using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class OrderDetailsWindow : Window
{
    private readonly SecureSessionStore _store;
    private readonly OperationalActionsApiClient _api;
    private readonly int _orderId;
    private readonly bool _canAssignDelivery;
    private readonly bool _canDiscountRequest;
    private readonly bool _canCancellationRequest;
    private OperationalOrderDetail? _order;
    private bool _loading;

    public bool OrderChanged { get; private set; }

    public OrderDetailsWindow(
        SecureSessionStore store,
        int orderId,
        bool canAssignDelivery,
        bool canDiscountRequest,
        bool canCancellationRequest)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _orderId = orderId;
        _canAssignDelivery = canAssignDelivery;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        InitializeComponent();
        TitleText.Text = $"Pedido #{orderId}";
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync()
    {
        if (_loading) return;
        _loading = true;
        FooterStatusText.Text = "Carregando pedido...";
        AssignDeliveryButton.IsEnabled = false;
        try
        {
            var response = await _api.OrderAsync(_orderId);
            _order = response.Order ?? throw new InvalidOperationException("Pedido não encontrado.");
            ItemsGrid.ItemsSource = response.Items;
            RenderOrder(_order);
            RefreshSensitiveActions(_order);

            if (_order.Channel == "delivery")
            {
                DeliveryCard.Visibility = Visibility.Visible;
                if (_canAssignDelivery && _order.Status == "ready")
                {
                    await LoadDeliveryUsersAsync(_order.AssignedDeliveryUserId);
                }
                else
                {
                    DeliveryAssignmentPanel.Visibility = Visibility.Collapsed;
                    if (_canAssignDelivery && _order.AssignedDeliveryUserId is null && _order.Status is not ("completed" or "cancelled" or "out_for_delivery"))
                        CurrentDeliveryText.Text += " • A escolha do entregador será liberada quando o pedido estiver pronto.";
                }
            }
            else
            {
                DeliveryCard.Visibility = Visibility.Collapsed;
            }

            FooterStatusText.Text = $"Atualizado • {_order.CreatedDisplay}";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
        }
        finally
        {
            _loading = false;
        }
    }

    private void RenderOrder(OperationalOrderDetail order)
    {
        TitleText.Text = $"Pedido #{order.Id}";
        SubtitleText.Text = $"{order.ChannelDisplay} • criado em {order.CreatedDisplay}";
        TotalText.Text = order.TotalDisplay;
        StatusText.Text = order.StatusDisplay;
        PaymentText.Text = order.PaymentDisplay;
        ChannelText.Text = order.ChannelDisplay;

        CustomerText.Text = string.IsNullOrWhiteSpace(order.CustomerName) ? "Cliente não informado" : order.CustomerName;
        PhoneText.Text = string.IsNullOrWhiteSpace(order.CustomerPhone) ? "Telefone não informado" : order.CustomerPhone;

        LocationText.Text = order.Channel switch
        {
            "delivery" => string.IsNullOrWhiteSpace(order.DeliveryAddress) ? "Endereço não informado" : order.DeliveryAddress,
            "table" => !string.IsNullOrWhiteSpace(order.TableName) ? order.TableName : order.TableId is > 0 ? $"Mesa #{order.TableId}" : "Mesa não identificada",
            _ => ""
        };

        CurrentDeliveryText.Text = string.IsNullOrWhiteSpace(order.DeliveryName)
            ? "Ainda sem entregador"
            : $"Entregador: {order.DeliveryName}";

        NotesText.Text = string.IsNullOrWhiteSpace(order.Notes) ? "Nenhuma observação." : order.Notes;
    }

    private void RefreshSensitiveActions(OperationalOrderDetail order)
    {
        var open = order.Status is not ("completed" or "cancelled");
        DiscountRequestButton.Visibility = _canDiscountRequest && open ? Visibility.Visible : Visibility.Collapsed;
        CancellationRequestButton.Visibility = _canCancellationRequest && open ? Visibility.Visible : Visibility.Collapsed;
        DiscountRequestButton.IsEnabled = open && order.PaymentStatus is not ("paid" or "refunded");
        CancellationRequestButton.IsEnabled = open && order.PaymentStatus is not "paid";
    }

    private async Task LoadDeliveryUsersAsync(int? selectedId)
    {
        var response = await _api.DeliveryUsersAsync();
        var options = response.DeliveryUsers
            .Where(x => x.Id > 0)
            .GroupBy(x => x.Id)
            .Select(x => x.First())
            .ToList();

        DeliverySelector.ItemsSource = options;
        DeliverySelector.SelectedItem = selectedId.HasValue
            ? options.FirstOrDefault(x => x.Id == selectedId.Value)
            : null;
        DeliveryAssignmentPanel.Visibility = Visibility.Visible;

        if (options.Count == 0)
        {
            CurrentDeliveryText.Text = "Nenhum entregador disponível nesta unidade. O entregador precisa iniciar o turno de Delivery.";
            AssignDeliveryButton.IsEnabled = false;
        }
        else
        {
            AssignDeliveryButton.IsEnabled = DeliverySelector.SelectedItem is DeliveryUserOption;
            DeliverySelector.SelectionChanged += (_, _) => AssignDeliveryButton.IsEnabled = DeliverySelector.SelectedItem is DeliveryUserOption;
        }
    }

    private async void AssignDeliveryButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canAssignDelivery || _order is null || DeliverySelector.SelectedItem is not DeliveryUserOption selected) return;
        if (_order.Status != "ready")
        {
            FooterStatusText.Text = "O pedido precisa estar pronto para escolher o entregador.";
            return;
        }

        AssignDeliveryButton.IsEnabled = false;
        FooterStatusText.Text = "Salvando entregador...";
        try
        {
            await _api.AssignDeliveryAsync(_order.Id, selected.Id);
            OrderChanged = true;
            FooterStatusText.Text = $"{selected.Name} atribuído ao pedido.";
            await LoadAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
            AssignDeliveryButton.IsEnabled = DeliverySelector.SelectedItem is DeliveryUserOption;
        }
    }

    private async void DiscountRequestButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canDiscountRequest || _order is null || _order.Status is "completed" or "cancelled") return;
        var window = new OrderRequestWindow(_store, _order.Id, _order.TotalCents, true) { Owner = this };
        if (window.ShowDialog() == true && window.Submitted)
        {
            FooterStatusText.Text = "Desconto enviado para autorização.";
            OrderChanged = true;
            await LoadAsync();
        }
    }

    private async void CancellationRequestButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canCancellationRequest || _order is null || _order.Status is "completed" or "cancelled") return;
        var window = new OrderRequestWindow(_store, _order.Id, _order.TotalCents, false) { Owner = this };
        if (window.ShowDialog() == true && window.Submitted)
        {
            FooterStatusText.Text = "Cancelamento enviado para autorização.";
            OrderChanged = true;
            await LoadAsync();
        }
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
