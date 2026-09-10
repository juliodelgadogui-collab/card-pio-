using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class OperationalActionsApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly SecureSessionStore _store;
    private readonly string _deviceId;
    private readonly SemaphoreSlim _refreshLock = new(1, 1);
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString
    };

    public OperationalActionsApiClient(SecureSessionStore store)
    {
        _store = store;
        _deviceId = DeviceIdentity.GetOrCreate();
        var baseUrl = (Environment.GetEnvironmentVariable("EVENTMENU_DESKTOP_API_BASE_URL") ?? "https://go.gestao2.store/1/").Trim();
        if (!baseUrl.EndsWith('/')) baseUrl += "/";
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps)
            throw new InvalidOperationException("A configuração de conexão do EventMenu está inválida.");

        _http = new HttpClient { BaseAddress = uri, Timeout = TimeSpan.FromSeconds(25) };
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Add("X-Device-Id", _deviceId);
    }

    public Task<OperationalOrderDetailsResponse> OrderAsync(int orderId, CancellationToken ct = default) =>
        GetAsync<OperationalOrderDetailsResponse>("api.php", "order", new() { ["id"] = orderId.ToString() }, ct);

    public async Task<DeliveryUsersResponse> DeliveryUsersAsync(CancellationToken ct = default)
    {
        try
        {
            return await GetAsync<DeliveryUsersResponse>("api-go.php", "delivery-users", null, ct);
        }
        catch (ApiClientException ex) when (IsEndpointUnavailable(ex))
        {
            return await GetAsync<DeliveryUsersResponse>("api.php", "delivery-users", null, ct);
        }
    }

    public async Task<SimpleOperationResponse> AssignDeliveryAsync(int orderId, int deliveryUserId, CancellationToken ct = default)
    {
        try
        {
            return await PostAsync<SimpleOperationResponse>("api-go.php", "delivery-assign", new { order_id = orderId, delivery_user_id = deliveryUserId }, ct);
        }
        catch (ApiClientException ex) when (IsEndpointUnavailable(ex))
        {
            return await PostAsync<SimpleOperationResponse>("api.php", "delivery-assign", new { order_id = orderId, delivery_user_id = deliveryUserId }, ct);
        }
    }

    public Task<QrResolveResponse> ResolveQrAsync(string value, CancellationToken ct = default) =>
        GetAsync<QrResolveResponse>("api.php", "qr-resolve", new() { ["value"] = value }, ct);

    public Task<OrderQrResolveResponse> ResolveOrderQrAsync(string value, CancellationToken ct = default) =>
        GetAsync<OrderQrResolveResponse>("api-go.php", "order-qr-resolve", new() { ["value"] = value }, ct);

    public Task<QrActionResponse> TicketCheckInAsync(string token, CancellationToken ct = default) =>
        PostAsync<QrActionResponse>("api.php", "ticket-checkin", new { token }, ct);

    public Task<QrActionResponse> GuestCheckInAsync(string code, CancellationToken ct = default) =>
        PostAsync<QrActionResponse>("api.php", "guest-checkin", new { code }, ct);

    public Task<TabResponse> OpenTableAsync(int tableId, string label, CancellationToken ct = default) =>
        PostAsync<TabResponse>("api-go.php", "table-open", new { table_id = tableId, label }, ct);

    private static bool IsEndpointUnavailable(ApiClientException ex) =>
        ex.StatusCode is HttpStatusCode.NotFound or HttpStatusCode.MethodNotAllowed;

    private async Task<T> GetAsync<T>(string path, string action, Dictionary<string, string>? query, CancellationToken ct)
    {
        var url = BuildUrl(path, action, query);
        return await SendWithRefreshAsync<T>(HttpMethod.Get, url, null, ct);
    }

    private async Task<T> PostAsync<T>(string path, string action, object body, CancellationToken ct)
    {
        var url = BuildUrl(path, action, null);
        return await SendWithRefreshAsync<T>(HttpMethod.Post, url, body, ct);
    }

    private async Task<T> SendWithRefreshAsync<T>(HttpMethod method, string url, object? body, CancellationToken ct)
    {
        var session = _store.Load() ?? throw new ApiClientException("Faça login novamente.", HttpStatusCode.Unauthorized);
        try
        {
            return await SendAsync<T>(method, url, body, session.Token, ct);
        }
        catch (ApiClientException ex) when (ex.StatusCode == HttpStatusCode.Unauthorized)
        {
            session = await RefreshAsync(session, ct);
            return await SendAsync<T>(method, url, body, session.Token, ct);
        }
    }

    private async Task<SessionEnvelope> RefreshAsync(SessionEnvelope current, CancellationToken ct)
    {
        await _refreshLock.WaitAsync(ct);
        try
        {
            var latest = _store.Load();
            if (latest is not null && latest.Token != current.Token && !string.IsNullOrWhiteSpace(latest.Token)) return latest;

            var refreshed = await SendAsync<LoginResponse>(
                HttpMethod.Post,
                "api.php?action=refresh",
                new { refresh_token = current.RefreshToken, device_id = _deviceId },
                null,
                ct);

            if (string.IsNullOrWhiteSpace(refreshed.Token) || string.IsNullOrWhiteSpace(refreshed.RefreshToken))
                throw new ApiClientException("Faça login novamente.", HttpStatusCode.Unauthorized);

            var session = new SessionEnvelope
            {
                Token = refreshed.Token,
                RefreshToken = refreshed.RefreshToken,
                ExpiresAt = refreshed.ExpiresAt,
                RefreshExpiresAt = refreshed.RefreshExpiresAt,
                User = refreshed.User ?? current.User
            };
            _store.Save(session);
            return session;
        }
        finally
        {
            _refreshLock.Release();
        }
    }

    private async Task<T> SendAsync<T>(HttpMethod method, string url, object? body, string? token, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(method, url);
        if (!string.IsNullOrWhiteSpace(token)) request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        if (body is not null) request.Content = new StringContent(JsonSerializer.Serialize(body, JsonOptions), Encoding.UTF8, "application/json");

        HttpResponseMessage response;
        try
        {
            response = await _http.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct);
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new ApiClientException("A conexão demorou para responder. Tente novamente.");
        }
        catch (HttpRequestException)
        {
            throw new ApiClientException("Sem conexão. Confira a internet e tente novamente.");
        }

        using (response)
        {
            var text = await response.Content.ReadAsStringAsync(ct);
            if (!response.IsSuccessStatusCode) throw new ApiClientException(ReadError(text), response.StatusCode);
            var result = JsonSerializer.Deserialize<T>(text, JsonOptions);
            return result ?? throw new ApiClientException("Não foi possível carregar todos os dados desta tela.");
        }
    }

    private static string BuildUrl(string path, string action, Dictionary<string, string>? query)
    {
        var sb = new StringBuilder(path).Append("?action=").Append(Uri.EscapeDataString(action));
        if (query is not null)
        {
            foreach (var pair in query)
                sb.Append('&').Append(Uri.EscapeDataString(pair.Key)).Append('=').Append(Uri.EscapeDataString(pair.Value));
        }
        return sb.ToString();
    }

    private static string ReadError(string text)
    {
        try
        {
            return JsonSerializer.Deserialize<ApiError>(text, JsonOptions)?.Error ?? "Não foi possível concluir a operação.";
        }
        catch
        {
            return "Não foi possível concluir a operação.";
        }
    }

    public void Dispose()
    {
        _http.Dispose();
        _refreshLock.Dispose();
    }
}
