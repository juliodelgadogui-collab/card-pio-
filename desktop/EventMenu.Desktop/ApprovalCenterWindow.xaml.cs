using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ApprovalCenterWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly bool _canDiscountApprove;
    private readonly bool _canCancellationApprove;
    private bool _busy;

    public bool ApprovalChanged { get; private set; }

    public ApprovalCenterWindow(SecureSessionStore store, bool canDiscountApprove, bool canCancellationApprove)
    {
        _api = new OperationalActionsApiClient(store);
        _canDiscountApprove = canDiscountApprove;
        _canCancellationApprove = canCancellationApprove;
        InitializeComponent();

        DiscountTab.Visibility = _canDiscountApprove ? Visibility.Visible : Visibility.Collapsed;
        CancellationTab.Visibility = _canCancellationApprove ? Visibility.Visible : Visibility.Collapsed;
        if (!_canDiscountApprove && _canCancellationApprove) ApprovalTabs.SelectedItem = CancellationTab;

        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync()
    {
        if (_busy) return;
        _busy = true;
        SetBusy(true);
        StatusText.Text = "Atualizando aprovações...";
        try
        {
            if (_canDiscountApprove)
            {
                var discounts = await _api.PendingDiscountsAsync();
                DiscountGrid.ItemsSource = discounts.Requests;
                DiscountTab.Header = discounts.Requests.Count == 0 ? "Descontos" : $"Descontos ({discounts.Requests.Count})";
            }

            if (_canCancellationApprove)
            {
                var cancellations = await _api.PendingCancellationsAsync();
                CancellationGrid.ItemsSource = cancellations.Requests;
                CancellationTab.Header = cancellations.Requests.Count == 0 ? "Cancelamentos" : $"Cancelamentos ({cancellations.Requests.Count})";
            }

            StatusText.Text = "Lista atualizada.";
            RefreshSelectionState();
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            SetBusy(false);
            RefreshSelectionState();
        }
    }

    private void SetBusy(bool busy)
    {
        ApprovalTabs.IsEnabled = !busy;
    }

    private void RefreshSelectionState()
    {
        var discount = DiscountGrid.SelectedItem as DiscountRequestItem;
        ApproveDiscountButton.IsEnabled = !_busy && _canDiscountApprove && discount is not null;
        RejectDiscountButton.IsEnabled = ApproveDiscountButton.IsEnabled;
        DiscountSelectionText.Text = discount is null
            ? "Selecione uma solicitação de desconto."
            : $"Pedido #{discount.OrderId} • {discount.RequestedDisplay} • {discount.RequesterName}";

        var cancellation = CancellationGrid.SelectedItem as CancellationRequestItem;
        ApproveCancellationButton.IsEnabled = !_busy && _canCancellationApprove && cancellation is not null;
        RejectCancellationButton.IsEnabled = ApproveCancellationButton.IsEnabled;
        CancellationSelectionText.Text = cancellation is null
            ? "Selecione uma solicitação de cancelamento."
            : $"Pedido #{cancellation.OrderId} • {cancellation.TotalDisplay} • {cancellation.RequesterName}";
    }

    private async Task ApproveDiscountAsync()
    {
        if (_busy || DiscountGrid.SelectedItem is not DiscountRequestItem request) return;
        var answer = MessageBox.Show(
            $"Aprovar desconto de {request.RequestedDisplay} no pedido #{request.OrderId}?",
            "Aprovar desconto",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);
        if (answer != MessageBoxResult.Yes) return;

        await ExecuteDecisionAsync(
            () => _api.ApproveDiscountAsync(request.Id),
            $"Desconto do pedido #{request.OrderId} aprovado.");
    }

    private async Task RejectDiscountAsync()
    {
        if (_busy || DiscountGrid.SelectedItem is not DiscountRequestItem request) return;
        var reason = DiscountRejectReasonBox.Text.Trim();
        if (reason.Length < 3)
        {
            StatusText.Text = "Informe o motivo da recusa do desconto.";
            DiscountRejectReasonBox.Focus();
            return;
        }

        await ExecuteDecisionAsync(
            () => _api.RejectDiscountAsync(request.Id, reason),
            $"Desconto do pedido #{request.OrderId} recusado.");
        DiscountRejectReasonBox.Clear();
    }

    private async Task ApproveCancellationAsync()
    {
        if (_busy || CancellationGrid.SelectedItem is not CancellationRequestItem request) return;
        var answer = MessageBox.Show(
            $"Aprovar o cancelamento do pedido #{request.OrderId}?\n\nMotivo: {request.Reason}",
            "Aprovar cancelamento",
            MessageBoxButton.YesNo,
            MessageBoxImage.Warning);
        if (answer != MessageBoxResult.Yes) return;

        await ExecuteDecisionAsync(
            () => _api.ApproveCancellationAsync(request.Id),
            $"Cancelamento do pedido #{request.OrderId} aprovado.");
    }

    private async Task RejectCancellationAsync()
    {
        if (_busy || CancellationGrid.SelectedItem is not CancellationRequestItem request) return;
        var reason = CancellationRejectReasonBox.Text.Trim();
        if (reason.Length < 3)
        {
            StatusText.Text = "Informe o motivo da recusa do cancelamento.";
            CancellationRejectReasonBox.Focus();
            return;
        }

        await ExecuteDecisionAsync(
            () => _api.RejectCancellationAsync(request.Id, reason),
            $"Cancelamento do pedido #{request.OrderId} recusado.");
        CancellationRejectReasonBox.Clear();
    }

    private async Task ExecuteDecisionAsync(Func<Task<OperationPayloadResponse>> action, string successMessage)
    {
        _busy = true;
        SetBusy(true);
        StatusText.Text = "Registrando decisão...";
        try
        {
            await action();
            ApprovalChanged = true;
            StatusText.Text = successMessage;
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
            _busy = false;
            SetBusy(false);
            RefreshSelectionState();
            return;
        }

        _busy = false;
        SetBusy(false);
        await LoadAsync();
    }

    private void DiscountGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshSelectionState();
    private void CancellationGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshSelectionState();
    private async void ApproveDiscountButton_Click(object sender, RoutedEventArgs e) => await ApproveDiscountAsync();
    private async void RejectDiscountButton_Click(object sender, RoutedEventArgs e) => await RejectDiscountAsync();
    private async void ApproveCancellationButton_Click(object sender, RoutedEventArgs e) => await ApproveCancellationAsync();
    private async void RejectCancellationButton_Click(object sender, RoutedEventArgs e) => await RejectCancellationAsync();
    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
