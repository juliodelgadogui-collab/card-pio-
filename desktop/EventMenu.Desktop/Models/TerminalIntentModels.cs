using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class TerminalIntentResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("intent")] public TerminalIntent? Intent { get; set; }
}

public sealed class TerminalIntent
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("terminal_config_id")] public int TerminalConfigId { get; set; }
    [JsonPropertyName("provider")] public string Provider { get; set; } = "";
    [JsonPropertyName("intent_token")] public string IntentToken { get; set; } = "";
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("payment_type")] public string PaymentType { get; set; } = "";
    [JsonPropertyName("installments")] public int Installments { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("provider_transaction_id")] public string? ProviderTransactionId { get; set; }
    [JsonPropertyName("authorization_code")] public string? AuthorizationCode { get; set; }
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("verified_at")] public string? VerifiedAt { get; set; }
    [JsonPropertyName("terminal")] public PaymentTerminalConfig? Terminal { get; set; }
}
