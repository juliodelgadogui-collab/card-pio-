using System.Text.Json;
using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DesktopContextResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("permissions")] public Dictionary<string, bool> Permissions { get; set; } = new();
    [JsonPropertyName("permission_names")] public List<string> PermissionNames { get; set; } = new();
    [JsonPropertyName("modes")] public List<string> Modes { get; set; } = new();
    [JsonPropertyName("shift")] public Dictionary<string, JsonElement>? Shift { get; set; }
}

public sealed class PaymentStatusResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("payment")] public Dictionary<string, JsonElement>? Payment { get; set; }
}
