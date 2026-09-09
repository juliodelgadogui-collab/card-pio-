using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private StackPanel? _paymentPanel;
    private TextBlock? _paymentStatusText;
    private TextBox? _paymentAmountBox;
    private Button? _paymentRefreshButton;
    private Button? _paymentCashButton;
    private bool _paymentControlsReady;
    private bool _canPayments;

    protected override void OnContentRendered(EventArgs e)
    {
        base.OnContentRendered(e);
        EnsurePaymentControls();
    }

    private void EnsurePaymentControls()
    {
        if (_paymentControlsReady) return;
        _paymentControlsReady = true;

        var actions = OrdersView.Children
            .OfType<StackPanel>()
            .FirstOrDefault(panel => Grid.GetRow(panel) == 2);
        if (actions is null) return;

        _paymentStatusText = new TextBlock
        {
            Text = "Selecione um pedido para consultar o pagamento.",
            VerticalAlignment = VerticalAlignment.Center,
            Foreground = Brushes.DimGray,
            Margin = new Thickness(0, 0, 10, 8),
            MaxWidth = 250,
            TextWrapping = TextWrapping.Wrap
        };
        _paymentAmountBox = new TextBox
        {
            Width = 105,
            Height = 36,
            Margin = new Thickness(0, 0, 8, 8),
            ToolTip = "Valor a receber em dinheiro"
        };
        _paymentRefreshButton = new Button
        {
            Content = "Ver saldo",
            Height = 36,
            Padding = new Thickness(12, 5, 12, 5)
        };
        _paymentRefreshButton.Click += async (_, _) => await RefreshSelectedPaymentAsync(true);

        _paymentCashButton = new Button
        {
            Content = "Receber dinheiro",
            Height = 36,
            Padding = new Thickness(12, 5, 12, 5)
        };
        _paymentCashButton.Click += async (_, _) => await ReceiveSelectedCashAsync();

        _paymentPanel = new StackPanel
        {
            Orientation = Orientation.Horizontal,
            VerticalAlignment = VerticalAlignment.Center,
            Visibility = Visibility.Collapsed
        };
        _paymentPanel.Children.Add(_paymentStatusText);
        _paymentPanel.Children.Add(_paymentAmountBox);
        _paymentPanel.Children.Add(_paymentRefreshButton);
        _paymentPanel.Children.Add(_paymentCashButton);
        actions.Children.Insert(0, _paymentPanel);

        OrdersGrid.SelectionChanged += async (_, _) => await RefreshSelectedPaymentAsync(false);
        ShellPanel.IsVisibleChanged += async (_, args) =>
        {
            if (args.NewValue is true) await RefreshPaymentPermissionsAsync();
        };

        if (ShellPanel.IsVisible)
            _ = RefreshPaymentPermissionsAsync();
    }

    private async Task RefreshPaymentPermissionsAsync()
    {
        if (_api is null || _paymentPanel is null) return;
        try
        {
            var context = await _api.GoContextAsync();
            foreach (var permission in context.Permissions)
                _permissions[permission.Key] = permission.Value;

            _canPayments = context.Permissions.TryGetValue("payments", out var allowed) && allowed;
            _paymentPanel.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
            if (_paymentCashButton is not null)
                _paymentCashButton.Visibility = _canPayments && Can("cash") ? Visibility.Visible : Visibility.Collapsed;
        }
        catch
        {
            _canPayments = false;
            _paymentPanel.Visibility = Visibility.Collapsed;
        }
    }

    private async Task RefreshSelectedPaymentAsync(bool showError)
    {
        if (!_canPayments || _api is null || _paymentStatusText is null || _paymentAmountBox is null) return;
        if (OrdersGrid.SelectedItem is not Order order)
        {
            _paymentStatusText.Text = "Selecione um pedido.";
            _paymentAmountBox.Clear();
            return;
        }
        if (!HasShift)
        {
            _paymentStatusText.Text = "Inicie um turno para receber.";
            _paymentAmountBox.Clear();
            return;
        }

        try
        {
            var response = await _api.PaymentStatusAsync(order.Id);
            var remaining = PaymentInt(response.Payment, "remaining_cents");
            var paid = PaymentInt(response.Payment, "paid_cents");
            var total = PaymentInt(response.Payment, "total_cents");
            _paymentStatusText.Text = remaining <= 0
                ? $"Pago • {Money(total)}"
                : $"Pago {Money(paid)} • Falta {Money(remaining)}";
            _paymentAmountBox.Text = remaining > 0 ? (remaining / 100m).ToString("N2", PtBr) : "";

            var orderClosed = order.Status is "cancelled" or "completed";
            if (_paymentCashButton is not null)
                _paymentCashButton.IsEnabled = remaining > 0 && !orderClosed && Can("cash");
        }
        catch (Exception ex)
        {
            _paymentStatusText.Text = "Pagamento indisponível";
            if (showError) MessageBox.Show(ex.Message, "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task ReceiveSelectedCashAsync()
    {
        if (!_canPayments || _api is null || _paymentAmountBox is null || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de receber pagamento.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (order.Status is "cancelled" or "completed")
        {
            MessageBox.Show("Este pedido está encerrado e não pode receber nova cobrança.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(_paymentAmountBox.Text, out var amountCents, false))
        {
            MessageBox.Show("Informe um valor maior que zero.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (_paymentCashButton is not null) _paymentCashButton.IsEnabled = false;
        try
        {
            // Uma chave nova representa uma intenção explícita de recebimento. Repetições da mesma
            // requisição no servidor continuam protegidas pela idempotência da camada de pagamentos.
            var key = $"desktop-cash:{order.Id}:{Guid.NewGuid():N}";
            var response = await _api.PaymentCashAsync(order.Id, amountCents, key);
            var remaining = PaymentInt(response.Payment, "remaining_cents");
            var paid = PaymentInt(response.Payment, "paid_cents");
            var total = PaymentInt(response.Payment, "total_cents");

            if (_paymentStatusText is not null)
                _paymentStatusText.Text = remaining <= 0
                    ? $"Pago • {Money(total)}"
                    : $"Pago {Money(paid)} • Falta {Money(remaining)}";
            _paymentAmountBox.Text = remaining > 0 ? (remaining / 100m).ToString("N2", PtBr) : "";

            await TryLoadOrdersAsync(false);
            await TryLoadCashAsync(false);
            if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);

            MessageBox.Show(
                remaining <= 0 ? $"Pedido #{order.Id} pago." : $"Parcela registrada. Ainda faltam {Money(remaining)}.",
                "Pagamento",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            if (_paymentCashButton is not null) _paymentCashButton.IsEnabled = true;
        }
    }

    private static int PaymentInt(Dictionary<string, JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }
}
