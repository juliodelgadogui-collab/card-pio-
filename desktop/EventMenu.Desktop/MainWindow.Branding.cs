using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _brandLoading;

    private async void ShellPanel_BrandVisibilityChanged(object sender, DependencyPropertyChangedEventArgs e)
    {
        if (ShellPanel.Visibility != Visibility.Visible || _store is null || _brandLoading) return;
        _brandLoading = true;
        try
        {
            using var service = new TenantBrandDesktopService(_store);
            var brand = await service.LoadAsync();
            if (brand is not null && brand.ApplyWeb) ApplyTenantBrand(brand);
        }
        finally { _brandLoading = false; }
    }

    private void ApplyTenantBrand(TenantBrand brand)
    {
        var displayName = string.IsNullOrWhiteSpace(brand.DisplayName) ? _currentUser?.TenantName : brand.DisplayName.Trim();
        if (string.IsNullOrWhiteSpace(displayName)) displayName = "Minha empresa";
        TenantNameText.Text = displayName;
        Title = $"{displayName} • EventMenu";

        // A identidade da empresa não deve pintar toda a área operacional.
        // Mantemos fundo e navegação neutros para preservar contraste e legibilidade.
        ShellPanel.Background = new SolidColorBrush(Color.FromRgb(246, 247, 251));
        if (PosNavButton.Parent is StackPanel menu && menu.Parent is Border sidebar)
            sidebar.Background = new SolidColorBrush(Color.FromRgb(17, 24, 39));

        foreach (var text in Descendants<TextBlock>(ShellPanel))
        {
            if (text.Text == "EventMenu")
            {
                text.Text = displayName;
                break;
            }
        }
    }

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
