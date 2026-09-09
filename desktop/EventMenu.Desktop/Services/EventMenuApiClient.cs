using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class EventMenuApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly SecureSessionStore _store;
    private readonly string _deviceId;
    private SessionEnvelope? _session;
    private readonly SemaphoreSlim _refreshLock = new(1, 1);

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString
    };

    public EventMenuApiClient(SecureSessionStore store)
    {
        _store = store;
        _deviceId = DeviceIdentity.GetOrCreate();
        _session = store.Load();

        var baseUrl = (Environment.GetEnvironmentVariable("EVENTMENU_DESKTOP_API_BASE_URL")
                       ?? "https://go.gestao2.store/1/").Trim();
        if (!baseUrl.EndsWith('/')) baseUrl += "/";
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps)
            throw new InvalidOperationException("O endereço do servidor EventMenu precisa usar HTTPS.");

        _http = new HttpClient
        {
            BaseAddress = uri,
            Timeout = TimeSpan.FromSeconds(25)
        };
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Add("X-Device-Id", _deviceId);
    }

    public bool HasSavedSession => _session is { Token.Length: > 0, RefreshToken.Length: > 0 };

    public async Task<LoginResponse> LoginAsync(string email, string password, CancellationToken ct = default)
    {
        var body = new
        {
            email = email.Trim(),
            password,
            device_id = _deviceId,
            device_label = $"EventMenu Desktop - {Environment.MachineName}"
        };

        var response = await SendCoreAsync(HttpMethod.Post, "api.php", "login", null, body, null, ct);
        var login = Deserialize<LoginResponse>(response);
        if (string.IsNullOrWhiteSpace(login.Token) || string.IsNullOrWhiteSpace(login.RefreshToken))
            throw new ApiClientException("O servidor não retornou uma sessão válida.");

        _session = new SessionEnvelope
        {
            Token = login.Token,
            RefreshToken = login.RefreshToken,
            ExpiresAt = login.ExpiresAt,
            RefreshExpiresAt = login.RefreshExpiresAt,
            User = login.User
        };
        _store.Save(_session);
        return login;
    }

    public Task<MeResponse> MeAsync(CancellationToken ct = default) => GetAsync<MeResponse>("api.php", "me", null, ct);
    public Task<OrdersResponse> OrdersAsync(CancellationToken ct = default) => GetAsync<OrdersResponse>("api.php", "orders", null, ct);
    public Task<OrdersResponse> OperationalOrdersAsync(CancellationToken ct = default) => GetAsync<OrdersResponse>("api-go.php", "orders", null, ct);
    public Task<ProductsResponse> ProductsAsync(CancellationToken ct = default) => GetAsync<ProductsResponse>("api.php", "products", null, ct);
    public Task<CashResponse> CashCurrentAsync(CancellationToken ct = default) => GetAsync<CashResponse>("api.php", "cash-current", null, ct);
    public Task<CashSummaryResponse> CashSummaryAsync(CancellationToken ct = default) => GetAsync<CashSummaryResponse>("api.php", "cash-summary", null, ct);
    public Task<OrderDetailsResponse> OrderDetailsAsync(int orderId, CancellationToken ct = default) =>
        GetAsync<OrderDetailsResponse>("api.php", "order", new Dictionary<string, string> { ["id"] = orderId.ToString() }, ct);

    public Task<OrderCreateResponse> CreateOrderAsync(OrderCreateRequest request, CancellationToken ct = default) =>
        PostAsync<OrderCreateResponse>("api.php", "order-create", request, ct);

    public Task<OrderCreateResponse> ChangeOrderStatusAsync(int orderId, string status, CancellationToken ct = default) =>
        PostAsync<OrderCreateResponse>("api.php", "order-status", new { order_id = orderId, status }, ct);

    public Task<OrderCreateResponse> ChangeOperationalOrderStatusAsync(int orderId, string status, CancellationToken ct = default) =>
        PostAsync<OrderCreateResponse>("api-go.php", "order-status", new { order_id = orderId, status }, ct);

    public Task<CashMutationResponse> CashOpenAsync(int openingCashCents, string notes, CancellationToken ct = default) =>
        PostAsync<CashMutationResponse>("api.php", "cash-open", new { opening_cash_cents = openingCashCents, notes }, ct);

    public Task<CashMutationResponse> CashMovementAsync(string type, int amountCents, string notes, string direction = "in", CancellationToken ct = default) =>
        PostAsync<CashMutationResponse>("api.php", "cash-movement", new { type, amount_cents = amountCents, notes, direction }, ct);

    public Task<CashMutationResponse> CashCloseAsync(int countedCashCents, string notes, CancellationToken ct = default) =>
        PostAsync<CashMutationResponse>("api.php", "cash-close", new { counted_cash_cents = countedCashCents, notes }, ct);

    public Task<DesktopContextResponse> GoContextAsync(CancellationToken ct = default) => GetAsync<DesktopContextResponse>("api-go.php", "context", null, ct);
    public Task<UnitsResponse> UnitsAsync(CancellationToken ct = default) => GetAsync<UnitsResponse>("api-go-units.php", "list", null, ct);
    public Task<ShiftResponse> ShiftOpenAsync(string mode, int? unitId, CancellationToken ct = default) =>
        PostAsync<ShiftResponse>("api-go-units.php", "shift-open", new { mode, unit_id = unitId }, ct);
    public Task<ShiftResponse> ShiftCloseAsync(string notes = "", CancellationToken ct = default) =>
        PostAsync<ShiftResponse>("api-go.php", "shift-close", new { notes }, ct);
    public Task<TablesResponse> TablesAsync(CancellationToken ct = default) => GetAsync<TablesResponse>("api-go.php", "tables-list", null, ct);
    public Task<TabResponse> TableOpenAsync(int tableId, string label = "", CancellationToken ct = default) =>
        PostAsync<TabResponse>("api-go.php", "table-open", new { table_id = tableId, label }, ct);
    public Task<TabResponse> TableCloseAsync(int tabId, CancellationToken ct = default) =>
        PostAsync<TabResponse>("api-go.php", "table-close", new { tab_id = tabId }, ct);

    public Task<PaymentStatusResponse> PaymentStatusAsync(int orderId, CancellationToken ct = default) =>
        GetAsync<PaymentStatusResponse>("api-go.php", "payment-status", new Dictionary<string, string> { ["order_id"] = orderId.ToString() }, ct);

    public Task<PaymentStatusResponse> PaymentCashAsync(int orderId, int amountCents, string idempotencyKey, CancellationToken ct = default) =>
        PostAsync<PaymentStatusResponse>("api-go.php", "payment-cash", new { order_id = orderId, amount_cents = amountCents, idempotency_key = idempotencyKey }, ct);

    public async Task LogoutAsync(CancellationToken ct = default)
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(_session?.Token))
                await SendCoreAsync(HttpMethod.Post, "api.php", "logout", null, new { }, _session.Token, ct);
        }
        finally
        {
            _session = null;
            _store.Clear();
        }
    }

    private async Task<T> GetAsync<T>(string path, string action, Dictionary<string, string>? query, CancellationToken ct)
    {
        EnsureSession();
        var json = await SendWithRefreshAsync(HttpMethod.Get, path, action, query, null, ct);
        return Deserialize<T>(json);
    }

    private async Task<T> PostAsync<T>(string path, string action, object body, CancellationToken ct)
    {
        EnsureSession();
        var json = await SendWithRefreshAsync(HttpMethod.Post, path, action, null, body, ct);
        return Deserialize<T>(json);
    }

    private async Task<string> SendWithRefreshAsync(HttpMethod method, string path, string action, Dictionary<string, string>? query, object? body, CancellationToken ct)
    {
        EnsureSession();
        try
        {
            return await SendCoreAsync(method, path, action, query, body, _session!.Token, ct);
        }
        catch (ApiClientException ex) when (
            ex.StatusCode == HttpStatusCode.Unauthorized ||
            (ex.StatusCode == HttpStatusCode.UnprocessableEntity && IsSessionError(ex.Message)))
        {
            await RefreshAsync(ct);
            return await SendCoreAsync(method, path, action, query, body, _session!.Token, ct);
        }
    }

    private static bool IsSessionError(string message) =>
        message.Contains("sessão", StringComparison.OrdinalIgnoreCase) ||
        message.Contains("token", StringComparison.OrdinalIgnoreCase) ||
        message.Contains("bearer", StringComparison.OrdinalIgnoreCase);

    private async Task RefreshAsync(CancellationToken ct)
    {
        await _refreshLock.WaitAsync(ct);
        try
        {
            EnsureSession();
            var current = _store.Load();
            if (current is not null && !string.IsNullOrWhiteSpace(current.Token) && current.Token != _session!.Token)
            {
                _session = current;
                return;
            }

            var json = await SendCoreAsync(
                HttpMethod.Post,
                "api.php",
                "refresh",
                null,
                new { refresh_token = _session!.RefreshToken, device_id = _deviceId },
                null,
                ct);
            var refreshed = Deserialize<LoginResponse>(json);
            if (string.IsNullOrWhiteSpace(refreshed.Token) || string.IsNullOrWhiteSpace(refreshed.RefreshToken))
                throw new ApiClientException("Faça login novamente.", HttpStatusCode.Unauthorized);
            _session.Token = refreshed.Token;
            _session.RefreshToken = refreshed.RefreshToken;
            _session.ExpiresAt = refreshed.ExpiresAt;
            _session.RefreshExpiresAt = refreshed.RefreshExpiresAt;
            _session.User = refreshed.User ?? _session.User;
            _store.Save(_session);
        }
        catch
        {
            _session = null;
            _store.Clear();
            throw;
        }
        finally
        {
            _refreshLock.Release();
        }
    }

    private async Task<string> SendCoreAsync(
        HttpMethod method,
        string path,
        string action,
        Dictionary<string, string>? query,
        object? body,
        string? token,
        CancellationToken ct)
    {
        var url = new StringBuilder(path)
            .Append("?action=")
            .Append(Uri.EscapeDataString(action));
        if (query is not null)
        {
            foreach (var pair in query)
                url.Append('&').Append(Uri.EscapeDataString(pair.Key)).Append('=').Append(Uri.EscapeDataString(pair.Value));
        }

        using var request = new HttpRequestMessage(method, url.ToString());
        if (!string.IsNullOrWhiteSpace(token))
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        if (body is not null)
        {
            var json = JsonSerializer.Serialize(body, JsonOptions);
            request.Content = new StringContent(json, Encoding.UTF8, "application/json");
        }

        HttpResponseMessage response;
        try
        {
            response = await _http.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct);
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new ApiClientException("O servidor demorou para responder. Tente novamente.");
        }
        catch (HttpRequestException)
        {
            throw new ApiClientException("Sem conexão com o servidor. Confira a internet e tente novamente.");
        }

        using (response)
        {
            var text = await response.Content.ReadAsStringAsync(ct);
            if (!response.IsSuccessStatusCode)
                throw new ApiClientException(ReadServerError(text), response.StatusCode);

            try
            {
                using var doc = JsonDocument.Parse(text);
                if (doc.RootElement.TryGetProperty("ok", out var ok) && ok.ValueKind == JsonValueKind.False)
                    throw new ApiClientException(ReadServerError(text), response.StatusCode);
            }
            catch (JsonException)
            {
                throw new ApiClientException("O servidor retornou uma resposta inválida.", response.StatusCode);
            }
            return text;
        }
    }

    private static string ReadServerError(string text)
    {
        try
        {
            var error = JsonSerializer.Deserialize<ApiError>(text, JsonOptions)?.Error;
            if (!string.IsNullOrWhiteSpace(error)) return Friendly(error);
        }
        catch (JsonException) { }
        return "Não foi possível concluir a operação no servidor.";
    }

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        if (lower.Contains("sqlstate") || lower.Contains("pdoexception") || lower.Contains("stack trace") || lower.Contains("constraint failed"))
            return "Não foi possível concluir a operação no servidor. Tente novamente.";
        if (lower.Contains("database is locked") || lower.Contains("database table is locked"))
            return "O servidor está ocupado por alguns segundos. Tente novamente.";
        return message;
    }

    private static T Deserialize<T>(string json) =>
        JsonSerializer.Deserialize<T>(json, JsonOptions)
        ?? throw new ApiClientException("O servidor retornou dados incompletos.");

    private void EnsureSession()
    {
        if (_session is null || string.IsNullOrWhiteSpace(_session.Token))
            throw new ApiClientException("Faça login novamente.", HttpStatusCode.Unauthorized);
    }

    public void Dispose()
    {
        _http.Dispose();
        _refreshLock.Dispose();
    }
}

public sealed class ApiClientException : Exception
{
    public HttpStatusCode? StatusCode { get; }

    public ApiClientException(string message, HttpStatusCode? statusCode = null) : base(message)
    {
        StatusCode = statusCode;
    }
}
