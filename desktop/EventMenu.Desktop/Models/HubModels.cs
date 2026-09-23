using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class HubPairingResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("pairing")] public HubPairing? Pairing { get; set; }
}

public sealed class HubPairing
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("token")] public string Token { get; set; } = "";
    [JsonPropertyName("qr")] public string Qr { get; set; } = "";
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("desktop")] public Dictionary<string, JsonElement> Desktop { get; set; } = new();
}

public sealed class HubCommandsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("commands")] public List<HubCommand> Commands { get; set; } = new();
}

public sealed class HubCommandResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("command")] public HubCommand? Command { get; set; }
}

public sealed class HubCommand
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("command_type")] public string CommandType { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payload")] public Dictionary<string, JsonElement> Payload { get; set; } = new();
    [JsonPropertyName("result")] public Dictionary<string, JsonElement> Result { get; set; } = new();
    [JsonPropertyName("error_message")] public string? ErrorMessage { get; set; }
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
}
