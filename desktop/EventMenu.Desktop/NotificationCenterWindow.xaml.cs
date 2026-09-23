using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class NotificationCenterWindow : Window
{
    private readonly SecureSessionStore _store;
    private readonly OperationalActionsApiClient _api;
    private readonly bool _canAssignDelivery;
    private readonly bool _canDiscountRequest;
    private readonly bool _canCancellationRequest;
    private readonly bool _canDiscountApprove;
    private readonly bool _canCancellationApprove;
    private bool _busy;

    public bool ReadStateChanged { get; private set; }
    public bool OperationChanged { get; private set; }

    public NotificationCenterWindow(
        SecureSessionStore store,
        bool canAssignDelivery,
        bool canDiscountRequest,
        bool canCancellationRequest,
        bool canDiscountApprove,
        bool canCancellationApprove)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _canAssignDelivery = canAssignDelivery;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        _canDiscountApprove = canDiscountApprove;
        _canCancellationApprove = canCancellationApprove;
        InitializeComponent();
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync(int? keepSelectedId = null)
    {
        if (_busy) return;
        _busy = true;
        StatusText.Text = "Atualizando notificações...";
        try
        {
            var response = await _api.NotificationsAsync(150);
            var items = response.Notifications.Items;
            NotificationsGrid.ItemsSource = items;
            SubtitleText.Text = response.Notifications.UnreadCount == 0
                ? "Nenhum aviso novo neste turno."
                : $"{response.Notifications.UnreadCount} aviso(s) ainda não lido(s).";

            if (keepSelectedId.HasValue)
                NotificationsGrid.SelectedItem = items.FirstOrDefault(x => x.Id == keepSelectedId.Value);

            StatusText.Text = items.Count == 0 ? "Nenhuma notificação para exibir." : $"{items.Count} notificação(ões).";
            RenderSelection();
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            RenderSelection();
        }
    }

    private void RenderSelection()
    {
        if (NotificationsGrid.SelectedItem is not NotificationItem item)
        {
            DetailPriorityText.Text = "Selecione uma notificação";
            DetailTitleText.Text = "";
            DetailMessageText.Text = "Escolha um aviso à esquerda para ver os detalhes.";
            DetailTimeText.Text = "";
            ReadButton.IsEnabled = false;
            OpenRelatedButton.Visibility = Visibility.Collapsed;
            return;
        }

        DetailPriorityText.Text = $"{item.PriorityDisplay} • {item.StateDisplay}";
        DetailTitleText.Text = item.Title;
        DetailMessageText.Text = item.Message;
        DetailTimeText.Text = item.CreatedDisplay;
        ReadButton.IsEnabled = !_busy && item.IsUnread;
        OpenRelatedButton.Visibility = CanOpenRelated(item) ? Visibility.Visible : Visibility.Collapsed;
    }

    private bool CanOpenRelated(NotificationItem item)
    {
        if (item.EntityType == "order" && int.TryParse(item.EntityId, out var orderId) && orderId > 0) return true;
        if (item.EntityType == "discount_request" && _canDiscountApprove) return true;
        if (item.EntityType == "cancellation_request" && _canCancellationApprove) return true;
        return false;
    }

    private async Task MarkSelectedReadAsync()
    {
        if (_busy || NotificationsGrid.SelectedItem is not NotificationItem item || !item.IsUnread) return;
        _busy = true;
        ReadButton.IsEnabled = false;
        StatusText.Text = "Marcando como lida...";
        try
        {
            await _api.MarkNotificationReadAsync(item.Id);
            ReadStateChanged = true;
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
            _busy = false;
            RenderSelection();
            return;
        }
        _busy = false;
        await LoadAsync(item.Id);
    }

    private async Task MarkAllReadAsync()
    {
        if (_busy) return;
        _busy = true;
        StatusText.Text = "Marcando notificações como lidas...";
        try
        {
            var response = await _api.MarkAllNotificationsReadAsync();
            if (response.Updated > 0) ReadStateChanged = true;
            StatusText.Text = response.Updated == 0 ? "Não havia notificações novas." : $"{response.Updated} notificação(ões) marcadas como lidas.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
            _busy = false;
            return;
        }
        _busy = false;
        await LoadAsync();
    }

    private async Task OpenRelatedAsync()
    {
        if (NotificationsGrid.SelectedItem is not NotificationItem item) return;
        if (item.IsUnread) await MarkSelectedReadAsync();

        if (item.EntityType == "order" && int.TryParse(item.EntityId, out var orderId) && orderId > 0)
        {
            var window = new OrderDetailsWindow(
                _store,
                orderId,
                _canAssignDelivery,
                _canDiscountRequest,
                _canCancellationRequest) { Owner = this };
            window.ShowDialog();
            OperationChanged |= window.OrderChanged;
            return;
        }

        if (item.EntityType == "discount_request" && _canDiscountApprove)
        {
            var window = new ApprovalCenterWindow(_store, true, false) { Owner = this };
            window.ShowDialog();
            OperationChanged |= window.ApprovalChanged;
            await LoadAsync(item.Id);
            return;
        }

        if (item.EntityType == "cancellation_request" && _canCancellationApprove)
        {
            var window = new ApprovalCenterWindow(_store, false, true) { Owner = this };
            window.ShowDialog();
            OperationChanged |= window.ApprovalChanged;
            await LoadAsync(item.Id);
        }
    }

    private void NotificationsGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RenderSelection();
    private async void ReadButton_Click(object sender, RoutedEventArgs e) => await MarkSelectedReadAsync();
    private async void ReadAllButton_Click(object sender, RoutedEventArgs e) => await MarkAllReadAsync();
    private async void OpenRelatedButton_Click(object sender, RoutedEventArgs e) => await OpenRelatedAsync();
    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
