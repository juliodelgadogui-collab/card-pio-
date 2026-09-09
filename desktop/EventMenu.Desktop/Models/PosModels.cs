using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class ChannelOption
{
    public string Value { get; init; } = "counter";
    public string Label { get; init; } = "Balcão";
    public override string ToString() => Label;
}

public sealed class ShiftModeOption
{
    public string Value { get; init; } = "operation";
    public string Label { get; init; } = "Operação";
    public override string ToString() => Label;
}

public sealed class OperatingUnit
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("code")] public string Code { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("address")] public string? Address { get; set; }
    [JsonPropertyName("is_default")] public int IsDefault { get; set; }
    public override string ToString() => Name;
}

public sealed class UnitsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("units")] public List<OperatingUnit> Units { get; set; } = new();
}

public sealed class GoContextResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("permissions")] public Dictionary<string, bool> Permissions { get; set; } = new();
    [JsonPropertyName("permission_names")] public List<string> PermissionNames { get; set; } = new();
    [JsonPropertyName("modes")] public List<string> Modes { get; set; } = new();
    [JsonPropertyName("shift")] public Dictionary<string, JsonElement>? Shift { get; set; }
}

public sealed class ShiftResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("shift")] public Dictionary<string, JsonElement>? Shift { get; set; }
}

public sealed class TableInfo
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("seats")] public int Seats { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("tab_id")] public int? TabId { get; set; }
    [JsonPropertyName("tab_label")] public string? TabLabel { get; set; }
    [JsonPropertyName("tab_total_cents")] public int TabTotalCents { get; set; }
    [JsonPropertyName("unpaid_cents")] public int UnpaidCents { get; set; }
    public string StatusDisplay => Status switch { "available" => "Livre", "occupied" => "Ocupada", "inactive" => "Inativa", _ => Status };
    public string TotalDisplay => Money(TabTotalCents);
    public string UnpaidDisplay => Money(UnpaidCents);
    public override string ToString() => TabId is > 0 ? $"{Name} • comanda aberta" : Name;
    private static string Money(int cents) => (cents / 100m).ToString("C2", new System.Globalization.CultureInfo("pt-BR"));
}

public sealed class TablesResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("tables")] public List<TableInfo> Tables { get; set; } = new();
}

public sealed class TabResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("tab")] public Dictionary<string, JsonElement>? Tab { get; set; }
}

public sealed class CartLine : INotifyPropertyChanged
{
    public int ProductId { get; init; }
    public string Name { get; init; } = "";
    public int UnitPriceCents { get; init; }
    private decimal _quantity = 1;
    public decimal Quantity { get => _quantity; set { _quantity = Math.Max(0.01m, value); OnPropertyChanged(); OnPropertyChanged(nameof(TotalCents)); OnPropertyChanged(nameof(TotalDisplay)); } }
    public int TotalCents => (int)Math.Round(UnitPriceCents * Quantity, MidpointRounding.AwayFromZero);
    public string UnitPriceDisplay => Money(UnitPriceCents);
    public string TotalDisplay => Money(TotalCents);
    private static string Money(int cents) => (cents / 100m).ToString("C2", new System.Globalization.CultureInfo("pt-BR"));
    public event PropertyChangedEventHandler? PropertyChanged;
    private void OnPropertyChanged([CallerMemberName] string? name = null) => PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(name));
}

public sealed class OrderCreateItem
{
    [JsonPropertyName("product_id")] public int ProductId { get; set; }
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
}

public sealed class OrderCreateRequest
{
    [JsonPropertyName("channel")] public string Channel { get; set; } = "counter";
    [JsonPropertyName("table_id")] public int? TableId { get; set; }
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("customer_phone")] public string CustomerPhone { get; set; } = "";
    [JsonPropertyName("delivery_address")] public string DeliveryAddress { get; set; } = "";
    [JsonPropertyName("notes")] public string Notes { get; set; } = "";
    [JsonPropertyName("items")] public List<OrderCreateItem> Items { get; set; } = new();
}

public sealed class CreatedOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("public_token")] public string PublicToken { get; set; } = "";
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("table_id")] public int? TableId { get; set; }
    [JsonPropertyName("tab_id")] public int? TabId { get; set; }
}

public sealed class OrderCreateResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public CreatedOrder? Order { get; set; }
}

public sealed class OrderItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("product_id")] public int? ProductId { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("notes")] public string? Notes { get; set; }
    public string TotalDisplay => (TotalCents / 100m).ToString("C2", new System.Globalization.CultureInfo("pt-BR"));
}

public sealed class OrderDetailsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public Order? Order { get; set; }
    [JsonPropertyName("items")] public List<OrderItem> Items { get; set; } = new();
}

public sealed class CashSummaryResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("summary")] public Dictionary<string, JsonElement>? Summary { get; set; }
}

public sealed class CashMutationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("session")] public Dictionary<string, JsonElement>? Session { get; set; }
    [JsonPropertyName("movement_id")] public int MovementId { get; set; }
}
