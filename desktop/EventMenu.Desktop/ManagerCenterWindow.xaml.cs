using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ManagerCenterWindow : Window
{
    private readonly SecureSessionStore _store;
    private readonly OperationalActionsApiClient _api;
    private readonly bool _canAssignDelivery;
    private readonly bool _canReopen;
    private readonly bool _canDiscountRequest;
    private readonly bool _canCancellationRequest;
    private List<DeliveryUserOption> _deliveryUsers = new();
    private bool _loading;

    public bool OperationChanged { get; private set; }

    public ManagerCenterWindow(
        SecureSessionStore store,
        bool canAssignDelivery,
        bool canReopen,
        bool canDiscountRequest,
        bool canCancellationRequest)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _canAssignDelivery = canAssignDelivery;
        _canReopen = canReopen;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        InitializeComponent();
        ReopenTab.Visibility = canReopen ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync()
    {
        if (_loading) return;
        _loading = true;
        FooterStatusText.Text = "Atualizando visão gerencial...";
        TransferDeliveryButton.IsEnabled = false;
        ReopenButton.IsEnabled = false;
        try
        {
            var overviewTask = _api.ManagerOverviewAsync();
            var detailsTask = _api.ManagerDetailsAsync();
            Task<ManagerReopenCandidatesResponse>? reopenTask = _canReopen ? _api.ManagerReopenCandidatesAsync() : null;
            Task<DeliveryUsersResponse>? usersTask = _canAssignDelivery ? _api.DeliveryUsersAsync() : null;

            await Task.WhenAll(new Task[] { overviewTask, detailsTask }
                .Concat(reopenTask is null ? Array.Empty<Task>() : new Task[] { reopenTask })
                .Concat(usersTask is null ? Array.Empty<Task>() : new Task[] { usersTask }));

            var overview = (await overviewTask).Overview ?? new ManagerOverview();
            OrdersNowText.Text = overview.OrdersNow.ToString();
            DelayedText.Text = overview.KitchenDelayed.ToString();
            ReadyText.Text = overview.ReadyOrders.ToString();
            UnassignedText.Text = overview.UnassignedDelivery.ToString();
            PaymentsText.Text = overview.PendingPayments.ToString();
            RevenueText.Text = overview.RevenueTodayDisplay;
            AlertsGrid.ItemsSource = overview.Alerts;

            var details = (await detailsTask).Details ?? new ManagerDetails();
            ProblemOrdersGrid.ItemsSource = details.ProblemOrders;
            CashSessionsGrid.ItemsSource = details.CashSessions;
            DeliveryShiftsGrid.ItemsSource = details.DeliveryShifts;

            if (reopenTask is not null)
                ReopenGrid.ItemsSource = (await reopenTask).Orders;

            if (usersTask is not null)
            {
                _deliveryUsers = (await usersTask).DeliveryUsers
                    .Where(x => x.Id > 0)
                    .GroupBy(x => x.Id)
                    .Select(x => x.First())
                    .ToList();
                DeliverySelector.ItemsSource = _deliveryUsers;
            }
            else
            {
                _deliveryUsers.Clear();
                DeliverySelector.ItemsSource = null;
            }

            FooterStatusText.Text = $"Atualizado • {overview.CashOpen} caixa(s) aberto(s) • {overview.DeliveryOnline} entregador(es) em turno.";
            RefreshProblemActions();
            RefreshReopenAction();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
            RefreshProblemActions();
            RefreshReopenAction();
        }
    }

    private void RefreshProblemActions()
    {
        var selected = ProblemOrdersGrid.SelectedItem as ManagerProblemOrder;
        var deliveryRelated = selected is not null && (selected.Channel == "delivery" || selected.ProblemType.Contains("delivery", StringComparison.OrdinalIgnoreCase) || selected.ProblemType.Contains("entrega", StringComparison.OrdinalIgnoreCase));
        DeliverySelector.IsEnabled = !_loading && _canAssignDelivery && deliveryRelated && _deliveryUsers.Count > 0;
        TransferDeliveryButton.IsEnabled = DeliverySelector.IsEnabled && DeliverySelector.SelectedItem is DeliveryUserOption;
        ProblemHintText.Text = selected is null
            ? "Selecione um pedido para ver as ações disponíveis."
            : deliveryRelated && _canAssignDelivery
                ? "Você pode abrir o pedido ou transferir a entrega para outro entregador ativo."
                : "Abra o pedido para analisar os detalhes e o histórico.";

        if (selected?.DeliveryUserId is int currentId && currentId > 0)
            DeliverySelector.SelectedItem = _deliveryUsers.FirstOrDefault(x => x.Id == currentId);
    }

    private void RefreshReopenAction()
    {
        var selected = ReopenGrid.SelectedItem as ManagerReopenCandidate;
        ReopenButton.IsEnabled = !_loading && _canReopen && selected?.Eligible == true;
    }

    private async void TransferDeliveryButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canAssignDelivery || ProblemOrdersGrid.SelectedItem is not ManagerProblemOrder order || DeliverySelector.SelectedItem is not DeliveryUserOption user) return;
        if (order.DeliveryUserId == user.Id)
        {
            FooterStatusText.Text = "Este entregador já está vinculado ao pedido.";
            return;
        }
        if (MessageBox.Show($"Transferir o pedido #{order.Id} para {user.Name}?", "Gerência", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;

        _loading = true;
        TransferDeliveryButton.IsEnabled = false;
        try
        {
            await _api.ManagerTransferDeliveryAsync(order.Id, user.Id);
            OperationChanged = true;
            FooterStatusText.Text = $"Pedido #{order.Id} transferido para {user.Name}.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
        await LoadAsync();
    }

    private async void ReopenButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canReopen || ReopenGrid.SelectedItem is not ManagerReopenCandidate order || !order.Eligible) return;
        var reason = ReopenReasonBox.Text.Trim();
        if (reason.Length < 3)
        {
            FooterStatusText.Text = "Informe o motivo da reabertura.";
            ReopenReasonBox.Focus();
            return;
        }
        if (MessageBox.Show($"Reabrir o pedido #{order.Id}? Esta ação ficará registrada na auditoria.", "Gerência", MessageBoxButton.YesNo, MessageBoxImage.Warning) != MessageBoxResult.Yes) return;

        _loading = true;
        ReopenButton.IsEnabled = false;
        try
        {
            await _api.ManagerReopenOrderAsync(order.Id, reason);
            OperationChanged = true;
            ReopenReasonBox.Clear();
            FooterStatusText.Text = $"Pedido #{order.Id} reaberto.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
        await LoadAsync();
    }

    private void ProblemOrdersGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshProblemActions();
    private void ReopenGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshReopenAction();
    private void ProblemOrdersGrid_MouseDoubleClick(object sender, MouseButtonEventArgs e) => OpenSelectedProblemOrder();
    private void OpenProblemOrderButton_Click(object sender, RoutedEventArgs e) => OpenSelectedProblemOrder();

    private void OpenSelectedProblemOrder()
    {
        if (ProblemOrdersGrid.SelectedItem is not ManagerProblemOrder order) return;
        var window = new OrderDetailsWindow(_store, order.Id, _canAssignDelivery, _canDiscountRequest, _canCancellationRequest) { Owner = this };
        window.ShowDialog();
        if (window.OrderChanged)
        {
            OperationChanged = true;
            _ = LoadAsync();
        }
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
