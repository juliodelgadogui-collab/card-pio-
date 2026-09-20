using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class OrderDetailsWindow
{
    private Border? _eventMenuDeliveryOriginBadge;
    private Border? _paymentPreferenceBadge;
    private TextBlock? _paymentPreferenceText;

    protected override void OnInitialized(EventArgs e)
    {
        base.OnInitialized(e);
        EnsureOrderMetadataBadges();
        Loaded += async (_, _) => await LoadOrderMetadataAsync();
    }

    private void EnsureOrderMetadataBadges()
    {
        if (TitleText?.Parent is not StackPanel header) return;

        var primary = Application.Current.TryFindResource("PrimaryBrush") as Brush
                      ?? new SolidColorBrush(Color.FromRgb(79, 70, 229));
        var surface = Application.Current.TryFindResource("PrimarySoftBrush") as Brush
                      ?? new SolidColorBrush(Color.FromRgb(238, 242, 255));

        if (_eventMenuDeliveryOriginBadge is null)
        {
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

        if (_paymentPreferenceBadge is null)
        {
            _paymentPreferenceText = new TextBlock
            {
                Foreground = primary,
                FontWeight = FontWeights.SemiBold,
                FontSize = 12,
                TextWrapping = TextWrapping.Wrap,
            };
            _paymentPreferenceBadge = new Border
            {
                Background = surface,
                BorderBrush = primary,
                BorderThickness = new Thickness(1),
                CornerRadius = new CornerRadius(999),
                Padding = new Thickness(10, 4, 10, 4),
                Margin = new Thickness(0, 6, 0, 0),
                HorizontalAlignment = HorizontalAlignment.Left,
                Visibility = Visibility.Collapsed,
                Child = _paymentPreferenceText,
                ToolTip = "Preferência informada pelo cliente. O status Pago continua dependendo da confirmação do servidor.",
            };
            header.Children.Add(_paymentPreferenceBadge);
        }
    }

    private async Task LoadOrderMetadataAsync()
    {
        if (_orderId < 1) return;
        try
        {
            using var api = new EventMenuApiClient(_store);
            var response = await api.OrderSourcesAsync(new[] { _orderId });
            var meta = response.Orders.FirstOrDefault(item => item.OrderId == _orderId);
            var source = meta?.OrderSource ?? "";

            if (_eventMenuDeliveryOriginBadge is not null)
            {
                _eventMenuDeliveryOriginBadge.Visibility = source.Equals("EVENTMENU_DELIVERY", StringComparison.OrdinalIgnoreCase)
                    ? Visibility.Visible
                    : Visibility.Collapsed;
            }

            var paymentPreference = meta?.PaymentPreferenceDisplay ?? "";
            if (_paymentPreferenceBadge is not null && _paymentPreferenceText is not null)
            {
                if (string.IsNullOrWhiteSpace(paymentPreference))
                {
                    _paymentPreferenceBadge.Visibility = Visibility.Collapsed;
                }
                else
                {
                    _paymentPreferenceText.Text = $"Forma escolhida: {paymentPreference}";
                    _paymentPreferenceBadge.Visibility = Visibility.Visible;
                }
            }
        }
        catch
        {
            // Metadados visuais nunca podem impedir a operação do pedido.
            if (_eventMenuDeliveryOriginBadge is not null) _eventMenuDeliveryOriginBadge.Visibility = Visibility.Collapsed;
            if (_paymentPreferenceBadge is not null) _paymentPreferenceBadge.Visibility = Visibility.Collapsed;
        }
    }
}
