using System.Windows;
using System.Windows.Controls;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _androidParityNavigationReady;
    private Button? _managerCenterButton;
    private Button? _eventOperationsButton;
    private Button? _deliveryWorkButton;
    private Button? _deliveryTriageButton;
    private Button? _shiftSummaryButton;

    protected override void OnContentRendered(EventArgs e)
    {
        base.OnContentRendered(e);
        EnsureAndroidParityNavigation();
    }

    private void EnsureAndroidParityNavigation()
    {
        if (_androidParityNavigationReady || PosNavButton.Parent is not StackPanel sidebar) return;
        _androidParityNavigationReady = true;

        _deliveryTriageButton = CreateParityButton(
            "Triagem de Delivery",
            "Direcionar pedidos de Delivery que ainda não possuem unidade",
            async () => await OpenDeliveryTriageAsync());

        _deliveryWorkButton = CreateParityButton(
            "Minhas entregas",
            "Retirada, rota, chegada e conclusão das entregas atribuídas",
            async () => await OpenDeliveryWorkAsync());

        _managerCenterButton = CreateParityButton(
            "Gerência",
            "Visão gerencial, alertas, caixas, entregadores e reabertura de pedidos",
            async () => await OpenManagerCenterAsync());

        _eventOperationsButton = CreateParityButton(
            "Eventos",
            "Check-in, convidados, bar, retirada e acompanhamento do evento",
            async () => await OpenEventOperationsAsync());

        _shiftSummaryButton = CreateParityButton(
            "Meu turno",
            "Resumo dos pedidos, recebimentos, dinheiro de Delivery e comissão",
            async () => await OpenShiftSummaryAsync());

        var anchor = _deliveryMonitorButton is not null && sidebar.Children.Contains(_deliveryMonitorButton)
            ? sidebar.Children.IndexOf(_deliveryMonitorButton) + 1
            : Math.Max(0, sidebar.Children.IndexOf(CashNavButton) + 1);

        sidebar.Children.Insert(anchor, _deliveryTriageButton);
        sidebar.Children.Insert(anchor + 1, _deliveryWorkButton);
        sidebar.Children.Insert(anchor + 2, _managerCenterButton);
        sidebar.Children.Insert(anchor + 3, _eventOperationsButton);
        sidebar.Children.Insert(anchor + 4, _shiftSummaryButton);

        ShellPanel.IsVisibleChanged += ShellPanel_AndroidParityVisibilityChanged;
        if (_hubTimer is not null) _hubTimer.Tick += AndroidParityVisibilityTick;
        Closed += MainWindow_AndroidParityClosed;
        ApplyAndroidParityVisibility();
    }

    private Button CreateParityButton(string label, string toolTip, Func<Task> action)
    {
        var button = new Button
        {
            Content = label,
            HorizontalContentAlignment = HorizontalAlignment.Left,
            ToolTip = toolTip,
            Visibility = Visibility.Collapsed
        };
        button.Click += async (_, _) => await action();
        return button;
    }

    private void ShellPanel_AndroidParityVisibilityChanged(object sender, DependencyPropertyChangedEventArgs e) => ApplyAndroidParityVisibility();
    private void AndroidParityVisibilityTick(object? sender, EventArgs e) => ApplyAndroidParityVisibility();

    private void ApplyAndroidParityVisibility()
    {
        var shellVisible = ShellPanel.Visibility == Visibility.Visible;

        if (_deliveryTriageButton is not null)
            _deliveryTriageButton.Visibility = shellVisible && (Can("delivery_assign") || Can("orders_manage"))
                ? Visibility.Visible : Visibility.Collapsed;

        if (_deliveryWorkButton is not null)
            _deliveryWorkButton.Visibility = shellVisible && Can("orders_delivery")
                ? Visibility.Visible : Visibility.Collapsed;

        if (_managerCenterButton is not null)
            _managerCenterButton.Visibility = shellVisible && (Can("reports") || Can("orders_manage"))
                ? Visibility.Visible : Visibility.Collapsed;

        if (_eventOperationsButton is not null)
            _eventOperationsButton.Visibility = shellVisible &&
                (Can("events") || Can("event_bar") || Can("tickets") || Can("guests") || Can("promoter"))
                ? Visibility.Visible : Visibility.Collapsed;

        if (_shiftSummaryButton is not null)
            _shiftSummaryButton.Visibility = shellVisible ? Visibility.Visible : Visibility.Collapsed;
    }

    private async Task OpenDeliveryTriageAsync()
    {
        if (_store is null || ShellPanel.Visibility != Visibility.Visible) return;
        if (!Can("delivery_assign") && !Can("orders_manage")) return;
        if (!HasShift || !ShiftIs("operation"))
        {
            MessageBox.Show("Use um turno de Operação para direcionar pedidos entre unidades.", "Triagem de Delivery", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            var window = new DeliveryTriageWindow(_store) { Owner = this };
            window.ShowDialog();
            if (window.OperationChanged) await RefreshAfterAndroidParityOperationAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Triagem de Delivery", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task OpenDeliveryWorkAsync()
    {
        if (_store is null || ShellPanel.Visibility != Visibility.Visible || !Can("orders_delivery")) return;
        if (!HasShift || !ShiftIs("delivery"))
        {
            MessageBox.Show("Inicie um turno no modo Delivery para operar suas entregas.", "Minhas entregas", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            var window = new DeliveryWorkWindow(_store) { Owner = this };
            window.ShowDialog();
            if (window.OperationChanged) await RefreshAfterAndroidParityOperationAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Minhas entregas", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task OpenManagerCenterAsync()
    {
        if (_store is null || ShellPanel.Visibility != Visibility.Visible) return;
        if (!Can("reports") && !Can("orders_manage")) return;
        if (!HasShift || (!ShiftIs("operation") && !ShiftIs("pay")))
        {
            MessageBox.Show("Use um turno de Operação ou Pay para abrir a Central gerencial.", "Gerência", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            var window = new ManagerCenterWindow(
                _store,
                Can("delivery_assign"),
                Can("orders_reopen"),
                Can("discount_request"),
                Can("cancellation_request"),
                Can("orders_dispatch") || Can("orders_manage"),
                Can("loyalty_redeem")) { Owner = this };
            window.ShowDialog();
            if (window.OperationChanged) await RefreshAfterAndroidParityOperationAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Gerência", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task OpenEventOperationsAsync()
    {
        if (_store is null || ShellPanel.Visibility != Visibility.Visible) return;
        if (!Can("events") && !Can("event_bar") && !Can("tickets") && !Can("guests") && !Can("promoter")) return;
        if (!HasShift || !ShiftIs("events"))
        {
            MessageBox.Show("Inicie um turno no modo Eventos para abrir esta área.", "Eventos", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            var window = new EventOperationsWindow(
                _store,
                Can("tickets"),
                Can("guests"),
                Can("event_bar"),
                Can("payments"),
                Can("cash")) { Owner = this };
            window.ShowDialog();
            if (window.OperationChanged) await RefreshAfterAndroidParityOperationAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Eventos", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task OpenShiftSummaryAsync()
    {
        if (_store is null || ShellPanel.Visibility != Visibility.Visible) return;
        try
        {
            var window = new ShiftSummaryWindow(_store) { Owner = this };
            window.ShowDialog();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Meu turno", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        await Task.CompletedTask;
    }

    private async Task RefreshAfterAndroidParityOperationAsync()
    {
        if (Can("orders_create") || Can("orders_manage") || Can("orders_kitchen") || Can("orders_delivery"))
            await TryLoadOrdersAsync(false);
        if (Can("orders_create")) await TryLoadProductsAsync(false);
        if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);
        if (Can("cash")) await TryLoadCashAsync(false);
        RefreshDashboardUx();
    }

    private void MainWindow_AndroidParityClosed(object? sender, EventArgs e)
    {
        ShellPanel.IsVisibleChanged -= ShellPanel_AndroidParityVisibilityChanged;
        if (_hubTimer is not null) _hubTimer.Tick -= AndroidParityVisibilityTick;
        _managerCenterButton = null;
        _eventOperationsButton = null;
        _deliveryWorkButton = null;
        _deliveryTriageButton = null;
        _shiftSummaryButton = null;
        _androidParityNavigationReady = false;
    }
}
