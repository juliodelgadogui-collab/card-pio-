using System.Windows;
using System.Windows.Controls;
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
    private readonly bool _canAccept;
    private readonly bool _canLoyalty;
    private OperationalOrderDetail? _order;
    private LoyaltyOrderSummary? _loyalty;
    private bool _loading;

    public bool OrderChanged { get; private set; }

    public OrderDetailsWindow(
        SecureSessionStore store,
        int orderId,
        bool canAssignDelivery,
        bool canDiscountRequest,
        bool canCancellationRequest,
        bool canAccept = false,
        bool canLoyalty = false)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _orderId = orderId;
        _canAssignDelivery = canAssignDelivery;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        _canAccept = canAccept;
        _canLoyalty = canLoyalty;
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
        AcceptOrderButton.IsEnabled = false;
        try
        {
            var response = await _api.OrderAsync(_orderId);
            _order = response.Order ?? throw new InvalidOperationException("Pedido não encontrado.");
            ItemsGrid.ItemsSource = response.Items;
            RenderOrder(_order);
            RefreshSensitiveActions(_order);

            await LoadAdvancedAsync();

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
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
            if (_order is not null) RefreshSensitiveActions(_order);
        }
    }

    private async Task LoadAdvancedAsync()
    {
        try
        {
            var response = await _api.OrderOpsDetailAsync(_orderId);
            var detail = response.Detail;
            TimelineGrid.ItemsSource = detail?.Timeline ?? new List<OrderTimelineItem>();
            if (detail?.Items.Count > 0) ItemsGrid.ItemsSource = detail.Items;

            if (detail?.Customer is { } customer)
            {
                if (!string.IsNullOrWhiteSpace(customer.Name)) CustomerText.Text = customer.Name;
                if (!string.IsNullOrWhiteSpace(customer.Phone)) PhoneText.Text = customer.Phone;
            }

            _loyalty = detail?.Loyalty ?? response.Loyalty;
            RenderLoyalty();
        }
        catch
        {
            TimelineGrid.ItemsSource = Array.Empty<OrderTimelineItem>();
            _loyalty = null;
            LoyaltyCard.Visibility = Visibility.Collapsed;
        }
    }

    private void RenderLoyalty()
    {
        if (!_canLoyalty || _loyalty is null || !_loyalty.Enabled || _loyalty.CustomerId < 1)
        {
            LoyaltyCard.Visibility = Visibility.Collapsed;
            return;
        }

        LoyaltyCard.Visibility = Visibility.Visible;
        LoyaltyBalanceText.Text = $"Saldo: {_loyalty.Balance} ponto(s) • disponível: {_loyalty.Available} • reservado: {_loyalty.Reserved}.";
        if (_loyalty.OrderReservation is { } reservation)
        {
            LoyaltyReservationText.Text = $"Este pedido reservou {reservation.Points} ponto(s), gerando {reservation.DiscountDisplay} de benefício.";
            LoyaltyActionPanel.Visibility = Visibility.Collapsed;
            RemoveLoyaltyButton.Visibility = Visibility.Visible;
            RemoveLoyaltyButton.IsEnabled = !_loading;
        }
        else
        {
            LoyaltyReservationText.Text = _loyalty.RedeemValueCents > 0
                ? $"A partir de {_loyalty.MinRedeemPoints} pontos. Regra atual: {_loyalty.RedeemPoints} pontos = {OperationalDisplay.Money(_loyalty.RedeemValueCents)}."
                : $"A partir de {_loyalty.MinRedeemPoints} pontos, conforme a regra da empresa.";
            LoyaltyActionPanel.Visibility = Visibility.Visible;
            RemoveLoyaltyButton.Visibility = Visibility.Collapsed;
            ApplyLoyaltyButton.IsEnabled = !_loading && _loyalty.Available >= _loyalty.MinRedeemPoints;
            if (string.IsNullOrWhiteSpace(LoyaltyPointsBox.Text) && _loyalty.Available >= _loyalty.MinRedeemPoints)
                LoyaltyPointsBox.Text = Math.Min(_loyalty.Available, Math.Max(_loyalty.MinRedeemPoints, _loyalty.RedeemPoints)).ToString();
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
            "bar" or "event_bar" => "Consumo no evento",
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

        var noPaymentStarted = order.PaymentStatus == "unpaid";
        DiscountRequestButton.IsEnabled = !_loading && open && noPaymentStarted;
        CancellationRequestButton.IsEnabled = !_loading && open && noPaymentStarted;
        ReceiptButton.IsEnabled = !_loading && order.Id > 0;

        AcceptOrderButton.Visibility = _canAccept && order.Status == "pending" ? Visibility.Visible : Visibility.Collapsed;
        AcceptOrderButton.IsEnabled = !_loading && _canAccept && order.Status == "pending";
        RenderLoyalty();
    }

    private async Task LoadDeliveryUsersAsync(int? selectedId)
    {
        var response = await _api.DeliveryUsersAsync();
        var options = response.DeliveryUsers
            .Where(x => x.Id > 0)
            .GroupBy(x => x.Id)
            .Select(x => x.First())
            .ToList();

        DeliverySelector.SelectionChanged -= DeliverySelector_SelectionChanged;
        DeliverySelector.ItemsSource = options;
        DeliverySelector.SelectedItem = selectedId.HasValue
            ? options.FirstOrDefault(x => x.Id == selectedId.Value)
            : null;
        DeliverySelector.SelectionChanged += DeliverySelector_SelectionChanged;
        DeliveryAssignmentPanel.Visibility = Visibility.Visible;

        if (options.Count == 0)
        {
            CurrentDeliveryText.Text = "Nenhum entregador disponível nesta unidade. O entregador precisa iniciar o turno de Delivery.";
            AssignDeliveryButton.IsEnabled = false;
        }
        else
        {
            AssignDeliveryButton.IsEnabled = DeliverySelector.SelectedItem is DeliveryUserOption;
        }
    }

    private void DeliverySelector_SelectionChanged(object sender, SelectionChangedEventArgs e) =>
        AssignDeliveryButton.IsEnabled = !_loading && DeliverySelector.SelectedItem is DeliveryUserOption;

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
            FooterStatusText.Text = Friendly(ex.Message);
            AssignDeliveryButton.IsEnabled = DeliverySelector.SelectedItem is DeliveryUserOption;
        }
    }

    private async void AcceptOrderButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canAccept || _order?.Status != "pending") return;
        AcceptOrderButton.IsEnabled = false;
        try
        {
            await _api.AcceptOrderAsync(_order.Id);
            OrderChanged = true;
            FooterStatusText.Text = "Pedido aceito e enviado ao fluxo operacional.";
            await LoadAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
    }

    private async void ApplyLoyaltyButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canLoyalty || _order is null || _loyalty is null) return;
        if (!int.TryParse(LoyaltyPointsBox.Text.Trim(), out var points) || points < _loyalty.MinRedeemPoints || points > _loyalty.Available)
        {
            FooterStatusText.Text = $"Informe entre {_loyalty.MinRedeemPoints} e {_loyalty.Available} pontos disponíveis.";
            LoyaltyPointsBox.Focus();
            return;
        }
        ApplyLoyaltyButton.IsEnabled = false;
        try
        {
            await _api.ApplyLoyaltyAsync(_order.Id, points);
            OrderChanged = true;
            FooterStatusText.Text = "Pontos reservados e valor do pedido recalculado pelo servidor.";
            await LoadAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
    }

    private async void RemoveLoyaltyButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canLoyalty || _order is null || _loyalty?.OrderReservation is null) return;
        if (MessageBox.Show("Remover os pontos reservados deste pedido?", "Fidelidade", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        RemoveLoyaltyButton.IsEnabled = false;
        try
        {
            await _api.RemoveLoyaltyAsync(_order.Id);
            OrderChanged = true;
            FooterStatusText.Text = "Reserva de pontos removida.";
            await LoadAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
    }

    private async void DiscountRequestButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canDiscountRequest || _order is null || _order.Status is "completed" or "cancelled" || _order.PaymentStatus != "unpaid") return;
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
        if (!_canCancellationRequest || _order is null || _order.Status is "completed" or "cancelled" || _order.PaymentStatus != "unpaid") return;
        var window = new OrderRequestWindow(_store, _order.Id, _order.TotalCents, false) { Owner = this };
        if (window.ShowDialog() == true && window.Submitted)
        {
            FooterStatusText.Text = "Cancelamento enviado para autorização.";
            OrderChanged = true;
            await LoadAsync();
        }
    }

    private void ReceiptButton_Click(object sender, RoutedEventArgs e)
    {
        if (_order is null) return;
        var window = new ReceiptWindow(_store, _order.Id) { Owner = this };
        window.ShowDialog();
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir a operação. Atualize e tente novamente."
            : message;
    }
}
