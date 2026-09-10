using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DeliveryUserOption
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("phone")] public string? Phone { get; set; }
    [JsonPropertyName("on_shift")] public int OnShift { get; set; }
    public string DisplayName => string.IsNullOrWhiteSpace(Phone) ? Name : $"{Name} • {Phone}";
    public override string ToString() => DisplayName;
}

public sealed class DeliveryUsersResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("delivery_users")] public List<DeliveryUserOption> DeliveryUsers { get; set; } = new();
}

public sealed class SimpleOperationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
}

public sealed class OperationPayloadResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("request")] public Dictionary<string, JsonElement>? Request { get; set; }
    [JsonPropertyName("result")] public Dictionary<string, JsonElement>? Result { get; set; }
}

public sealed class CancellationRequestsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("requests")] public List<CancellationRequestItem> Requests { get; set; } = new();
}

public sealed class CancellationRequestItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
    [JsonPropertyName("requester_name")] public string RequesterName { get; set; } = "";
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("order_status")] public string OrderStatus { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
    public string CustomerDisplay => string.IsNullOrWhiteSpace(CustomerName) ? "Cliente não informado" : CustomerName!;
}

public sealed class DiscountRequestsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("requests")] public List<DiscountRequestItem> Requests { get; set; } = new();
}

public sealed class DiscountRequestItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("requested_cents")] public int RequestedCents { get; set; }
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
    [JsonPropertyName("requester_name")] public string RequesterName { get; set; } = "";
    [JsonPropertyName("subtotal_cents")] public int SubtotalCents { get; set; }
    [JsonPropertyName("discount_cents")] public int DiscountCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("order_status")] public string OrderStatus { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string RequestedDisplay => OperationalDisplay.Money(RequestedCents);
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
}

public sealed class NotificationListResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("notifications")] public NotificationInbox Notifications { get; set; } = new();
}

public sealed class NotificationInbox
{
    [JsonPropertyName("items")] public List<NotificationItem> Items { get; set; } = new();
    [JsonPropertyName("unread_count")] public int UnreadCount { get; set; }
    [JsonPropertyName("mode")] public string? Mode { get; set; }
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
}

public sealed class NotificationItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
    [JsonPropertyName("mode")] public string? Mode { get; set; }
    [JsonPropertyName("type")] public string Type { get; set; } = "";
    [JsonPropertyName("priority")] public string Priority { get; set; } = "info";
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("message")] public string Message { get; set; } = "";
    [JsonPropertyName("entity_type")] public string? EntityType { get; set; }
    [JsonPropertyName("entity_id")] public string? EntityId { get; set; }
    [JsonPropertyName("read_at")] public string? ReadAt { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public bool IsUnread => string.IsNullOrWhiteSpace(ReadAt);
    public string StateDisplay => IsUnread ? "Nova" : "Lida";
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
    public string PriorityDisplay => Priority switch
    {
        "critical" => "Urgente",
        "warning" => "Atenção",
        "success" => "Concluído",
        _ => "Informação"
    };
}

public sealed class MarkAllNotificationsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("updated")] public int Updated { get; set; }
}

public sealed class ReceiptResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("receipt")] public OrderReceipt? Receipt { get; set; }
}

public sealed class OrderReceipt
{
    [JsonPropertyName("receipt_number")] public string ReceiptNumber { get; set; } = "";
    [JsonPropertyName("tenant_name")] public string TenantName { get; set; } = "";
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("customer_phone")] public string CustomerPhone { get; set; } = "";
    [JsonPropertyName("table_name")] public string TableName { get; set; } = "";
    [JsonPropertyName("tab_label")] public string TabLabel { get; set; } = "";
    [JsonPropertyName("created_by_name")] public string CreatedByName { get; set; } = "";
    [JsonPropertyName("subtotal_cents")] public int SubtotalCents { get; set; }
    [JsonPropertyName("discount_cents")] public int DiscountCents { get; set; }
    [JsonPropertyName("delivery_fee_cents")] public int DeliveryFeeCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("paid_cents")] public int PaidCents { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    [JsonPropertyName("items")] public List<ReceiptItem> Items { get; set; } = new();
    [JsonPropertyName("payments")] public List<ReceiptPayment> Payments { get; set; } = new();
    [JsonPropertyName("identity")] public ReceiptIdentity Identity { get; set; } = new();

    public int RemainingCents => Math.Max(0, TotalCents - PaidCents);
    public string SubtotalDisplay => OperationalDisplay.Money(SubtotalCents);
    public string DiscountDisplay => OperationalDisplay.Money(DiscountCents);
    public string DeliveryFeeDisplay => OperationalDisplay.Money(DeliveryFeeCents);
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string PaidDisplay => OperationalDisplay.Money(PaidCents);
    public string RemainingDisplay => OperationalDisplay.Money(RemainingCents);
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
    public string CustomerDisplay => string.IsNullOrWhiteSpace(CustomerName) ? "Cliente não informado" : CustomerName;
    public string LocationDisplay => !string.IsNullOrWhiteSpace(TableName) ? TableName : !string.IsNullOrWhiteSpace(TabLabel) ? TabLabel : "";
}

public sealed class ReceiptItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("quantity")] public int Quantity { get; set; }
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("notes")] public string? Notes { get; set; }
    public string UnitPriceDisplay => OperationalDisplay.Money(UnitPriceCents);
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
}

public sealed class ReceiptPayment
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("provider")] public string Provider { get; set; } = "";
    [JsonPropertyName("method")] public string Method { get; set; } = "";
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("verified_at")] public string? VerifiedAt { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string AmountDisplay => OperationalDisplay.Money(AmountCents);
    public string MethodDisplay => Method switch
    {
        "cash" => "Dinheiro",
        "pix" => "Pix",
        "credit" => "Crédito",
        "debit" => "Débito",
        "nfc" => "Aproximação",
        _ when !string.IsNullOrWhiteSpace(Method) => Method,
        _ => Provider
    };
    public string StatusDisplay => Status switch
    {
        "paid" => "Pago",
        "authorized" => "Autorizado",
        "pending" => "Pendente",
        "created" => "Criado",
        "partially_refunded" => "Estorno parcial",
        "refunded" => "Estornado",
        "failed" => "Falhou",
        "cancelled" => "Cancelado",
        _ => Status
    };
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
}

public sealed class ReceiptIdentity
{
    [JsonPropertyName("trade_name")] public string TradeName { get; set; } = "";
    [JsonPropertyName("legal_name")] public string LegalName { get; set; } = "";
    [JsonPropertyName("document")] public string Document { get; set; } = "";
    [JsonPropertyName("address")] public string Address { get; set; } = "";
    [JsonPropertyName("phone")] public string Phone { get; set; } = "";
    [JsonPropertyName("email")] public string Email { get; set; } = "";
    [JsonPropertyName("footer")] public string Footer { get; set; } = "";
    [JsonPropertyName("paper_width")] public string PaperWidth { get; set; } = "80";
}

public sealed class QrResolveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("type")] public string Type { get; set; } = "";
    [JsonPropertyName("data")] public Dictionary<string, JsonElement> Data { get; set; } = new();
}

public sealed class OrderQrResolveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public OperationalOrderDetail? Order { get; set; }
}

public sealed class QrActionResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("result")] public Dictionary<string, JsonElement>? Result { get; set; }
    [JsonPropertyName("guest")] public Dictionary<string, JsonElement>? Guest { get; set; }
}

public sealed class OperationalOrderDetailsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public OperationalOrderDetail? Order { get; set; }
    [JsonPropertyName("items")] public List<OrderItem> Items { get; set; } = new();
}

public sealed class OperationalOrderDetail
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("public_token")] public string PublicToken { get; set; } = "";
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("delivery_address")] public string? DeliveryAddress { get; set; }
    [JsonPropertyName("delivery_name")] public string? DeliveryName { get; set; }
    [JsonPropertyName("assigned_delivery_user_id")] public int? AssignedDeliveryUserId { get; set; }
    [JsonPropertyName("table_name")] public string? TableName { get; set; }
    [JsonPropertyName("table_id")] public int? TableId { get; set; }
    [JsonPropertyName("tab_id")] public int? TabId { get; set; }
    [JsonPropertyName("notes")] public string? Notes { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";

    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string ChannelDisplay => Channel switch
    {
        "counter" => "Balcão",
        "pickup" => "Retirada",
        "delivery" => "Delivery",
        "table" => "Mesa",
        "event_bar" => "Evento / Bar",
        _ => Channel
    };
    public string StatusDisplay => Status switch
    {
        "pending" => "Pendente",
        "confirmed" => "Confirmado",
        "preparing" => "Em preparo",
        "ready" => "Pronto",
        "out_for_delivery" => "Em rota",
        "served" => "Servido",
        "completed" => "Concluído",
        "cancelled" => "Cancelado",
        _ => Status
    };
    public string PaymentDisplay => PaymentStatus switch
    {
        "unpaid" => "Não pago",
        "pending" => "Pendente",
        "paid" => "Pago",
        "partially_paid" => "Parcial",
        "refunded" => "Estornado",
        "cancelled" => "Cancelado",
        _ => PaymentStatus
    };
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
}

internal static class OperationalDisplay
{
    public static string Money(int cents) => (cents / 100m).ToString("C2", new CultureInfo("pt-BR"));

    public static string LocalTime(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "";
        if (DateTimeOffset.TryParse(value, out var offset)) return offset.ToLocalTime().ToString("dd/MM/yyyy HH:mm");
        if (DateTime.TryParse(value, out var local)) return local.ToString("dd/MM/yyyy HH:mm");
        return value;
    }
}
