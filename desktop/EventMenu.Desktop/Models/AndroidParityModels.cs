using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class EventOverviewResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("events")] public List<EventOverviewItem> Events { get; set; } = new();
}

public sealed class EventOverviewItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("venue")] public string Venue { get; set; } = "";
    [JsonPropertyName("address")] public string Address { get; set; } = "";
    [JsonPropertyName("starts_at")] public string StartsAt { get; set; } = "";
    [JsonPropertyName("ends_at")] public string EndsAt { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("tickets_paid")] public int TicketsPaid { get; set; }
    [JsonPropertyName("tickets_checked_in")] public int TicketsCheckedIn { get; set; }
    [JsonPropertyName("tickets_reserved")] public int TicketsReserved { get; set; }
    [JsonPropertyName("guests_pending")] public int GuestsPending { get; set; }
    [JsonPropertyName("guests_checked_in")] public int GuestsCheckedIn { get; set; }
    [JsonPropertyName("ticket_revenue_cents")] public int TicketRevenueCents { get; set; }
    [JsonPropertyName("bar_revenue_cents")] public int BarRevenueCents { get; set; }
    [JsonPropertyName("revenue_cents")] public int RevenueCents { get; set; }
    public string StartsDisplay => OperationalDisplay.LocalTime(StartsAt);
    public string RevenueDisplay => OperationalDisplay.Money(RevenueCents);
    public string TicketRevenueDisplay => OperationalDisplay.Money(TicketRevenueCents);
    public string BarRevenueDisplay => OperationalDisplay.Money(BarRevenueCents);
    public string AccessDisplay => $"Ingressos {TicketsCheckedIn}/{TicketsPaid} • convidados {GuestsCheckedIn} dentro / {GuestsPending} pendentes";
    public override string ToString() => string.IsNullOrWhiteSpace(Name) ? $"Evento #{Id}" : Name;
}

public sealed class EventRecentResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("entries")] public List<EventEntryItem> Entries { get; set; } = new();
}

public sealed class EventEntryItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("entry_type")] public string Type { get; set; } = "";
    [JsonPropertyName("person_name")] public string PersonName { get; set; } = "";
    [JsonPropertyName("detail")] public string Detail { get; set; } = "";
    [JsonPropertyName("checked_in_at")] public string CheckedInAt { get; set; } = "";
    [JsonPropertyName("operator_name")] public string OperatorName { get; set; } = "";
    public string TypeDisplay => Type switch { "ticket" => "Ingresso", "guest" => "Convidado", _ => Type };
    public string CheckedInDisplay => OperationalDisplay.LocalTime(CheckedInAt);
}

public sealed class EventPickupResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public EventPickupOrder? Order { get; set; }
}

public sealed class EventPickupOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("event_id")] public int EventId { get; set; }
    [JsonPropertyName("event_name")] public string EventName { get; set; } = "";
    [JsonPropertyName("public_token")] public string PublicToken { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("customer_phone")] public string CustomerPhone { get; set; } = "";
    [JsonPropertyName("already_delivered")] public bool AlreadyDelivered { get; set; }
    [JsonPropertyName("can_deliver")] public bool CanDeliver { get; set; }
    [JsonPropertyName("items")] public List<EventPickupItem> Items { get; set; } = new();
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string StatusDisplay => AlreadyDelivered ? "Já entregue" : CanDeliver ? "Pronto para entregar" : Status;
}

public sealed class EventPickupItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
}

public sealed class ManagerOverviewResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("overview")] public ManagerOverview? Overview { get; set; }
}

public sealed class ManagerOverview
{
    [JsonPropertyName("orders_now")] public int OrdersNow { get; set; }
    [JsonPropertyName("kitchen_delayed")] public int KitchenDelayed { get; set; }
    [JsonPropertyName("ready_orders")] public int ReadyOrders { get; set; }
    [JsonPropertyName("unassigned_delivery")] public int UnassignedDelivery { get; set; }
    [JsonPropertyName("delivery_online")] public int DeliveryOnline { get; set; }
    [JsonPropertyName("cash_open")] public int CashOpen { get; set; }
    [JsonPropertyName("pending_payments")] public int PendingPayments { get; set; }
    [JsonPropertyName("revenue_today_cents")] public int RevenueTodayCents { get; set; }
    [JsonPropertyName("alerts")] public List<ManagerAlert> Alerts { get; set; } = new();
    public string RevenueTodayDisplay => OperationalDisplay.Money(RevenueTodayCents);
}

public sealed class ManagerAlert
{
    [JsonPropertyName("level")] public string Level { get; set; } = "info";
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("message")] public string Message { get; set; } = "";
    public string LevelDisplay => Level switch { "critical" => "Urgente", "warning" => "Atenção", "success" => "OK", _ => "Info" };
}

public sealed class ManagerDetailsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("details")] public ManagerDetails? Details { get; set; }
}

public sealed class ManagerDetails
{
    [JsonPropertyName("cash_sessions")] public List<ManagerCashSession> CashSessions { get; set; } = new();
    [JsonPropertyName("delivery_shifts")] public List<ManagerDeliveryShift> DeliveryShifts { get; set; } = new();
    [JsonPropertyName("problem_orders")] public List<ManagerProblemOrder> ProblemOrders { get; set; } = new();
}

public sealed class ManagerCashSession
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("user_id")] public int UserId { get; set; }
    [JsonPropertyName("user_name")] public string UserName { get; set; } = "";
    [JsonPropertyName("opening_cash_cents")] public int OpeningCashCents { get; set; }
    [JsonPropertyName("opened_at")] public string OpenedAt { get; set; } = "";
    public string OpeningDisplay => OperationalDisplay.Money(OpeningCashCents);
    public string OpenedDisplay => OperationalDisplay.LocalTime(OpenedAt);
}

public sealed class ManagerDeliveryShift
{
    [JsonPropertyName("shift_id")] public int ShiftId { get; set; }
    [JsonPropertyName("user_id")] public int UserId { get; set; }
    [JsonPropertyName("user_name")] public string UserName { get; set; } = "";
    [JsonPropertyName("started_at")] public string StartedAt { get; set; } = "";
    [JsonPropertyName("active_orders")] public int ActiveOrders { get; set; }
    public string StartedDisplay => OperationalDisplay.LocalTime(StartedAt);
}

public sealed class ManagerProblemOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("assigned_delivery_user_id")] public int? DeliveryUserId { get; set; }
    [JsonPropertyName("delivery_name")] public string DeliveryName { get; set; } = "";
    [JsonPropertyName("updated_at")] public string UpdatedAt { get; set; } = "";
    [JsonPropertyName("problem_type")] public string ProblemType { get; set; } = "";
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string UpdatedDisplay => OperationalDisplay.LocalTime(UpdatedAt);
}

public sealed class ManagerReopenCandidatesResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("orders")] public List<ManagerReopenCandidate> Orders { get; set; } = new();
}

public sealed class ManagerReopenCandidate
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("table_name")] public string TableName { get; set; } = "";
    [JsonPropertyName("updated_at")] public string UpdatedAt { get; set; } = "";
    [JsonPropertyName("reopen_eligible")] public int ReopenEligible { get; set; }
    [JsonPropertyName("reopen_block_reason")] public string BlockReason { get; set; } = "";
    public bool Eligible => ReopenEligible == 1;
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string UpdatedDisplay => OperationalDisplay.LocalTime(UpdatedAt);
}

public sealed class OrderOpsDetailResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("detail")] public OrderOpsDetail? Detail { get; set; }
    [JsonPropertyName("loyalty")] public LoyaltyOrderSummary? Loyalty { get; set; }
    [JsonPropertyName("order_total_cents")] public int OrderTotalCents { get; set; }
}

public sealed class OrderOpsDetail
{
    [JsonPropertyName("order")] public OrderOpsOrder Order { get; set; } = new();
    [JsonPropertyName("customer")] public OrderOpsCustomer? Customer { get; set; }
    [JsonPropertyName("items")] public List<OrderOpsItem> Items { get; set; } = new();
    [JsonPropertyName("timeline")] public List<OrderTimelineItem> Timeline { get; set; } = new();
    [JsonPropertyName("loyalty")] public LoyaltyOrderSummary? Loyalty { get; set; }
}

public sealed class OrderOpsOrder
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("subtotal_cents")] public int SubtotalCents { get; set; }
    [JsonPropertyName("delivery_fee_cents")] public int DeliveryFeeCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("delivery_address")] public string DeliveryAddress { get; set; } = "";
    [JsonPropertyName("notes")] public string Notes { get; set; } = "";
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
}

public sealed class OrderOpsCustomer
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("phone")] public string Phone { get; set; } = "";
    [JsonPropertyName("email")] public string Email { get; set; } = "";
}

public sealed class OrderOpsItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name_snapshot")] public string Name { get; set; } = "";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("notes")] public string Notes { get; set; } = "";
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
}

public sealed class OrderTimelineItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("from_status")] public string FromStatus { get; set; } = "";
    [JsonPropertyName("to_status")] public string ToStatus { get; set; } = "";
    [JsonPropertyName("source")] public string Source { get; set; } = "";
    [JsonPropertyName("notes")] public string Notes { get; set; } = "";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    [JsonPropertyName("user_name")] public string UserName { get; set; } = "";
    public string CreatedDisplay => OperationalDisplay.LocalTime(CreatedAt);
    public string TransitionDisplay => string.IsNullOrWhiteSpace(FromStatus) ? ToStatus : $"{FromStatus} → {ToStatus}";
}

public sealed class LoyaltyOrderSummary
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("customer_id")] public int CustomerId { get; set; }
    [JsonPropertyName("balance")] public int Balance { get; set; }
    [JsonPropertyName("reserved")] public int Reserved { get; set; }
    [JsonPropertyName("available")] public int Available { get; set; }
    [JsonPropertyName("redeem_points")] public int RedeemPoints { get; set; }
    [JsonPropertyName("redeem_value_cents")] public int RedeemValueCents { get; set; }
    [JsonPropertyName("min_redeem_points")] public int MinRedeemPoints { get; set; }
    [JsonPropertyName("max_redeem_percent")] public int MaxRedeemPercent { get; set; }
    [JsonPropertyName("order_reservation")] public LoyaltyOrderReservation? OrderReservation { get; set; }
}

public sealed class LoyaltyOrderReservation
{
    [JsonPropertyName("points")] public int Points { get; set; }
    [JsonPropertyName("discount_cents")] public int DiscountCents { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    public string DiscountDisplay => OperationalDisplay.Money(DiscountCents);
}

public sealed class DeliveryMineResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("progress")] public List<DeliveryMineItem> Progress { get; set; } = new();
}

public sealed class DeliveryMutationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("progress")] public DeliveryMineItem? Progress { get; set; }
    [JsonPropertyName("tracking_url")] public string? TrackingUrl { get; set; }
    [JsonPropertyName("tracking_expires_at")] public string? TrackingExpiresAt { get; set; }
}

public sealed class DeliveryMineItem
{
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("order_status")] public string OrderStatus { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("customer_name")] public string CustomerName { get; set; } = "";
    [JsonPropertyName("customer_phone")] public string CustomerPhone { get; set; } = "";
    [JsonPropertyName("delivery_address")] public string DeliveryAddress { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("picked_up_at")] public string? PickedUpAt { get; set; }
    [JsonPropertyName("route_started_at")] public string? RouteStartedAt { get; set; }
    [JsonPropertyName("arrived_at")] public string? ArrivedAt { get; set; }
    [JsonPropertyName("completed_at")] public string? CompletedAt { get; set; }
    [JsonPropertyName("tracking_url")] public string? TrackingUrl { get; set; }
    [JsonPropertyName("tracking_expires_at")] public string? TrackingExpiresAt { get; set; }
    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string StageDisplay => !string.IsNullOrWhiteSpace(CompletedAt) ? "Concluída" : !string.IsNullOrWhiteSpace(ArrivedAt) ? "Chegou" : !string.IsNullOrWhiteSpace(RouteStartedAt) ? "Em rota" : !string.IsNullOrWhiteSpace(PickedUpAt) ? "Retirada" : "Aguardando retirada";
}

public sealed class ManagerMutationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("order")] public Dictionary<string, JsonElement>? Order { get; set; }
}
