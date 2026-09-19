using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class UserInfo
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("tenant_id")] public int TenantId { get; set; }
    [JsonPropertyName("tenant_name")] public string TenantName { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("email")] public string Email { get; set; } = "";
    [JsonPropertyName("role")] public string Role { get; set; } = "";
}

public sealed class LoginResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("token")] public string Token { get; set; } = "";
    [JsonPropertyName("expires_at")] public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("refresh_token")] public string RefreshToken { get; set; } = "";
    [JsonPropertyName("refresh_expires_at")] public string RefreshExpiresAt { get; set; } = "";
    [JsonPropertyName("user")] public UserInfo? User { get; set; }
}

public sealed class MeResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("user")] public UserInfo? User { get; set; }
    [JsonPropertyName("permissions")] public Dictionary<string, bool> Permissions { get; set; } = new();
}

public sealed class Product
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("category_id")] public int? CategoryId { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("sku")] public string Sku { get; set; } = "";
    [JsonPropertyName("price_cents")] public int PriceCents { get; set; }
    [JsonPropertyName("stock_qty")] public decimal? StockQty { get; set; }
    [JsonPropertyName("track_stock")] public int TrackStock { get; set; }
    public string PriceDisplay => (PriceCents / 100m).ToString("C2", new System.Globalization.CultureInfo("pt-BR"));
}

public sealed class ProductsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("products")] public List<Product> Products { get; set; } = new();
}

public sealed class Order
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("public_token")] public string PublicToken { get; set; } = "";
    [JsonPropertyName("channel")] public string Channel { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_status")] public string PaymentStatus { get; set; } = "";
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("customer_name")] public string? CustomerName { get; set; }
    [JsonPropertyName("customer_phone")] public string? CustomerPhone { get; set; }
    [JsonPropertyName("delivery_name")] public string? DeliveryName { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    public string TotalDisplay => (TotalCents / 100m).ToString("C2", new System.Globalization.CultureInfo("pt-BR"));
}

public sealed class OrdersResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("orders")] public List<Order> Orders { get; set; } = new();
}

public sealed class CashResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("session")] public Dictionary<string, object?>? Session { get; set; }
}

public sealed class ApiError
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("error")] public string Error { get; set; } = "Falha na comunicação com o servidor.";
}
