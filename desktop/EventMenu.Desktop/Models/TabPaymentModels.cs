using System.Globalization;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class TabAccountResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("account")] public TabAccount? Account { get; set; }
}

public sealed class TabAccount
{
    [JsonPropertyName("tab")] public TabAccountHeader Tab { get; set; } = new();
    [JsonPropertyName("orders")] public List<TabAccountOrder> Orders { get; set; } = new();
    [JsonPropertyName("items")] public List<TabAccountItem> Items { get; set; } = new();
    [JsonPropertyName("open_group")] public TabPaymentGroup? OpenGroup { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("paid_cents")] public int PaidCents { get; set; }
    [JsonPropertyName("remaining_cents")] public int RemainingCents { get; set; }
    public string TotalDisplay => TabPaymentDisplay.Money(TotalCents);
    public string PaidDisplay => TabPaymentDisplay.Money(PaidCents);
    public string RemainingDisplay => TabPaymentDisplay.Money(RemainingCents);
}

public sealed class TabAccountHeader
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("label")] public string? Label { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("table_name")] public string TableName { get; set; } = "";
    [JsonPropertyName("seats")] public int Seats { get; set; }
    [JsonPropertyName("opened_at")] public string OpenedAt { get; set; } = "";
    public string OpenedDisplay => ServerTimeDisplay.Local(OpenedAt);
}

public sealed class TabAccountOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("paid_cents")] public int PaidCents { get; set; }
    [JsonPropertyName("remaining_cents")] public int RemainingCents { get; set; }
    public string TotalDisplay => TabPaymentDisplay.Money(TotalCents);
    public string RemainingDisplay => TabPaymentDisplay.Money(RemainingCents);
}

public sealed class TabAccountItem
{
    [JsonPropertyName("order_item_id")] public int OrderItemId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("quantity")] public int Quantity { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("split_used")] public int SplitUsed { get; set; }
    public string TotalDisplay => TabPaymentDisplay.Money(TotalCents);
}

public sealed class TabPaymentGroupResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("group")] public TabPaymentGroup? Group { get; set; }
}

public sealed class TabPaymentGroup
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("tab_id")] public int TabId { get; set; }
    [JsonPropertyName("method")] public string Method { get; set; } = "";
    [JsonPropertyName("split_type")] public string SplitType { get; set; } = "";
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("tab_remaining_cents")] public int TabRemainingCents { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string AmountDisplay => TabPaymentDisplay.Money(AmountCents);
    public string RemainingDisplay => TabPaymentDisplay.Money(TabRemainingCents);
    public string MethodDisplay => Method switch { "cash" => "Dinheiro", "pix" => "Pix", _ => Method };
    public string StatusDisplay => Status switch
    {
        "created" => "Criada",
        "pending" => "Aguardando pagamento",
        "paid" => "Paga",
        "attention" => "Requer conferência",
        "failed" => "Falhou",
        "cancelled" => "Cancelada",
        "refunded" => "Estornada",
        _ => Status
    };
}

public sealed class TabPixCreateResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("pix")] public TabPixCharge? Pix { get; set; }
}

public sealed class TabPixCharge
{
    [JsonPropertyName("group_id")] public int GroupId { get; set; }
    [JsonPropertyName("tab_id")] public int TabId { get; set; }
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("copy_paste")] public string CopyPaste { get; set; } = "";
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("reused")] public bool Reused { get; set; }
    public string AmountDisplay => TabPaymentDisplay.Money(AmountCents);
    public string ExpiresDisplay => ServerTimeDisplay.Local(ExpiresAt);
}

public sealed class TabPixStatusResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("group")] public TabPaymentGroup? Group { get; set; }
    [JsonPropertyName("paid")] public bool Paid { get; set; }
}

internal static class TabPaymentDisplay
{
    public static string Money(int cents) => (cents / 100m).ToString("C2", CultureInfo.GetCultureInfo("pt-BR"));
}
