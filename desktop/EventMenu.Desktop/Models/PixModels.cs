using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class PixDetails
{
    [JsonPropertyName("provider")] public string Provider { get; set; } = "";
    [JsonPropertyName("payment_id")] public int PaymentId { get; set; }
    [JsonPropertyName("order_id")] public int OrderId { get; set; }
    [JsonPropertyName("amount_cents")] public int AmountCents { get; set; }
    [JsonPropertyName("copy_paste")] public string CopyPaste { get; set; } = "";
    [JsonPropertyName("image_url")] public string ImageUrl { get; set; } = "";
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("reused")] public bool Reused { get; set; }

    public string ProviderDisplay => Provider.ToLowerInvariant() switch
    {
        "mercadopago" => "Mercado Pago",
        "pagbank" => "PagBank",
        "efi" => "Efí",
        "inter" => "Banco Inter",
        _ => string.IsNullOrWhiteSpace(Provider) ? "Pix" : Provider
    };
}

public sealed class PixCreateResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("pix")] public PixDetails? Pix { get; set; }
}
