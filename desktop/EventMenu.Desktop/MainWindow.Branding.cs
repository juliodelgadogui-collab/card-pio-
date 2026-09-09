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
            else ApplyProfessionalShell();
        }
        finally { _brandLoading = false; }
    }

    private void ApplyTenantBrand(TenantBrand brand)
    {
        var displayName = string.IsNullOrWhiteSpace(brand.DisplayName) ? _currentUser?.TenantName : brand.DisplayName.Trim();
        if (string.IsNullOrWhiteSpace(displayName)) displayName = "Minha empresa";
        TenantNameText.Text = displayName;
        Title = $"{displayName} • EventMenu";
        ApplyProfessionalShell();

        foreach (var text in Descendants<TextBlock>(ShellPanel))
        {
            if (text.Text == "EventMenu")
            {
                text.Text = displayName;
                break;
            }
        }
    }

    private void ApplyProfessionalShell()
    {
        ShellPanel.Background = new SolidColorBrush(Color.FromRgb(246, 247, 251));
        if (PosNavButton.Parent is not StackPanel menu || menu.Parent is not Border sidebar) return;

        sidebar.Background = new SolidColorBrush(Color.FromRgb(17, 24, 39));
        foreach (var button in menu.Children.OfType<Button>())
        {
            button.Background = Brushes.Transparent;
            button.Foreground = new SolidColorBrush(Color.FromRgb(226, 232, 240));
            button.HorizontalContentAlignment = HorizontalAlignment.Left;
            button.FontWeight = FontWeights.SemiBold;
            button.Padding = new Thickness(12, 10, 12, 10);
            button.Margin = new Thickness(0, 0, 0, 4);
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
