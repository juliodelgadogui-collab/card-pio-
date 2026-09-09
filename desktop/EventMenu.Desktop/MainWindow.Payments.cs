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
    private Button? _paymentPixButton;
    private Button? _fiscalIssueButton;
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
            ToolTip = "Valor a receber"
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

        _paymentPixButton = new Button
        {
            Content = "Gerar PIX",
            Height = 36,
            Padding = new Thickness(12, 5, 12, 5)
        };
        _paymentPixButton.Click += async (_, _) => await OpenPixWindowAsync();

        _fiscalIssueButton = new Button
        {
            Content = "Preparar fiscal",
            Height = 36,
            Padding = new Thickness(12, 5, 12, 5),
            ToolTip = "Prepara NFC-e/NF-e e coloca na fila fiscal. Autorização depende da resposta real da SEFAZ.",
            Visibility = Visibility.Collapsed
        };
        _fiscalIssueButton.Click += async (_, _) => await PrepareSelectedFiscalAsync();

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
        _paymentPanel.Children.Add(_paymentPixButton);
        _paymentPanel.Children.Add(_fiscalIssueButton);
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
            var canFiscalIssue = context.Permissions.TryGetValue("fiscal_issue", out var fiscalAllowed) && fiscalAllowed;
            _paymentPanel.Visibility = (_canPayments || canFiscalIssue) ? Visibility.Visible : Visibility.Collapsed;
            if (_paymentAmountBox is not null) _paymentAmountBox.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
            if (_paymentRefreshButton is not null) _paymentRefreshButton.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
            if (_paymentCashButton is not null)
                _paymentCashButton.Visibility = _canPayments && Can("cash") ? Visibility.Visible : Visibility.Collapsed;
            if (_paymentPixButton is not null)
                _paymentPixButton.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
            if (_fiscalIssueButton is not null)
                _fiscalIssueButton.Visibility = canFiscalIssue ? Visibility.Visible : Visibility.Collapsed;

            await RefreshSelectedPaymentAsync(false);
        }
        catch
        {
            _canPayments = false;
            _paymentPanel.Visibility = Visibility.Collapsed;
        }
    }

    private async Task RefreshSelectedPaymentAsync(bool showError)
    {
        if (_api is null || _paymentStatusText is null) return;
        if (OrdersGrid.SelectedItem is not Order order)
        {
            _paymentStatusText.Text = "Selecione um pedido.";
            _paymentAmountBox?.Clear();
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;
            return;
        }
        if (!HasShift)
        {
            _paymentStatusText.Text = "Inicie um turno para receber.";
            _paymentAmountBox?.Clear();
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;
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
            if (_paymentAmountBox is not null)
                _paymentAmountBox.Text = remaining > 0 ? (remaining / 100m).ToString("N2", PtBr) : "";

            var orderClosedForPayment = order.Status is "cancelled" or "completed";
            if (_paymentCashButton is not null)
                _paymentCashButton.IsEnabled = remaining > 0 && !orderClosedForPayment && Can("cash");
            if (_paymentPixButton is not null)
                _paymentPixButton.IsEnabled = remaining > 0 && !orderClosedForPayment;
            if (_fiscalIssueButton is not null)
                _fiscalIssueButton.IsEnabled = remaining <= 0 && order.Status != "cancelled" && Can("fiscal_issue");
        }
        catch (Exception ex)
        {
            _paymentStatusText.Text = "Pagamento indisponível";
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;
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
            await RefreshSelectedPaymentAsync(false);

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

    private async Task OpenPixWindowAsync()
    {
        if (!_canPayments || _api is null || _paymentAmountBox is null || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de cobrar PIX.", "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (order.Status is "cancelled" or "completed")
        {
            MessageBox.Show("Este pedido está encerrado e não pode receber nova cobrança.", "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(_paymentAmountBox.Text, out var amountCents, false))
        {
            await RefreshSelectedPaymentAsync(true);
            if (!TryMoney(_paymentAmountBox.Text, out amountCents, false)) return;
        }

        var window = new PixPaymentWindow(_api, order.Id, amountCents) { Owner = this };
        window.ShowDialog();
        await RefreshSelectedPaymentAsync(false);
        await TryLoadOrdersAsync(false);
        if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);
    }

    private async Task PrepareSelectedFiscalAsync()
    {
        if (!Can("fiscal_issue") || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno na unidade correta antes de preparar o documento fiscal.", "Fiscal", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (order.Status == "cancelled")
        {
            MessageBox.Show("Pedido cancelado não pode gerar documento fiscal.", "Fiscal", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        try
        {
            EnsureHubRuntime();
            if (_hubIntegrationApi is null) throw new InvalidOperationException("Módulo fiscal do Desktop indisponível.");
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;

            var response = await _hubIntegrationApi.QueueFiscalAsync(order.Id, "");
            var document = response.Document ?? throw new InvalidOperationException("O servidor não retornou o documento fiscal.");
            var kind = document.Model == "65" ? "NFC-e" : "NF-e";
            var message = document.Status switch
            {
                "authorized" => $"{kind} já autorizada. Chave: {document.AccessKey}",
                "rejected" => $"{kind} rejeitada pela SEFAZ: {document.RejectionCode} • {document.RejectionMessage}",
                "error" => $"{kind} preparada, mas a transmissão está com erro técnico: {document.RejectionMessage}",
                "processing" => $"{kind} já está em processamento no módulo fiscal.",
                _ => $"{kind} #{document.DocumentNumber} preparada e colocada na fila fiscal. Ainda não está autorizada pela SEFAZ."
            };
            MessageBox.Show(message, "Fiscal", MessageBoxButton.OK,
                document.Status == "authorized" ? MessageBoxImage.Information : MessageBoxImage.Warning);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Fiscal", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            await RefreshSelectedPaymentAsync(false);
        }
    }

    private static int PaymentInt(Dictionary<string, JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }
}
