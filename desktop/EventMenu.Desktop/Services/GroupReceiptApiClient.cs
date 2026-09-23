using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class GroupReceiptApiClient : IDisposable
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

    public GroupReceiptApiClient(SecureSessionStore store)
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

    public Task<GroupReceiptResponse> GetAsync(int groupId, CancellationToken ct = default) =>
        SendWithRefreshAsync<GroupReceiptResponse>(
            HttpMethod.Get,
            $"api-go-receipts.php?action=group&group_id={groupId}",
            null,
            ct);

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
        if (body is not null)
            request.Content = new StringContent(JsonSerializer.Serialize(body, JsonOptions), Encoding.UTF8, "application/json");

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
            return JsonSerializer.Deserialize<T>(text, JsonOptions)
                ?? throw new ApiClientException("Não foi possível carregar o comprovante.");
        }
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
