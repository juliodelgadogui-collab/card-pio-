using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class GroupReceiptResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("receipt")] public GroupReceipt? Receipt { get; set; }
}

public sealed class GroupReceipt
{
    [JsonPropertyName("receipt_number")] public string ReceiptNumber { get; set; } = "";
    [JsonPropertyName("tenant_name")] public string TenantName { get; set; } = "";
    [JsonPropertyName("group_id")] public int GroupId { get; set; }
    [JsonPropertyName("tab_id")] public int? TabId { get; set; }
    [JsonPropertyName("table_name")] public string TableName { get; set; } = "";
    [JsonPropertyName("tab_label")] public string TabLabel { get; set; } = "";
    [JsonPropertyName("operator_name")] public string OperatorName { get; set; } = "";
    [JsonPropertyName("split_type")] public string SplitType { get; set; } = "";
    [JsonPropertyName("method")] public string Method { get; set; } = "";
    [JsonPropertyName("provider")] public string Provider { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("confirmed_cents")] public int ConfirmedCents { get; set; }
    [JsonPropertyName("provider_payment_id")] public string ProviderPaymentId { get; set; } = "";
    [JsonPropertyName("verified_at")] public string VerifiedAt { get; set; } = "";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    [JsonPropertyName("allocations")] public List<GroupReceiptAllocation> Allocations { get; set; } = new();
    [JsonPropertyName("items")] public List<GroupReceiptItem> Items { get; set; } = new();

    public string AmountDisplay => OperationalDisplay.Money(AmountCents);
    public string ConfirmedDisplay => OperationalDisplay.Money(ConfirmedCents);
    public string DateDisplay => ServerTimeDisplay.Local(string.IsNullOrWhiteSpace(VerifiedAt) ? CreatedAt : VerifiedAt);
    public string SplitDisplay => SplitType switch
    {
        "value" => "Por valor",
        "percentage" => "Por percentual",
        "person" => "Por pessoa",
        "product" => "Por produtos",
        _ => SplitType
    };
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
        "paid" => "Confirmado",
        "refunded" => "Estornado",
        "attention" => "Requer conferência",
        _ => Status
    };
}

public sealed class GroupReceiptAllocation
{
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("payment_id")] public int PaymentId { get; set; }
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("verified_at")] public string? VerifiedAt { get; set; }
    public string AmountDisplay => OperationalDisplay.Money(AmountCents);
    public string StatusDisplay => PaymentStatus switch
    {
        "paid" => "Pago",
        "partially_refunded" => "Estorno parcial",
        "refunded" => "Estornado",
        "attention" => "Atenção",
        _ => PaymentStatus
    };
}

public sealed class GroupReceiptItem
{
    [JsonPropertyName("order_item_id")] public int OrderItemId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    public string QuantityDisplay => Quantity.ToString("0.##");
    public string AmountDisplay => OperationalDisplay.Money(AmountCents);
}
