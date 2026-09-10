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

    public Task<OperationPayloadResponse> RequestCancellationAsync(int orderId, string reason, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-cancellations.php", "request", new { order_id = orderId, reason }, ct);

    public Task<CancellationRequestsResponse> PendingCancellationsAsync(CancellationToken ct = default) =>
        GetAsync<CancellationRequestsResponse>("api-go-cancellations.php", "pending", null, ct);

    public Task<OperationPayloadResponse> ApproveCancellationAsync(int requestId, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-cancellations.php", "approve", new { request_id = requestId }, ct);

    public Task<OperationPayloadResponse> RejectCancellationAsync(int requestId, string reason, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-cancellations.php", "reject", new { request_id = requestId, reason }, ct);

    public Task<OperationPayloadResponse> RequestDiscountAsync(int orderId, int amountCents, string reason, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-discounts.php", "request", new { order_id = orderId, amount_cents = amountCents, reason }, ct);

    public Task<DiscountRequestsResponse> PendingDiscountsAsync(CancellationToken ct = default) =>
        GetAsync<DiscountRequestsResponse>("api-go-discounts.php", "pending", null, ct);

    public Task<OperationPayloadResponse> ApproveDiscountAsync(int requestId, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-discounts.php", "approve", new { request_id = requestId }, ct);

    public Task<OperationPayloadResponse> RejectDiscountAsync(int requestId, string reason, CancellationToken ct = default) =>
        PostAsync<OperationPayloadResponse>("api-go-discounts.php", "reject", new { request_id = requestId, reason }, ct);

    public Task<NotificationListResponse> NotificationsAsync(int limit = 100, CancellationToken ct = default) =>
        GetAsync<NotificationListResponse>("api-go-notifications.php", "list", new() { ["limit"] = Math.Clamp(limit, 1, 200).ToString() }, ct);

    public Task<SimpleOperationResponse> MarkNotificationReadAsync(int notificationId, CancellationToken ct = default) =>
        PostAsync<SimpleOperationResponse>("api-go-notifications.php", "read", new { notification_id = notificationId }, ct);

    public Task<MarkAllNotificationsResponse> MarkAllNotificationsReadAsync(CancellationToken ct = default) =>
        PostAsync<MarkAllNotificationsResponse>("api-go-notifications.php", "read-all", new { }, ct);

    public Task<ReceiptResponse> OrderReceiptAsync(int orderId, CancellationToken ct = default) =>
        GetAsync<ReceiptResponse>("api-go-receipts.php", "order", new() { ["order_id"] = orderId.ToString() }, ct);

    // Paridade com EventMenu GO: eventos e bar.
    public Task<EventOverviewResponse> EventsOverviewAsync(CancellationToken ct = default) =>
        GetAsync<EventOverviewResponse>("api-go-events.php", "overview", null, ct);

    public Task<EventRecentResponse> EventRecentAsync(int eventId, CancellationToken ct = default) =>
        GetAsync<EventRecentResponse>("api-go-events.php", "recent", new() { ["event_id"] = eventId.ToString() }, ct);

    public Task<EventPickupResponse> ResolveEventBarOrderAsync(int eventId, string value, CancellationToken ct = default) =>
        GetAsync<EventPickupResponse>("api-go-events.php", "bar-order-resolve", new() { ["event_id"] = eventId.ToString(), ["value"] = value.Trim() }, ct);

    public Task<EventPickupResponse> DeliverEventBarOrderAsync(int eventId, string value, CancellationToken ct = default) =>
        PostAsync<EventPickupResponse>("api-go-events.php", "bar-order-deliver", new { event_id = eventId, value = value.Trim() }, ct);

    public Task<QrActionResponse> EventTicketCheckInAsync(string token, CancellationToken ct = default) =>
        PostAsync<QrActionResponse>("api-go-events.php", "ticket-checkin", new { token = token.Trim() }, ct);

    public Task<QrActionResponse> EventGuestCheckInAsync(string code, CancellationToken ct = default) =>
        PostAsync<QrActionResponse>("api-go-events.php", "guest-checkin", new { code = code.Trim() }, ct);

    public Task<ProductsResponse> EventCatalogAsync(CancellationToken ct = default) =>
        GetAsync<ProductsResponse>("api-go.php", "catalog", null, ct);

    public Task<OrderCreateResponse> CreateEventBarOrderAsync(int eventId, IEnumerable<OrderCreateItem> items, string notes, CancellationToken ct = default) =>
        PostAsync<OrderCreateResponse>("api.php", "order-create", new { channel = "bar", event_id = eventId, notes = notes.Trim(), items = items.ToList() }, ct);

    // Paridade com o painel gerencial do Android.
    public Task<ManagerOverviewResponse> ManagerOverviewAsync(CancellationToken ct = default) =>
        GetAsync<ManagerOverviewResponse>("api-go-manager.php", "overview", null, ct);

    public Task<ManagerDetailsResponse> ManagerDetailsAsync(CancellationToken ct = default) =>
        GetAsync<ManagerDetailsResponse>("api-go-manager.php", "details", null, ct);

    public Task<ManagerReopenCandidatesResponse> ManagerReopenCandidatesAsync(CancellationToken ct = default) =>
        GetAsync<ManagerReopenCandidatesResponse>("api-go-manager.php", "reopen-candidates", null, ct);

    public Task<ManagerMutationResponse> ManagerTransferDeliveryAsync(int orderId, int deliveryUserId, CancellationToken ct = default) =>
        PostAsync<ManagerMutationResponse>("api-go-manager.php", "transfer-delivery", new { order_id = orderId, delivery_user_id = deliveryUserId }, ct);

    public Task<ManagerMutationResponse> ManagerReopenOrderAsync(int orderId, string reason, CancellationToken ct = default) =>
        PostAsync<ManagerMutationResponse>("api-go-manager.php", "reopen-order", new { order_id = orderId, reason = reason.Trim() }, ct);

    // Paridade com detalhes operacionais e fidelidade do Android.
    public Task<OrderOpsDetailResponse> OrderOpsDetailAsync(int orderId, CancellationToken ct = default) =>
        GetAsync<OrderOpsDetailResponse>("api-go-orders.php", "detail", new() { ["order_id"] = orderId.ToString() }, ct);

    public Task<SimpleOperationResponse> AcceptOrderAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<SimpleOperationResponse>("api-go-orders.php", "accept", new { order_id = orderId }, ct);

    public Task<OrderOpsDetailResponse> ApplyLoyaltyAsync(int orderId, int points, CancellationToken ct = default) =>
        PostAsync<OrderOpsDetailResponse>("api-go-orders.php", "loyalty-apply", new { order_id = orderId, points }, ct);

    public Task<OrderOpsDetailResponse> RemoveLoyaltyAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<OrderOpsDetailResponse>("api-go-orders.php", "loyalty-remove", new { order_id = orderId }, ct);

    // O Desktop oferece o mesmo fluxo de progresso para um usuário entregador autenticado.
    // O envio contínuo de GPS continua sendo responsabilidade do app Android.
    public Task<DeliveryMineResponse> DeliveryMineAsync(CancellationToken ct = default) =>
        GetAsync<DeliveryMineResponse>("api-go-delivery.php", "list", null, ct);

    public Task<DeliveryMutationResponse> DeliveryPickupAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<DeliveryMutationResponse>("api-go-delivery.php", "pickup", new { order_id = orderId }, ct);

    public Task<DeliveryMutationResponse> DeliveryStartRouteAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<DeliveryMutationResponse>("api-go-delivery.php", "start-route", new { order_id = orderId }, ct);

    public Task<DeliveryMutationResponse> DeliveryArriveAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<DeliveryMutationResponse>("api-go-delivery.php", "arrive", new { order_id = orderId }, ct);

    public Task<DeliveryMutationResponse> DeliveryCompleteAsync(int orderId, CancellationToken ct = default) =>
        PostAsync<DeliveryMutationResponse>("api-go-delivery.php", "complete", new { order_id = orderId }, ct);

    private static bool IsEndpointUnavailable(ApiClientException ex) =>
        ex.StatusCode is HttpStatusCode.NotFound or HttpStatusCode.MethodNotAllowed;

    private static bool ShouldRefreshSession(ApiClientException ex)
    {
        if (ex.StatusCode == HttpStatusCode.Unauthorized) return true;
        if (ex.StatusCode != HttpStatusCode.UnprocessableEntity) return false;
        var message = ex.Message.ToLowerInvariant();
        return message.Contains("sessão") || message.Contains("token inválido") || message.Contains("token expirado") || message.Contains("expirada");
    }

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
        catch (ApiClientException ex) when (ShouldRefreshSession(ex))
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
