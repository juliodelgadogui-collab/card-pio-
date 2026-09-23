namespace EventMenu.Desktop.Services;

public static class DesktopFeatureNames
{
    public const string Core = "desktop-core";
    public const string Hardware = "hardware";
    public const string Terminal = "terminal";
    public const string Fiscal = "fiscal";
    public const string Production = "production";
    public const string Hub = "hub";

    public static string Label(string feature) => feature switch
    {
        Hardware => "equipamentos deste computador",
        Terminal => "pagamento com cartão",
        Fiscal => "nota fiscal",
        Production => "produção",
        Hub => "conexão com o celular",
        _ => "integração do Desktop"
    };
}
