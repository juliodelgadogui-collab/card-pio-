using System.Text.Json.Serialization;

namespace EventMenu.WhatsAppConnect.Models;

public sealed class UserInfo
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("tenant_id")] public int TenantId { get; set; }
    [JsonPropertyName("tenant_name")] public string TenantName { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("email")] public string Email { get; set; } = "";
    [JsonPropertyName("role")] public string Role { get; set; } = "";
}

public sealed class SessionEnvelope
{
    public string Token { get; set; } = "";
    public string RefreshToken { get; set; } = "";
    public string ExpiresAt { get; set; } = "";
    public string RefreshExpiresAt { get; set; } = "";
    public UserInfo? User { get; set; }
}

public sealed class LoginRefreshResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("token")] public string Token { get; set; } = "";
    [JsonPropertyName("refresh_token")] public string RefreshToken { get; set; } = "";
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("refresh_expires_at")] public string RefreshExpiresAt { get; set; } = "";
    [JsonPropertyName("user")] public UserInfo? User { get; set; }
}

public sealed class LocalBridgeState
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "disconnected";
    [JsonPropertyName("qr")] public string? Qr { get; set; }
    [JsonPropertyName("pairing_code")] public string? PairingCode { get; set; }
    [JsonPropertyName("pairing_phone")] public string PairingPhone { get; set; } = "";
    [JsonPropertyName("phone")] public string Phone { get; set; } = "";
    [JsonPropertyName("error")] public string? Error { get; set; }
    [JsonPropertyName("updated_at")] public string UpdatedAt { get; set; } = "";
}

public sealed class LocalSendResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("message_id")] public string MessageId { get; set; } = "";
}

public sealed class CloudAgent
{
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("phone_number")] public string? PhoneNumber { get; set; }
    [JsonPropertyName("last_seen_at")] public string? LastSeenAt { get; set; }
    [JsonPropertyName("last_error")] public string? LastError { get; set; }
}

public sealed class CloudStateResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("agent")] public CloudAgent? Agent { get; set; }
    [JsonPropertyName("pending")] public int Pending { get; set; }
    [JsonPropertyName("provider")] public string Provider { get; set; } = "";
}

public sealed class CloudClaimMessage
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("recipient")] public string Recipient { get; set; } = "";
    [JsonPropertyName("message_text")] public string MessageText { get; set; } = "";
    [JsonPropertyName("claim_token")] public string ClaimToken { get; set; } = "";
    [JsonPropertyName("claim_expires_at")] public string ClaimExpiresAt { get; set; } = "";
    [JsonPropertyName("attempt_count")] public int AttemptCount { get; set; }
    [JsonPropertyName("max_attempts")] public int MaxAttempts { get; set; }
}

public sealed class CloudClaimResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("messages")] public List<CloudClaimMessage> Messages { get; set; } = new();
}

public sealed class ApiError
{
    [JsonPropertyName("error")] public string Error { get; set; } = "Não foi possível concluir a operação.";
}

public sealed class LocalWhatsAppSettings
{
    public string Secret { get; set; } = "";
    public string SessionKey { get; set; } = "";
    public int Port { get; set; } = 21467;
}
