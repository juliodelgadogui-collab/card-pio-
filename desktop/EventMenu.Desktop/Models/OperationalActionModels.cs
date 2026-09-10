using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DeliveryUserOption
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("phone")] public string? Phone { get; set; }
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

public sealed class QrResolveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("type")] public string Type { get; set; } = "";
    [JsonPropertyName("data")] public Dictionary<string, JsonElement> Data { get; set; } = new();
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
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("delivery_address")] public string? DeliveryAddress { get; set; }
    [JsonPropertyName("delivery_name")] public string? DeliveryName { get; set; }
    [JsonPropertyName("assigned_delivery_user_id")] public int? AssignedDeliveryUserId { get; set; }
    [JsonPropertyName("table_id")] public int? TableId { get; set; }
    [JsonPropertyName("tab_id")] public int? TabId { get; set; }
    [JsonPropertyName("notes")] public string? Notes { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";

    public string TotalDisplay => (TotalCents / 100m).ToString("C2", new CultureInfo("pt-BR"));
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
    public string CreatedDisplay
    {
        get
        {
            if (DateTimeOffset.TryParse(CreatedAt, out var offset)) return offset.ToLocalTime().ToString("dd/MM/yyyy HH:mm");
            if (DateTime.TryParse(CreatedAt, out var local)) return local.ToString("dd/MM/yyyy HH:mm");
            return CreatedAt;
        }
    }
}
