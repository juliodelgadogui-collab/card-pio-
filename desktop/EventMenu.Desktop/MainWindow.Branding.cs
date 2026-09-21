using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _brandLoading;
    private static readonly Color DefaultPrimary = Color.FromRgb(91, 52, 214);
    private static readonly Color DefaultBackground = Color.FromRgb(246, 247, 251);
    private static readonly Color DefaultSurface = Colors.White;
    private static readonly Color DefaultText = Color.FromRgb(30, 27, 43);

    private async void ShellPanel_BrandVisibilityChanged(object sender, DependencyPropertyChangedEventArgs e)
    {
        if (ShellPanel.Visibility != Visibility.Visible || _store is null || _brandLoading) return;
        _brandLoading = true;
        try
        {
            using var service = new TenantBrandDesktopService(_store);
            var brand = await service.LoadAsync();
            if (brand is not null && brand.ApplyWeb) ApplyTenantBrand(brand);
            else ApplyDefaultDesignTokens();
        }
        finally { _brandLoading = false; }
    }

    private void ApplyTenantBrand(TenantBrand brand)
    {
        var displayName = string.IsNullOrWhiteSpace(brand.DisplayName) ? _currentUser?.TenantName : brand.DisplayName.Trim();
        if (string.IsNullOrWhiteSpace(displayName)) displayName = "Minha empresa";
        TenantNameText.Text = displayName;
        Title = $"{displayName} • EventMenu";

        var primary = ParseColor(brand.PrimaryColor, DefaultPrimary);
        var background = ParseColor(brand.BackgroundColor, DefaultBackground);
        var surface = ParseColor(brand.SurfaceColor, DefaultSurface);
        var text = ParseColor(brand.TextColor, DefaultText);
        ApplyDesignTokens(primary, background, surface, text);
        ApplyProfessionalShell();

        foreach (var textBlock in Descendants<TextBlock>(ShellPanel))
        {
            if (textBlock.Text == "EventMenu")
            {
                textBlock.Text = displayName;
                break;
            }
        }
    }

    private void ApplyDefaultDesignTokens()
    {
        ApplyDesignTokens(DefaultPrimary, DefaultBackground, DefaultSurface, DefaultText);
        ApplyProfessionalShell();
    }

    private static void ApplyDesignTokens(Color primary, Color background, Color surface, Color text)
    {
        var resources = Application.Current.Resources;
        resources["AccentBrush"] = new SolidColorBrush(primary);
        resources["AccentHoverBrush"] = new SolidColorBrush(Darken(primary, 0.14));
        resources["AccentSoftBrush"] = new SolidColorBrush(Blend(primary, Colors.White, 0.90));
        resources["BackgroundBrush"] = new SolidColorBrush(background);
        resources["SurfaceBrush"] = new SolidColorBrush(surface);
        resources["PrimaryBrush"] = new SolidColorBrush(text);

        // Status são semânticos e não seguem a cor promocional da empresa.
        resources["SuccessBrush"] = new SolidColorBrush(Color.FromRgb(15, 159, 110));
        resources["SuccessSoftBrush"] = new SolidColorBrush(Color.FromRgb(234, 248, 242));
        resources["WarningBrush"] = new SolidColorBrush(Color.FromRgb(194, 123, 8));
        resources["WarningSoftBrush"] = new SolidColorBrush(Color.FromRgb(255, 245, 229));
        resources["DangerBrush"] = new SolidColorBrush(Color.FromRgb(217, 45, 72));
        resources["DangerSoftBrush"] = new SolidColorBrush(Color.FromRgb(255, 237, 240));
    }

    private void ApplyProfessionalShell()
    {
        ShellPanel.Background = (Brush)Application.Current.Resources["BackgroundBrush"];
        if (PosNavButton.Parent is not StackPanel menu) return;

        foreach (var button in menu.Children.OfType<Button>())
        {
            button.Background = Brushes.Transparent;
            button.Foreground = new SolidColorBrush(Color.FromRgb(229, 231, 235));
            button.HorizontalContentAlignment = HorizontalAlignment.Left;
            button.FontWeight = FontWeights.SemiBold;
            button.Padding = new Thickness(14, 11, 14, 11);
            button.Margin = new Thickness(0, 0, 0, 4);
        }
    }

    private static Color ParseColor(string? value, Color fallback)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(value)) return fallback;
            var parsed = ColorConverter.ConvertFromString(value.Trim());
            return parsed is Color color ? color : fallback;
        }
        catch { return fallback; }
    }

    private static Color Darken(Color color, double amount) => Color.FromRgb(
        (byte)Math.Clamp(color.R * (1 - amount), 0, 255),
        (byte)Math.Clamp(color.G * (1 - amount), 0, 255),
        (byte)Math.Clamp(color.B * (1 - amount), 0, 255));

    private static Color Blend(Color source, Color target, double targetAmount) => Color.FromRgb(
        (byte)Math.Clamp(source.R * (1 - targetAmount) + target.R * targetAmount, 0, 255),
        (byte)Math.Clamp(source.G * (1 - targetAmount) + target.G * targetAmount, 0, 255),
        (byte)Math.Clamp(source.B * (1 - targetAmount) + target.B * targetAmount, 0, 255));

    private static IEnumerable<T> Descendants<T>(DependencyObject root) where T : DependencyObject
    {
        for (var i = 0; i < VisualTreeHelper.GetChildrenCount(root); i++)
        {
            var child = VisualTreeHelper.GetChild(root, i);
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}
