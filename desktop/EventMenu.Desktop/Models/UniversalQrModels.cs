using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class UniversalQrResolveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("qr")] public UniversalQrResult? Qr { get; set; }
}

public sealed class UniversalQrResult
{
    [JsonPropertyName("type")] public string Type { get; set; } = "";
    [JsonPropertyName("entity_id")] public int EntityId { get; set; }
    [JsonPropertyName("label")] public string Label { get; set; } = "";
    [JsonPropertyName("data")] public Dictionary<string, JsonElement> Data { get; set; } = new();
}

public sealed class UniversalQrIssueResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("qr")] public IssuedUniversalQr? Qr { get; set; }
}

public sealed class IssuedUniversalQr
{
    [JsonPropertyName("type")] public string Type { get; set; } = "";
    [JsonPropertyName("entity_id")] public int EntityId { get; set; }
    [JsonPropertyName("label")] public string Label { get; set; } = "";
    [JsonPropertyName("payload")] public string Payload { get; set; } = "";
    [JsonPropertyName("expires_at")] public string? ExpiresAt { get; set; }
    public string ExpiresDisplay => string.IsNullOrWhiteSpace(ExpiresAt) ? "Sem prazo definido" : ServerTimeDisplay.Local(ExpiresAt!);
}
