using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
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
        PropertyNameCaseInsensitive = true
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

        var response = await SendCoreAsync(HttpMethod.Post, "login", body, null, false, ct);
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

    public Task<MeResponse> MeAsync(CancellationToken ct = default) => GetAsync<MeResponse>("me", ct);
    public Task<OrdersResponse> OrdersAsync(CancellationToken ct = default) => GetAsync<OrdersResponse>("orders", ct);
    public Task<ProductsResponse> ProductsAsync(CancellationToken ct = default) => GetAsync<ProductsResponse>("products", ct);
    public Task<CashResponse> CashCurrentAsync(CancellationToken ct = default) => GetAsync<CashResponse>("cash-current", ct);

    public async Task LogoutAsync(CancellationToken ct = default)
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(_session?.Token))
                await SendCoreAsync(HttpMethod.Post, "logout", new { }, _session.Token, false, ct);
        }
        finally
        {
            _session = null;
            _store.Clear();
        }
    }

    private async Task<T> GetAsync<T>(string action, CancellationToken ct)
    {
        EnsureSession();
        var json = await SendWithRefreshAsync(HttpMethod.Get, action, null, ct);
        return Deserialize<T>(json);
    }

    private async Task<string> SendWithRefreshAsync(HttpMethod method, string action, object? body, CancellationToken ct)
    {
        EnsureSession();
        try
        {
            return await SendCoreAsync(method, action, body, _session!.Token, false, ct);
        }
        catch (ApiClientException ex) when (ex.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.UnprocessableEntity)
        {
            await RefreshAsync(ct);
            return await SendCoreAsync(method, action, body, _session!.Token, false, ct);
        }
    }

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
                "refresh",
                new { refresh_token = _session!.RefreshToken, device_id = _deviceId },
                null,
                false,
                ct);
            var refreshed = Deserialize<LoginResponse>(json);
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
        string action,
        object? body,
        string? token,
        bool _unused,
        CancellationToken ct)
    {
        using var request = new HttpRequestMessage(method, $"api.php?action={Uri.EscapeDataString(action)}");
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
