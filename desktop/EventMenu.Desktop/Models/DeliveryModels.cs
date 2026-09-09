using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DeliveryLiveResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("locations")] public List<DeliveryLiveLocation> Locations { get; set; } = new();
}

public sealed class DeliveryLiveLocation
{
    [JsonPropertyName("delivery_user_id")] public int DeliveryUserId { get; set; }
    [JsonPropertyName("delivery_name")] public string DeliveryName { get; set; } = "";
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("latitude")] public double Latitude { get; set; }
    [JsonPropertyName("longitude")] public double Longitude { get; set; }
    [JsonPropertyName("accuracy_m")] public double? AccuracyM { get; set; }
    [JsonPropertyName("speed_mps")] public double? SpeedMps { get; set; }
    [JsonPropertyName("heading_degrees")] public double? HeadingDegrees { get; set; }
    [JsonPropertyName("recorded_at")] public string RecordedAt { get; set; } = "";
    [JsonPropertyName("received_at")] public string ReceivedAt { get; set; } = "";
    [JsonPropertyName("order_status")] public string OrderStatus { get; set; } = "";
    [JsonPropertyName("delivery_address")] public string? DeliveryAddress { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("route_started_at")] public string? RouteStartedAt { get; set; }
    [JsonPropertyName("arrived_at")] public string? ArrivedAt { get; set; }
    [JsonPropertyName("fresh")] public bool Fresh { get; set; }

    public string OnlineLabel => Fresh ? "Ao vivo" : "Sinal atrasado";
    public string AccuracyLabel => AccuracyM is null ? "—" : $"±{AccuracyM:0} m";
    public string SpeedLabel => SpeedMps is null ? "—" : $"{SpeedMps.Value * 3.6:0} km/h";
    public string Coordinates => $"{Latitude:0.000000}, {Longitude:0.000000}";
}
