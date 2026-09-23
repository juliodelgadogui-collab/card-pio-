using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class TenantBrand
{
    [JsonPropertyName("display_name")] public string DisplayName { get; set; } = "EventMenu";
    [JsonPropertyName("tagline")] public string Tagline { get; set; } = "";
    [JsonPropertyName("logo_url")] public string LogoUrl { get; set; } = "";
    [JsonPropertyName("primary_color")] public string PrimaryColor { get; set; } = "#5b34d6";
    [JsonPropertyName("secondary_color")] public string SecondaryColor { get; set; } = "#159b63";
    [JsonPropertyName("background_color")] public string BackgroundColor { get; set; } = "#f6f7fb";
    [JsonPropertyName("surface_color")] public string SurfaceColor { get; set; } = "#ffffff";
    [JsonPropertyName("text_color")] public string TextColor { get; set; } = "#1e1b2b";
    [JsonPropertyName("apply_web")] public bool ApplyWeb { get; set; } = true;
    [JsonPropertyName("show_eventmenu_brand")] public bool ShowEventMenuBrand { get; set; } = true;
}

public sealed class TenantBrandResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("brand")] public TenantBrand Brand { get; set; } = new();
}
