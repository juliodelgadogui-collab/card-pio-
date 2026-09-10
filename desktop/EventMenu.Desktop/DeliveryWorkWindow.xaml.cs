using System.Diagnostics;
using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class DeliveryWorkWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly EventMenuApiClient _mainApi;
    private bool _loading;

    public bool OperationChanged { get; private set; }

    public DeliveryWorkWindow(SecureSessionStore store)
    {
        _api = new OperationalActionsApiClient(store);
        _mainApi = new EventMenuApiClient(store);
        InitializeComponent();
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) =>
        {
            _api.Dispose();
            _mainApi.Dispose();
        };
    }

    private DeliveryMineItem? Selected => DeliveryGrid.SelectedItem as DeliveryMineItem;

    private async Task LoadAsync(int? selectOrderId = null)
    {
        if (_loading) return;
        _loading = true;
        SetActions(false);
        HandoffButton.IsEnabled = false;
        FooterStatusText.Text = "Atualizando entregas...";
        try
        {
            var progressTask = _api.DeliveryMineAsync();
            var ordersTask = _mainApi.OperationalOrdersAsync();
            var cashTask = _api.DeliveryCashOutstandingAsync();
            await Task.WhenAll(progressTask, ordersTask, cashTask);

            var progress = (await progressTask).Progress;
            var orders = (await ordersTask).Orders.ToDictionary(x => x.Id);
            foreach (var item in progress)
            {
                if (!orders.TryGetValue(item.OrderId, out var order)) continue;
                item.OrderStatus = string.IsNullOrWhiteSpace(item.OrderStatus) ? order.Status : item.OrderStatus;
                item.PaymentStatus = order.PaymentStatus;
                item.CustomerName = order.CustomerName ?? "";
                item.CustomerPhone = order.CustomerPhone ?? "";
                item.DeliveryAddress = order.DeliveryAddress ?? "";
                item.TotalCents = order.TotalCents;
            }

            DeliveryGrid.ItemsSource = progress;
            DeliveryGrid.SelectedItem = selectOrderId.HasValue
                ? progress.FirstOrDefault(x => x.OrderId == selectOrderId.Value)
                : progress.FirstOrDefault();

            var balance = (await cashTask).Cash;
            HandoffButton.IsEnabled = true;
            FooterStatusText.Text = progress.Count == 0
                ? balance is { OutstandingCents: > 0 }
                    ? $"Nenhuma entrega ativa. Há {balance.OutstandingDisplay} para repassar ao caixa."
                    : "Nenhuma entrega atribuída a este usuário."
                : balance is { OutstandingCents: > 0 }
                    ? $"{progress.Count} entrega(s) • {balance.OutstandingDisplay} aguardando repasse ao caixa."
                    : $"{progress.Count} entrega(s) vinculada(s) ao seu turno.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
            HandoffButton.IsEnabled = true;
        }
        finally
        {
            _loading = false;
            RenderSelected();
        }
    }

    private void DeliveryGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RenderSelected();

    private void RenderSelected()
    {
        var item = Selected;
        if (item is null)
        {
            OrderTitleText.Text = "Selecione uma entrega";
            CustomerText.Text = "";
            AddressText.Text = "";
            StageText.Text = "";
            SetActions(false);
            return;
        }

        OrderTitleText.Text = $"Pedido #{item.OrderId} • {item.TotalDisplay}";
        CustomerText.Text = string.IsNullOrWhiteSpace(item.CustomerName)
            ? "Cliente não informado"
            : string.IsNullOrWhiteSpace(item.CustomerPhone) ? item.CustomerName : $"{item.CustomerName} • {item.CustomerPhone}";
        AddressText.Text = string.IsNullOrWhiteSpace(item.DeliveryAddress) ? "Endereço não informado" : item.DeliveryAddress;
        StageText.Text = $"{item.StageDisplay} • pagamento {PaymentLabel(item.PaymentStatus)}";

        var active = !_loading && string.IsNullOrWhiteSpace(item.CompletedAt) && item.OrderStatus != "cancelled";
        PickupButton.IsEnabled = active && string.IsNullOrWhiteSpace(item.PickedUpAt);
        StartRouteButton.IsEnabled = active && !string.IsNullOrWhiteSpace(item.PickedUpAt) && string.IsNullOrWhiteSpace(item.RouteStartedAt);
        ArriveButton.IsEnabled = active && !string.IsNullOrWhiteSpace(item.RouteStartedAt) && string.IsNullOrWhiteSpace(item.ArrivedAt);
        var canReceive = active
            && !string.IsNullOrWhiteSpace(item.ArrivedAt)
            && item.OrderStatus == "out_for_delivery"
            && item.PaymentStatus is "unpaid" or "failed";
        ReceivePixButton.IsEnabled = canReceive;
        ReceiveCashButton.IsEnabled = canReceive;
        CompleteButton.IsEnabled = active && !string.IsNullOrWhiteSpace(item.ArrivedAt) && string.IsNullOrWhiteSpace(item.CompletedAt);
        TrackingButton.IsEnabled = IsSafeTrackingUrl(item.TrackingUrl);
    }

    private void SetActions(bool enabled)
    {
        PickupButton.IsEnabled = enabled;
        StartRouteButton.IsEnabled = enabled;
        ArriveButton.IsEnabled = enabled;
        ReceivePixButton.IsEnabled = enabled;
        ReceiveCashButton.IsEnabled = enabled;
        CompleteButton.IsEnabled = enabled;
        TrackingButton.IsEnabled = enabled;
    }

    private async Task MutateAsync(string action)
    {
        if (_loading || Selected is not { } item) return;
        _loading = true;
        SetActions(false);
        try
        {
            FooterStatusText.Text = action switch
            {
                "pickup" => "Confirmando retirada...",
                "start" => "Iniciando rota...",
                "arrive" => "Registrando chegada...",
                "complete" => "Concluindo entrega...",
                _ => "Atualizando entrega..."
            };

            switch (action)
            {
                case "pickup": await _api.DeliveryPickupAsync(item.OrderId); break;
                case "start": await _api.DeliveryStartRouteAsync(item.OrderId); break;
                case "arrive": await _api.DeliveryArriveAsync(item.OrderId); break;
                case "complete": await _api.DeliveryCompleteAsync(item.OrderId); break;
                default: return;
            }
            OperationChanged = true;
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
        await LoadAsync(item.OrderId);
    }

    private async void PickupButton_Click(object sender, RoutedEventArgs e) => await MutateAsync("pickup");

    private async void StartRouteButton_Click(object sender, RoutedEventArgs e)
    {
        if (MessageBox.Show("Iniciar a rota deste pedido? O acompanhamento por GPS só terá posição em tempo real se o app do entregador estiver com localização ativa.", "Entrega", MessageBoxButton.YesNo, MessageBoxImage.Information) == MessageBoxResult.Yes)
            await MutateAsync("start");
    }

    private async void ArriveButton_Click(object sender, RoutedEventArgs e) => await MutateAsync("arrive");

    private async void ReceivePixButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || Selected is not { } item) return;
        if (string.IsNullOrWhiteSpace(item.ArrivedAt))
        {
            FooterStatusText.Text = "Marque Cheguei antes de receber o pagamento.";
            return;
        }
        if (item.PaymentStatus is not ("unpaid" or "failed"))
        {
            FooterStatusText.Text = item.PaymentStatus == "paid"
                ? "Este pedido já está pago."
                : "Já existe uma cobrança em andamento para este pedido.";
            return;
        }

        var window = new PixPaymentWindow(_mainApi, item.OrderId, item.TotalCents) { Owner = this };
        window.ShowDialog();
        if (window.PaymentConfirmed)
        {
            OperationChanged = true;
            FooterStatusText.Text = "Pagamento por Pix confirmado.";
            await LoadAsync(item.OrderId);
        }
    }

    private async void ReceiveCashButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || Selected is not { } item) return;
        if (string.IsNullOrWhiteSpace(item.ArrivedAt))
        {
            FooterStatusText.Text = "Marque Cheguei antes de receber dinheiro do cliente.";
            return;
        }
        if (item.PaymentStatus is not ("unpaid" or "failed"))
        {
            FooterStatusText.Text = item.PaymentStatus == "paid"
                ? "Este pedido já está pago."
                : "Há uma cobrança eletrônica em andamento. Aguarde antes de receber em dinheiro.";
            return;
        }

        var window = new DeliveryCashReceiveWindow(_api, item.OrderId, item.TotalCents) { Owner = this };
        if (window.ShowDialog() == true && window.PaymentChanged)
        {
            OperationChanged = true;
            FooterStatusText.Text = window.Receipt is { } receipt && receipt.ChangeCents > 0
                ? $"Pagamento confirmado. Troco: {receipt.ChangeDisplay}."
                : "Pagamento em dinheiro confirmado.";
            await LoadAsync(item.OrderId);
        }
    }

    private async void CompleteButton_Click(object sender, RoutedEventArgs e)
    {
        if (MessageBox.Show("Confirmar que a entrega foi concluída?", "Entrega", MessageBoxButton.YesNo, MessageBoxImage.Question) == MessageBoxResult.Yes)
            await MutateAsync("complete");
    }

    private void TrackingButton_Click(object sender, RoutedEventArgs e)
    {
        var url = Selected?.TrackingUrl;
        if (!IsSafeTrackingUrl(url)) return;
        try
        {
            Process.Start(new ProcessStartInfo(url!) { UseShellExecute = true });
        }
        catch
        {
            FooterStatusText.Text = "Não foi possível abrir o acompanhamento no navegador.";
        }
    }

    private async void HandoffButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading) return;
        var window = new DeliveryCashHandoffWindow(_api) { Owner = this };
        window.ShowDialog();
        if (window.HandoffChanged) OperationChanged = true;
        await LoadAsync(Selected?.OrderId);
    }

    private static bool IsSafeTrackingUrl(string? value) =>
        Uri.TryCreate(value, UriKind.Absolute, out var uri) && uri.Scheme == Uri.UriSchemeHttps;

    private static string PaymentLabel(string status) => status switch
    {
        "paid" => "pago",
        "partially_paid" => "parcial",
        "pending" => "pendente",
        "unpaid" => "não pago",
        "failed" => "falhou",
        "refunded" => "estornado",
        _ => status
    };

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync(Selected?.OrderId);
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível atualizar a entrega. Tente novamente."
            : message;
    }
}
