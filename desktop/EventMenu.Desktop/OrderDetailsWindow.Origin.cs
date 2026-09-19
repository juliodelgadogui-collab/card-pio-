using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class OrderDetailsWindow
{
    private Border? _eventMenuDeliveryOriginBadge;

    protected override void OnInitialized(EventArgs e)
    {
        base.OnInitialized(e);
        EnsureOrderOriginBadge();
        Loaded += async (_, _) => await LoadOrderOriginAsync();
    }

    private void EnsureOrderOriginBadge()
    {
        if (_eventMenuDeliveryOriginBadge is not null || TitleText?.Parent is not StackPanel header) return;

        var primary = Application.Current.TryFindResource("PrimaryBrush") as Brush
                      ?? new SolidColorBrush(Color.FromRgb(79, 70, 229));
        var surface = Application.Current.TryFindResource("PrimarySoftBrush") as Brush
                      ?? new SolidColorBrush(Color.FromRgb(238, 242, 255));

        _eventMenuDeliveryOriginBadge = new Border
        {
            Background = surface,
            BorderBrush = primary,
            BorderThickness = new Thickness(1),
            CornerRadius = new CornerRadius(999),
            Padding = new Thickness(10, 4, 10, 4),
            Margin = new Thickness(0, 8, 0, 0),
            HorizontalAlignment = HorizontalAlignment.Left,
            Visibility = Visibility.Collapsed,
            Child = new TextBlock
            {
                Text = "EventMenu Delivery",
                Foreground = primary,
                FontWeight = FontWeights.SemiBold,
                FontSize = 12,
            }
        };

        header.Children.Add(_eventMenuDeliveryOriginBadge);
    }

    private async Task LoadOrderOriginAsync()
    {
        if (_eventMenuDeliveryOriginBadge is null || _orderId < 1) return;
        try
        {
            using var api = new EventMenuApiClient(_store);
            var response = await api.OrderSourcesAsync(new[] { _orderId });
            var source = response.Orders.FirstOrDefault(meta => meta.OrderId == _orderId)?.OrderSource ?? "";
            _eventMenuDeliveryOriginBadge.Visibility = source.Equals("EVENTMENU_DELIVERY", StringComparison.OrdinalIgnoreCase)
                ? Visibility.Visible
                : Visibility.Collapsed;
        }
        catch
        {
            // A origem é apenas um metadado visual e nunca pode impedir a operação do pedido.
            _eventMenuDeliveryOriginBadge.Visibility = Visibility.Collapsed;
        }
    }
}
