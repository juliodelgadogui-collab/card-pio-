using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;
using System.Text.Json.Serialization;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class TenantBrandDesktopService : IDisposable
{
    private readonly HttpClient _http;
    private readonly SecureSessionStore _store;
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString,
    };

    public TenantBrandDesktopService(SecureSessionStore store)
    {
        _store = store;
        var baseUrl = (Environment.GetEnvironmentVariable("EVENTMENU_DESKTOP_API_BASE_URL") ?? "https://go.gestao2.store/1/").Trim();
        if (!baseUrl.EndsWith('/')) baseUrl += "/";
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps)
            throw new InvalidOperationException("O endereço do servidor EventMenu precisa usar HTTPS.");
        _http = new HttpClient { BaseAddress = uri, Timeout = TimeSpan.FromSeconds(12) };
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Add("X-Device-Id", DeviceIdentity.GetOrCreate());
    }

    public async Task<TenantBrand?> LoadAsync(CancellationToken ct = default)
    {
        var session = _store.Load();
        if (session is null || string.IsNullOrWhiteSpace(session.Token)) return null;
        using var request = new HttpRequestMessage(HttpMethod.Get, "api-go-brand.php");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.Token);
        try
        {
            using var response = await _http.SendAsync(request, HttpCompletionOption.ResponseContentRead, ct);
            if (!response.IsSuccessStatusCode) return null;
            var text = await response.Content.ReadAsStringAsync(ct);
            return JsonSerializer.Deserialize<TenantBrandResponse>(text, JsonOptions)?.Brand;
        }
        catch (HttpRequestException) { return null; }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested) { return null; }
        catch (JsonException) { return null; }
    }

    public void Dispose() => _http.Dispose();
}
