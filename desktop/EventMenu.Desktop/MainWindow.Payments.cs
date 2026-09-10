using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private StackPanel? _paymentPanel;
    private Button? _paymentOpenButton;
    private Button? _fiscalIssueButton;
    private Button? _fiscalDocumentsButton;
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

        StackPanel? actions = null;
        var rowGrid = OrdersView.Children.OfType<Grid>().FirstOrDefault(x => Grid.GetRow(x) == 2);
        if (rowGrid is not null)
            actions = rowGrid.Children.OfType<StackPanel>().FirstOrDefault(x => Grid.GetColumn(x) == 1);
        actions ??= OrdersView.Children.OfType<StackPanel>().FirstOrDefault(x => Grid.GetRow(x) == 2);
        if (actions is null) return;

        _paymentOpenButton = new Button
        {
            Content = "Receber",
            Height = 40,
            MinHeight = 40,
            Padding = new Thickness(16, 8, 16, 8),
            Visibility = Visibility.Collapsed,
            ToolTip = "Consultar e receber o pagamento deste pedido"
        };
        _paymentOpenButton.Click += async (_, _) => await OpenSelectedPaymentAsync();

        _fiscalIssueButton = new Button
        {
            Content = "Preparar nota",
            Height = 40,
            MinHeight = 40,
            Padding = new Thickness(14, 8, 14, 8),
            Visibility = Visibility.Collapsed,
            Style = TryFindResource("SecondaryButton") as Style,
            ToolTip = "Preparar o documento fiscal do pedido"
        };
        _fiscalIssueButton.Click += async (_, _) => await PrepareSelectedFiscalAsync();

        _fiscalDocumentsButton = new Button
        {
            Content = "Notas fiscais",
            Height = 40,
            MinHeight = 40,
            Padding = new Thickness(14, 8, 14, 8),
            Visibility = Visibility.Collapsed,
            Style = TryFindResource("SecondaryButton") as Style,
            ToolTip = "Consultar os documentos fiscais"
        };
        _fiscalDocumentsButton.Click += async (_, _) => await OpenFiscalDocumentsAsync();

        _paymentPanel = new StackPanel
        {
            Orientation = Orientation.Horizontal,
            VerticalAlignment = VerticalAlignment.Center,
            Visibility = Visibility.Collapsed
        };
        _paymentPanel.Children.Add(_paymentOpenButton);
        _paymentPanel.Children.Add(_fiscalIssueButton);
        _paymentPanel.Children.Add(_fiscalDocumentsButton);
        actions.Children.Insert(0, _paymentPanel);

        OrdersGrid.SelectionChanged += async (_, _) => await RefreshSelectedPaymentActionAsync(false);
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
            if (_paymentOpenButton is not null) _paymentOpenButton.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
            if (_fiscalIssueButton is not null) _fiscalIssueButton.Visibility = canFiscalIssue ? Visibility.Visible : Visibility.Collapsed;
            if (_fiscalDocumentsButton is not null) _fiscalDocumentsButton.Visibility = canFiscalIssue ? Visibility.Visible : Visibility.Collapsed;

            await RefreshSelectedPaymentActionAsync(false);
        }
        catch
        {
            _canPayments = false;
            _paymentPanel.Visibility = Visibility.Collapsed;
        }
    }

    private async Task RefreshSelectedPaymentActionAsync(bool showError)
    {
        if (_api is null) return;
        if (OrdersGrid.SelectedItem is not Order order)
        {
            if (_paymentOpenButton is not null)
            {
                _paymentOpenButton.Content = "Receber";
                _paymentOpenButton.IsEnabled = false;
            }
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;
            return;
        }

        if (_paymentOpenButton is not null)
        {
            _paymentOpenButton.IsEnabled = _canPayments && HasShift;
            _paymentOpenButton.Content = order.PaymentStatus == "paid" ? "Ver pagamento" : "Receber";
        }

        try
        {
            var response = await _api.PaymentStatusAsync(order.Id);
            var remaining = PaymentInt(response.Payment, "remaining_cents");
            if (_paymentOpenButton is not null)
            {
                _paymentOpenButton.Content = remaining <= 0 ? "Ver pagamento" : "Receber";
                _paymentOpenButton.IsEnabled = _canPayments && HasShift;
            }
            if (_fiscalIssueButton is not null)
                _fiscalIssueButton.IsEnabled = remaining <= 0 && order.Status != "cancelled" && Can("fiscal_issue");
        }
        catch (Exception ex)
        {
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;
            if (showError) MessageBox.Show(FriendlyPayment(ex.Message), "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task OpenSelectedPaymentAsync()
    {
        if (!_canPayments || _api is null || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno para consultar ou receber pagamentos.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        var window = new OrderPaymentWindow(_api, order, Can("cash")) { Owner = this };
        window.ShowDialog();
        if (window.PaymentChanged)
        {
            await TryLoadOrdersAsync(false);
            await TryLoadCashAsync(false);
            if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);
        }
        await RefreshSelectedPaymentActionAsync(false);
    }

    private async Task PrepareSelectedFiscalAsync()
    {
        if (!Can("fiscal_issue") || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de preparar a nota fiscal.", "Nota fiscal", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        if (order.Status == "cancelled")
        {
            MessageBox.Show("Pedido cancelado não pode gerar nota fiscal.", "Nota fiscal", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            EnsureHubRuntime();
            if (_hubIntegrationApi is null) throw new InvalidOperationException("A emissão fiscal não está disponível neste computador.");
            if (_fiscalIssueButton is not null) _fiscalIssueButton.IsEnabled = false;

            var response = await _hubIntegrationApi.QueueFiscalAsync(order.Id, "");
            var document = response.Document ?? throw new InvalidOperationException("Não foi possível preparar a nota fiscal.");
            var kind = document.Model == "65" ? "NFC-e" : "NF-e";
            var message = document.Status switch
            {
                "authorized" => $"{kind} autorizada. Chave: {document.AccessKey}",
                "rejected" => $"{kind} rejeitada: {document.RejectionCode} • {document.RejectionMessage}",
                "error" => $"{kind} preparada, mas não foi possível concluir o envio: {document.RejectionMessage}",
                "processing" => $"{kind} está sendo processada.",
                _ => $"{kind} nº {document.DocumentNumber} preparada. A autorização ainda está pendente."
            };
            MessageBox.Show(message, "Nota fiscal", MessageBoxButton.OK,
                document.Status == "authorized" ? MessageBoxImage.Information : MessageBoxImage.Warning);
        }
        catch (Exception ex)
        {
            MessageBox.Show(FriendlyPayment(ex.Message), "Nota fiscal", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            await RefreshSelectedPaymentActionAsync(false);
        }
    }

    private async Task OpenFiscalDocumentsAsync()
    {
        if (!Can("fiscal_issue")) return;
        try
        {
            EnsureHubRuntime();
            if (_hubIntegrationApi is null) throw new InvalidOperationException("A área fiscal não está disponível neste computador.");
            var window = new FiscalDocumentsWindow(_hubIntegrationApi) { Owner = this };
            window.ShowDialog();
            await RefreshSelectedPaymentActionAsync(false);
        }
        catch (Exception ex)
        {
            MessageBox.Show(FriendlyPayment(ex.Message), "Notas fiscais", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private static int PaymentInt(Dictionary<string, System.Text.Json.JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == System.Text.Json.JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private static string FriendlyPayment(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir esta operação. Tente novamente."
            : message;
    }
}
