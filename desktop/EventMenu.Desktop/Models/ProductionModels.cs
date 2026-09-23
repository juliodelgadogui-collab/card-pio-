using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class ProductionBoardResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("board")] public ProductionBoard Board { get; set; } = new();
}

public sealed class ProductionBoard
{
    [JsonPropertyName("unit")] public OperatingUnit? Unit { get; set; }
    [JsonPropertyName("stations")] public List<ProductionStation> Stations { get; set; } = new();
    [JsonPropertyName("jobs")] public List<ProductionJob> Jobs { get; set; } = new();
    [JsonPropertyName("print_queue")] public List<ProductionQueueItem> PrintQueue { get; set; } = new();
    [JsonPropertyName("version")] public string Version { get; set; } = "";
}

public sealed class ProductionStation
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("code")] public string Code { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("station_type")] public string StationType { get; set; } = "";
    [JsonPropertyName("sla_minutes")] public int SlaMinutes { get; set; }
    [JsonPropertyName("printer_mode")] public string PrinterMode { get; set; } = "manual";
    [JsonPropertyName("printer_target")] public string? PrinterTarget { get; set; }
    [JsonPropertyName("desktop_device_id")] public string? DesktopDeviceId { get; set; }
    public string PrinterLabel => string.IsNullOrWhiteSpace(PrinterTarget) ? "Sem impressora" : PrinterTarget!;
}

public sealed class ProductionJob
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("station_id")] public int StationId { get; set; }
    [JsonPropertyName("station_name")] public string StationName { get; set; } = "";
    [JsonPropertyName("station_type")] public string StationType { get; set; } = "";
    [JsonPropertyName("kind")] public string Kind { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("prep_minutes")] public int PrepMinutes { get; set; }
    [JsonPropertyName("elapsed_minutes")] public int ElapsedMinutes { get; set; }
    [JsonPropertyName("delayed")] public bool Delayed { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("table_name")] public string? TableName { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("order_notes")] public string? OrderNotes { get; set; }
    public string TimeLabel => Delayed ? $"ATRASADO • {ElapsedMinutes} min" : $"{ElapsedMinutes} min";
}

public sealed class ProductionQueueItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("station_id")] public int StationId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("station_name")] public string StationName { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("attempts")] public int Attempts { get; set; }
    [JsonPropertyName("last_error")] public string? LastError { get; set; }
}

public sealed class ExpeditionResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("orders")] public List<ExpeditionOrder> Orders { get; set; } = new();
}

public sealed class ExpeditionOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("table_name")] public string? TableName { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("jobs_total")] public int JobsTotal { get; set; }
    [JsonPropertyName("jobs_ready")] public int JobsReady { get; set; }
    [JsonPropertyName("all_ready")] public bool AllReady { get; set; }
    [JsonPropertyName("delivery_user_id")] public int? DeliveryUserId { get; set; }
    [JsonPropertyName("delivery_name")] public string? DeliveryName { get; set; }
    [JsonPropertyName("delivery_stage")] public string? DeliveryStage { get; set; }
    [JsonPropertyName("delivery_picked_up")] public bool DeliveryPickedUp { get; set; }
    [JsonPropertyName("delivery_route_started")] public bool DeliveryRouteStarted { get; set; }
    [JsonPropertyName("delivery_arrived")] public bool DeliveryArrived { get; set; }
    [JsonPropertyName("delivery_location_fresh")] public bool DeliveryLocationFresh { get; set; }
    public string DeliveryDisplay => Channel == "delivery"
        ? $"{(string.IsNullOrWhiteSpace(DeliveryStage) ? "Delivery" : DeliveryStage)}{(string.IsNullOrWhiteSpace(DeliveryName) ? "" : " • " + DeliveryName)}"
        : "—";
}

public sealed class ProductionPrintClaimResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("print")] public ProductionPrintPayload? Print { get; set; }
}

public sealed class ProductionPrintPayload
{
    [JsonPropertyName("queue")] public ProductionPrintQueue Queue { get; set; } = new();
    [JsonPropertyName("items")] public List<ProductionPrintLine> Items { get; set; } = new();
    [JsonPropertyName("printer_target")] public string PrinterTarget { get; set; } = "";
    [JsonPropertyName("device_id")] public string DeviceId { get; set; } = "";
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
}

public sealed class ProductionPrintQueue
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("station_id")] public int StationId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("station_name")] public string StationName { get; set; } = "";
    [JsonPropertyName("station_type")] public string StationType { get; set; } = "";
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("table_name")] public string? TableName { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("order_notes")] public string? OrderNotes { get; set; }
    [JsonPropertyName("order_created_at")] public string? OrderCreatedAt { get; set; }
    [JsonPropertyName("delivery_address")] public string? DeliveryAddress { get; set; }
    [JsonPropertyName("subtotal_cents")] public int SubtotalCents { get; set; }
    [JsonPropertyName("discount_cents")] public int DiscountCents { get; set; }
    [JsonPropertyName("delivery_fee_cents")] public int DeliveryFeeCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("queue_type")] public string QueueType { get; set; } = "auto";
    [JsonPropertyName("trigger_event")] public string? TriggerEvent { get; set; }
    [JsonPropertyName("print_scope")] public string PrintScope { get; set; } = "station";
    [JsonPropertyName("reprint_of")] public int? ReprintOf { get; set; }
    [JsonPropertyName("reason")] public string? Reason { get; set; }
    [JsonPropertyName("attempts")] public int Attempts { get; set; }
}

public sealed class ProductionPrintLine
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("kind")] public string Kind { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("notes")] public string? Notes { get; set; }
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
}

public sealed class ProductionMutationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("job")] public ProductionJob? Job { get; set; }
    [JsonPropertyName("queue")] public ProductionQueueItem? Queue { get; set; }
    [JsonPropertyName("station")] public ProductionStation? Station { get; set; }
}
