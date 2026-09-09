using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DeliveryLiveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("locations")] public List<DeliveryLiveLocation> Locations { get; set; } = new();
}

public sealed class DeliverySignal
{
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("label")] public string Label { get; set; } = "";
    [JsonPropertyName("age_seconds")] public int AgeSeconds { get; set; }
}

public sealed class DeliveryLiveLocation
{
    [JsonPropertyName("delivery_user_id")] public int DeliveryUserId { get; set; }
    [JsonPropertyName("delivery_name")] public string DeliveryName { get; set; } = "";
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("latitude")] public double? Latitude { get; set; }
    [JsonPropertyName("longitude")] public double? Longitude { get; set; }
    [JsonPropertyName("accuracy_m")] public double? AccuracyM { get; set; }
    [JsonPropertyName("speed_mps")] public double? SpeedMps { get; set; }
    [JsonPropertyName("heading_degrees")] public double? HeadingDegrees { get; set; }
    [JsonPropertyName("bearing_deg")] public double? BearingDegrees { set { if (value.HasValue) HeadingDegrees = value; } }
    [JsonPropertyName("recorded_at")] public string RecordedAt { get; set; } = "";
    [JsonPropertyName("captured_at")] public string CapturedAt { set { if (!string.IsNullOrWhiteSpace(value)) RecordedAt = value; } }
    [JsonPropertyName("received_at")] public string ReceivedAt { get; set; } = "";
    [JsonPropertyName("updated_at")] public string UpdatedAt { set { if (!string.IsNullOrWhiteSpace(value)) ReceivedAt = value; } }
    [JsonPropertyName("order_status")] public string OrderStatus { get; set; } = "out_for_delivery";
    [JsonPropertyName("delivery_address")] public string? DeliveryAddress { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("route_started_at")] public string? RouteStartedAt { get; set; }
    [JsonPropertyName("arrived_at")] public string? ArrivedAt { get; set; }
    [JsonPropertyName("fresh")] public bool Fresh { get; set; }
    [JsonPropertyName("signal")] public DeliverySignal? Signal { get; set; }

    [JsonIgnore] public bool HasPosition => Latitude.HasValue && Longitude.HasValue;
    [JsonIgnore] public bool IsLive => Signal is not null ? Signal.Status.Equals("live", StringComparison.OrdinalIgnoreCase) : Fresh;
    [JsonIgnore] public string OnlineLabel => !HasPosition ? "Aguardando GPS" : Signal is not null && !string.IsNullOrWhiteSpace(Signal.Label) ? Signal.Label : Fresh ? "Ao vivo" : "Sinal atrasado";
    [JsonIgnore] public string AccuracyLabel => AccuracyM is null ? "—" : $"±{AccuracyM:0} m";
    [JsonIgnore] public string SpeedLabel => SpeedMps is null ? "—" : $"{SpeedMps.Value * 3.6:0} km/h";
    [JsonIgnore] public string Coordinates => HasPosition ? $"{Latitude!.Value:0.000000}, {Longitude!.Value:0.000000}" : "Aguardando posição";
}
