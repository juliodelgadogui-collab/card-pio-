using System.Globalization;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class InventoryUnit
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
}

public sealed class InventorySummary
{
    [JsonPropertyName("controlled")] public int Controlled { get; set; }
    [JsonPropertyName("low")] public int Low { get; set; }
    [JsonPropertyName("zero")] public int Zero { get; set; }
    [JsonPropertyName("reserved_qty")] public decimal ReservedQty { get; set; }
}

public sealed class InventoryProductRow
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("sku")] public string? Sku { get; set; }
    [JsonPropertyName("stock_unit")] public string StockUnit { get; set; } = "un";
    [JsonPropertyName("available_qty")] public decimal AvailableQty { get; set; }
    [JsonPropertyName("reserved_qty")] public decimal ReservedQty { get; set; }
    [JsonPropertyName("physical_qty")] public decimal PhysicalQty { get; set; }
    [JsonPropertyName("min_stock_qty")] public decimal MinStockQty { get; set; }
    [JsonPropertyName("stock_status")] public string StockStatus { get; set; } = "ok";
    public string AvailableDisplay => $"{AvailableQty.ToString("0.###", CultureInfo.CurrentCulture)} {StockUnit}";
    public string ReservedDisplay => $"{ReservedQty.ToString("0.###", CultureInfo.CurrentCulture)} {StockUnit}";
    public string PhysicalDisplay => $"{PhysicalQty.ToString("0.###", CultureInfo.CurrentCulture)} {StockUnit}";
    public string MinimumDisplay => $"{MinStockQty.ToString("0.###", CultureInfo.CurrentCulture)} {StockUnit}";
    public string StatusLabel => StockStatus switch { "zero" => "ZERADO", "low" => "BAIXO", _ => "OK" };
}

public sealed class InventorySnapshotResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("unit")] public InventoryUnit Unit { get; set; } = new();
    [JsonPropertyName("summary")] public InventorySummary Summary { get; set; } = new();
    [JsonPropertyName("products")] public List<InventoryProductRow> Products { get; set; } = new();
}
