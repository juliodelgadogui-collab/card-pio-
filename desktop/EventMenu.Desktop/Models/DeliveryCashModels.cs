using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DeliveryCashReceiptResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("receipt")] public DeliveryCashReceipt? Receipt { get; set; }
}

public sealed class DeliveryCashReceipt
{
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("payment_id")] public int? PaymentId { get; set; }
    [JsonPropertyName("paid")] public bool Paid { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("received_cents")] public int ReceivedCents { get; set; }
    [JsonPropertyName("change_cents")] public int ChangeCents { get; set; }

    public string TotalDisplay => OperationalDisplay.Money(TotalCents);
    public string ReceivedDisplay => OperationalDisplay.Money(ReceivedCents);
    public string ChangeDisplay => OperationalDisplay.Money(ChangeCents);
}

public sealed class DeliveryCashOutstandingResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("cash")] public DeliveryCashBalance? Cash { get; set; }
}

public sealed class DeliveryCashBalance
{
    [JsonPropertyName("shift_id")] public int ShiftId { get; set; }
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
    [JsonPropertyName("cash_collected_cents")] public int CashCollectedCents { get; set; }
    [JsonPropertyName("confirmed_handoff_cents")] public int ConfirmedHandoffCents { get; set; }
    [JsonPropertyName("outstanding_cents")] public int OutstandingCents { get; set; }

    public string CollectedDisplay => OperationalDisplay.Money(CashCollectedCents);
    public string ConfirmedDisplay => OperationalDisplay.Money(ConfirmedHandoffCents);
    public string OutstandingDisplay => OperationalDisplay.Money(OutstandingCents);
}

public sealed class DeliveryCashHandoffResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("handoff")] public DeliveryCashHandoff? Handoff { get; set; }
}

public sealed class DeliveryCashHandoff
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("work_shift_id")] public int WorkShiftId { get; set; }
    [JsonPropertyName("delivery_user_id")] public int DeliveryUserId { get; set; }
    [JsonPropertyName("delivery_name")] public string DeliveryName { get; set; } = "";
    [JsonPropertyName("unit_id")] public int? UnitId { get; set; }
    [JsonPropertyName("unit_name")] public string UnitName { get; set; } = "";
    [JsonPropertyName("token")] public string Token { get; set; } = "";
    [JsonPropertyName("qr_payload")] public string QrPayload { get; set; } = "";
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("requested_at")] public string RequestedAt { get; set; } = "";
    [JsonPropertyName("confirmed_at")] public string? ConfirmedAt { get; set; }

    public string AmountDisplay => OperationalDisplay.Money(AmountCents);
    public string StatusDisplay => Status switch
    {
        "pending" => "Aguardando confirmação",
        "confirmed" => "Recebido pelo caixa",
        "cancelled" => "Cancelado",
        _ => string.IsNullOrWhiteSpace(Status) ? "Pendente" : Status
    };
    public string RequestedDisplay => OperationalDisplay.LocalTime(RequestedAt);
}
