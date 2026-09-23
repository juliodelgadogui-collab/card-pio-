using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class ShiftSummaryResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("summary")] public ShiftSummaryData? Summary { get; set; }
}

public sealed class ShiftSummaryData
{
    [JsonPropertyName("shift")] public ShiftSummaryShift? Shift { get; set; }
    [JsonPropertyName("by_method")] public List<ShiftMethodSummary> ByMethod { get; set; } = new();
    [JsonPropertyName("orders")] public ShiftOrdersSummary Orders { get; set; } = new();
    [JsonPropertyName("delivery_cash")] public DeliveryCashSummary? DeliveryCash { get; set; }
    [JsonPropertyName("delivery_commission")] public DeliveryCommissionSummary? DeliveryCommission { get; set; }
}

public sealed class ShiftSummaryShift
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("mode")] public string Mode { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("started_at")] public string StartedAt { get; set; } = "";
    [JsonPropertyName("ended_at")] public string? EndedAt { get; set; }
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
    [JsonPropertyName("unit_name")] public string UnitName { get; set; } = "";
    [JsonPropertyName("unit_code")] public string UnitCode { get; set; } = "";
    [JsonPropertyName("user_name")] public string UserName { get; set; } = "";
    public string StartedDisplay => OperationalDisplay.LocalTime(StartedAt);
    public string EndedDisplay => string.IsNullOrWhiteSpace(EndedAt) ? "Em andamento" : OperationalDisplay.LocalTime(EndedAt!);
    public string ModeDisplay => Mode switch { "operation" => "Operação", "delivery" => "Delivery", "events" => "Eventos", "pay" => "Pay", _ => Mode };
}

public sealed class ShiftMethodSummary
{
    [JsonPropertyName("method")] public string Method { get; set; } = "";
    [JsonPropertyName("direction")] public string Direction { get; set; } = "";
    [JsonPropertyName("qty")] public int Qty { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string MethodDisplay => Method switch { "cash" => "Dinheiro", "pix" => "Pix", "credit" => "Crédito", "debit" => "Débito", "nfc" => "Aproximação", _ => Method };
    public string DirectionDisplay => Direction == "out" ? "Saída" : "Entrada";
}

public sealed class ShiftOrdersSummary
{
    [JsonPropertyName("qty")] public int Qty { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
}

public sealed class DeliveryCashSummary
{
    [JsonPropertyName("shift_id")] public int ShiftId { get; set; }
    [JsonPropertyName("cash_collected_cents")] public int CashCollectedCents { get; set; }
    [JsonPropertyName("confirmed_handoff_cents")] public int ConfirmedHandoffCents { get; set; }
    [JsonPropertyName("outstanding_cents")] public int OutstandingCents { get; set; }
    public string CollectedDisplay => OperationalDisplay.Money(CashCollectedCents);
    public string HandoffDisplay => OperationalDisplay.Money(ConfirmedHandoffCents);
    public string OutstandingDisplay => OperationalDisplay.Money(OutstandingCents);
}

public sealed class DeliveryCommissionSummary
{
    [JsonPropertyName("deliveries")] public int Deliveries { get; set; }
    [JsonPropertyName("revenue_cents")] public int RevenueCents { get; set; }
    [JsonPropertyName("percent_bps")] public int PercentBps { get; set; }
    [JsonPropertyName("fixed_per_delivery_cents")] public int FixedPerDeliveryCents { get; set; }
    [JsonPropertyName("percent_part_cents")] public int PercentPartCents { get; set; }
    [JsonPropertyName("fixed_part_cents")] public int FixedPartCents { get; set; }
    [JsonPropertyName("commission_cents")] public int CommissionCents { get; set; }
    public string RevenueDisplay => OperationalDisplay.Money(RevenueCents);
    public string CommissionDisplay => OperationalDisplay.Money(CommissionCents);
}

public sealed class UnassignedDeliveriesResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("orders")] public List<UnassignedDeliveryOrder> Orders { get; set; } = new();
}

public sealed class UnassignedDeliveryOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("customer_phone")] public string CustomerPhone { get; set; } = "";
    [JsonPropertyName("delivery_address")] public string DeliveryAddress { get; set; } = "";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
}

public sealed class UnitRoutingMutationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
}
