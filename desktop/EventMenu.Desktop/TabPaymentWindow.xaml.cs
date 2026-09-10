using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;
using QRCoder;

namespace EventMenu.Desktop;

public partial class TabPaymentWindow : Window
{
    private static readonly CultureInfo PtBr = CultureInfo.GetCultureInfo("pt-BR");
    private readonly TabPaymentApiClient _api;
    private readonly int _tabId;
    private readonly bool _canCash;
    private readonly DispatcherTimer _pixTimer;
    private TabAccount? _account;
    private TabPaymentGroup? _currentGroup;
    private bool _busy;
    private bool _polling;

    public bool PaymentChanged { get; private set; }

    public TabPaymentWindow(SecureSessionStore store, int tabId, bool canCash)
    {
        _api = new TabPaymentApiClient(store);
        _tabId = tabId;
        _canCash = canCash;
        InitializeComponent();

        SplitTypeCombo.SelectedIndex = 0;
        PaymentMethodCombo.Items.Add(new ComboBoxItem { Content = "Pix", Tag = "pix" });
        if (_canCash) PaymentMethodCombo.Items.Add(new ComboBoxItem { Content = "Dinheiro", Tag = "cash" });
        PaymentMethodCombo.SelectedIndex = 0;

        _pixTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(4) };
        _pixTimer.Tick += PixTimer_Tick;
        Loaded += async (_, _) => await LoadAccountAsync();
    }

    private async Task LoadAccountAsync()
    {
        if (_busy) return;
        _busy = true;
        SetEditingEnabled(false);
        FooterStatusText.Text = "Atualizando a conta...";
        try
        {
            var response = await _api.AccountAsync(_tabId);
            _account = response.Account ?? throw new InvalidOperationException("Comanda não encontrada.");
            RenderAccount(_account);
            _currentGroup = _account.OpenGroup;
            RenderOpenGroup();
            FooterStatusText.Text = "Conta atualizada.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            SetEditingEnabled(_currentGroup is null && (_account?.RemainingCents ?? 0) > 0);
            RefreshPreview();
        }
    }

    private void RenderAccount(TabAccount account)
    {
        TitleText.Text = string.IsNullOrWhiteSpace(account.Tab.TableName) ? "Conta da mesa" : account.Tab.TableName;
        SubtitleText.Text = string.IsNullOrWhiteSpace(account.Tab.Label)
            ? $"Comanda #{account.Tab.Id} • aberta em {account.Tab.OpenedDisplay}"
            : $"{account.Tab.Label} • comanda #{account.Tab.Id} • aberta em {account.Tab.OpenedDisplay}";
        TotalText.Text = account.TotalDisplay;
        PaidText.Text = account.PaidDisplay;
        RemainingText.Text = account.RemainingDisplay;

        if (account.RemainingCents <= 0)
        {
            PaymentStateText.Text = "Comanda integralmente paga.";
            ChargeButton.IsEnabled = false;
        }
        else if (_currentGroup is null)
        {
            SplitValueBox.Text = (account.RemainingCents / 100m).ToString("N2", PtBr);
        }
    }

    private void RenderOpenGroup()
    {
        if (_currentGroup is null)
        {
            CancelGroupButton.Visibility = Visibility.Collapsed;
            ClearPixVisuals();
            return;
        }

        PaymentStateText.Text = $"{_currentGroup.MethodDisplay} • {_currentGroup.AmountDisplay} • {_currentGroup.StatusDisplay}";
        SetComboByTag(PaymentMethodCombo, _currentGroup.Method);
        PaymentMethodCombo.IsEnabled = false;
        SplitTypeCombo.IsEnabled = false;
        SplitValueBox.IsEnabled = false;

        if (_currentGroup.Method == "pix" && _currentGroup.Status is "created" or "pending")
        {
            PixCustomerPanel.Visibility = Visibility.Visible;
            ChargeButton.Content = _currentGroup.Status == "pending" ? "Retomar Pix" : "Gerar Pix";
            ChargeButton.IsEnabled = true;
            CancelGroupButton.Visibility = _currentGroup.Status == "created" ? Visibility.Visible : Visibility.Collapsed;
            ServerStatusText.Text = "Existe uma cobrança Pix em andamento. Informe o CPF/CNPJ do pagador para exibir ou reutilizar o código.";
        }
        else
        {
            ChargeButton.IsEnabled = false;
            CancelGroupButton.Visibility = _currentGroup.Status == "created" ? Visibility.Visible : Visibility.Collapsed;
        }
    }

    private void SetEditingEnabled(bool enabled)
    {
        SplitTypeCombo.IsEnabled = enabled;
        SplitValueBox.IsEnabled = enabled;
        PaymentMethodCombo.IsEnabled = enabled;
        ChargeButton.IsEnabled = enabled || (_currentGroup?.Method == "pix" && _currentGroup.Status is "created" or "pending");
    }

    private async Task StartPaymentAsync()
    {
        if (_busy || _account is null || _account.RemainingCents <= 0) return;
        var method = SelectedTag(PaymentMethodCombo, "pix");

        if (_currentGroup is not null)
        {
            if (_currentGroup.Method == "pix" && _currentGroup.Status is "created" or "pending")
                await GeneratePixAsync(_currentGroup.Id);
            return;
        }

        var splitType = SelectedTag(SplitTypeCombo, "value");
        if (!TryBuildOptions(splitType, _account.RemainingCents, out var options, out var previewAmount, out var error))
        {
            FooterStatusText.Text = error;
            SplitValueBox.Focus();
            return;
        }

        if (method == "cash" && !_canCash)
        {
            FooterStatusText.Text = "Seu usuário não possui permissão para receber em dinheiro.";
            return;
        }

        if (method == "pix" && !ValidTaxIdShape(TaxIdBox.Text))
        {
            FooterStatusText.Text = "Informe CPF ou CNPJ do pagador para gerar o Pix.";
            TaxIdBox.Focus();
            return;
        }

        _busy = true;
        SetEditingEnabled(false);
        FooterStatusText.Text = method == "cash" ? "Registrando recebimento..." : "Preparando cobrança Pix...";
        try
        {
            var key = $"desktop-tab:{_tabId}:{Guid.NewGuid():N}";
            var response = await _api.CreateGroupAsync(_tabId, splitType, method, options, key);
            _currentGroup = response.Group ?? throw new InvalidOperationException("O servidor não retornou a cobrança criada.");
            PaymentStateText.Text = $"{_currentGroup.MethodDisplay} • {_currentGroup.AmountDisplay} • {_currentGroup.StatusDisplay}";

            if (method == "cash")
            {
                if (_currentGroup.Status != "paid")
                    throw new InvalidOperationException("O recebimento em dinheiro não foi confirmado pelo servidor.");
                PaymentChanged = true;
                FooterStatusText.Text = $"Recebimento de {TabPaymentDisplay.Money(previewAmount)} confirmado.";
                _currentGroup = null;
                _busy = false;
                await LoadAccountAsync();
                return;
            }
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
            _busy = false;
            SetEditingEnabled(_currentGroup is null);
            RenderOpenGroup();
            return;
        }

        _busy = false;
        RenderOpenGroup();
        if (_currentGroup?.Method == "pix") await GeneratePixAsync(_currentGroup.Id);
    }

    private async Task GeneratePixAsync(int groupId)
    {
        if (_busy) return;
        var taxId = Digits(TaxIdBox.Text);
        if (!ValidTaxIdShape(taxId))
        {
            FooterStatusText.Text = "Informe um CPF ou CNPJ válido para continuar.";
            TaxIdBox.Focus();
            return;
        }

        _busy = true;
        ChargeButton.IsEnabled = false;
        FooterStatusText.Text = "Gerando Pix...";
        try
        {
            var response = await _api.CreatePixAsync(groupId, taxId);
            var pix = response.Pix ?? throw new InvalidOperationException("O servidor não retornou o Pix.");
            if (string.IsNullOrWhiteSpace(pix.CopyPaste)) throw new InvalidOperationException("O provedor não retornou o código Pix.");

            PixCodeBox.Text = pix.CopyPaste;
            PixCodeBox.Visibility = Visibility.Visible;
            PixCodeLabel.Visibility = Visibility.Visible;
            CopyPixButton.Visibility = Visibility.Visible;
            QrImage.Source = CreateQrNative(pix.CopyPaste);
            QrBorder.Visibility = Visibility.Visible;
            PixExpiryText.Text = string.IsNullOrWhiteSpace(pix.ExpiresAt) ? "" : $"Válido até {pix.ExpiresDisplay}";
            PaymentStateText.Text = $"Pix de {pix.AmountDisplay} • aguardando pagamento";
            ServerStatusText.Text = "Verificando automaticamente. O pagamento só será concluído após confirmação do PagBank pelo servidor.";
            FooterStatusText.Text = pix.Reused ? "Cobrança Pix existente reutilizada." : "Pix gerado com sucesso.";
            _pixTimer.Start();
            await CheckPixAsync();
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            ChargeButton.IsEnabled = _currentGroup?.Method == "pix" && _currentGroup.Status is "created" or "pending";
        }
    }

    private async Task CheckPixAsync()
    {
        if (_polling || _currentGroup is null || _currentGroup.Method != "pix") return;
        _polling = true;
        try
        {
            var response = await _api.PixStatusAsync(_currentGroup.Id);
            if (response.Group is not null) _currentGroup = response.Group;

            if (response.Paid || _currentGroup?.Status == "paid")
            {
                _pixTimer.Stop();
                PaymentChanged = true;
                PaymentStateText.Text = "Pix confirmado.";
                ServerStatusText.Text = "Pagamento confirmado pelo servidor.";
                FooterStatusText.Text = "Pagamento recebido com sucesso.";
                ChargeButton.IsEnabled = false;
                CancelGroupButton.Visibility = Visibility.Collapsed;
                _currentGroup = null;
                await LoadAccountAsync();
                return;
            }

            if (_currentGroup?.Status is "failed" or "cancelled" or "attention")
            {
                _pixTimer.Stop();
                PaymentStateText.Text = _currentGroup.StatusDisplay;
                ServerStatusText.Text = _currentGroup.Status == "attention"
                    ? "A cobrança precisa de conferência no servidor antes de qualquer nova tentativa."
                    : "Esta cobrança não está mais aguardando pagamento.";
                ChargeButton.IsEnabled = false;
            }
            else
            {
                PaymentStateText.Text = _currentGroup is null ? "Aguardando pagamento Pix." : $"Pix • {_currentGroup.AmountDisplay} • aguardando pagamento";
            }
        }
        catch (Exception ex)
        {
            ServerStatusText.Text = $"Não foi possível conferir agora: {ex.Message}";
        }
        finally
        {
            _polling = false;
        }
    }

    private async Task CancelOpenGroupAsync()
    {
        if (_busy || _currentGroup is null || _currentGroup.Status != "created") return;
        if (MessageBox.Show("Cancelar esta cobrança antes de enviá-la ao provedor?", "Conta da mesa", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;

        _busy = true;
        CancelGroupButton.IsEnabled = false;
        try
        {
            await _api.CancelGroupAsync(_currentGroup.Id);
            _currentGroup = null;
            ClearPixVisuals();
            FooterStatusText.Text = "Cobrança cancelada.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            CancelGroupButton.IsEnabled = true;
        }
        await LoadAccountAsync();
    }

    private bool TryBuildOptions(string splitType, int remainingCents, out object options, out int estimatedAmountCents, out string error)
    {
        options = new { };
        estimatedAmountCents = 0;
        error = "";
        var raw = SplitValueBox.Text.Trim();

        if (splitType == "value")
        {
            if (!TryMoney(raw, out var amount) || amount <= 0 || amount > remainingCents)
            {
                error = "Informe um valor maior que zero e não superior ao saldo da comanda.";
                return false;
            }
            estimatedAmountCents = amount;
            options = new { amount_cents = amount };
            return true;
        }

        if (splitType == "percentage")
        {
            if (!decimal.TryParse(raw, NumberStyles.Number, PtBr, out var percentage) || percentage <= 0 || percentage > 100)
            {
                error = "Informe um percentual maior que 0 e no máximo 100.";
                return false;
            }
            estimatedAmountCents = percentage >= 100 ? remainingCents : Math.Max(1, (int)Math.Round(remainingCents * percentage / 100m, MidpointRounding.AwayFromZero));
            options = new { percentage };
            return true;
        }

        if (!int.TryParse(raw, out var people) || people < 1 || people > 100)
        {
            error = "Informe quantas pessoas ainda vão dividir o saldo, entre 1 e 100.";
            return false;
        }
        estimatedAmountCents = (int)Math.Ceiling(remainingCents / (decimal)people);
        options = new { people_remaining = people };
        return true;
    }

    private void RefreshPreview()
    {
        if (_account is null)
        {
            PreviewText.Text = "Carregando a conta...";
            return;
        }
        if (_currentGroup is not null)
        {
            PreviewText.Text = $"Cobrança em andamento: {_currentGroup.MethodDisplay} • {_currentGroup.AmountDisplay} • {_currentGroup.StatusDisplay}.";
            return;
        }
        if (_account.RemainingCents <= 0)
        {
            PreviewText.Text = "A comanda não possui saldo a receber.";
            return;
        }

        var split = SelectedTag(SplitTypeCombo, "value");
        if (TryBuildOptions(split, _account.RemainingCents, out _, out var amount, out _))
        {
            var method = SelectedTag(PaymentMethodCombo, "pix") == "cash" ? "dinheiro" : "Pix";
            PreviewText.Text = $"Esta cobrança será de aproximadamente {TabPaymentDisplay.Money(amount)} em {method}. O valor final será validado pelo servidor.";
        }
        else
        {
            PreviewText.Text = "Informe os dados da divisão para calcular esta cobrança.";
        }
    }

    private void SplitTypeCombo_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var split = SelectedTag(SplitTypeCombo, "value");
        SplitValueLabel.Text = split switch
        {
            "percentage" => "Percentual a receber",
            "person" => "Pessoas que ainda vão dividir",
            _ => "Valor a receber"
        };
        SplitHintText.Text = split switch
        {
            "percentage" => "Ex.: 50 para receber metade do saldo restante.",
            "person" => "Ex.: 4 para receber uma das quatro partes do saldo restante.",
            _ => "Você pode receber o saldo inteiro ou somente uma parte."
        };
        if (_account is not null && split == "value") SplitValueBox.Text = (_account.RemainingCents / 100m).ToString("N2", PtBr);
        else if (split == "percentage") SplitValueBox.Text = "100";
        else if (split == "person") SplitValueBox.Text = "1";
        RefreshPreview();
    }

    private void PaymentMethodCombo_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        PixCustomerPanel.Visibility = SelectedTag(PaymentMethodCombo, "pix") == "pix" ? Visibility.Visible : Visibility.Collapsed;
        ChargeButton.Content = SelectedTag(PaymentMethodCombo, "pix") == "cash" ? "Receber em dinheiro" : "Gerar Pix";
        RefreshPreview();
    }

    private void SplitValueBox_TextChanged(object sender, TextChangedEventArgs e) => RefreshPreview();
    private async void ChargeButton_Click(object sender, RoutedEventArgs e) => await StartPaymentAsync();
    private async void CancelGroupButton_Click(object sender, RoutedEventArgs e) => await CancelOpenGroupAsync();
    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAccountAsync();
    private async void PixTimer_Tick(object? sender, EventArgs e) => await CheckPixAsync();

    private void CopyPixButton_Click(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PixCodeBox.Text)) return;
        try
        {
            Clipboard.SetText(PixCodeBox.Text);
            FooterStatusText.Text = "Código Pix copiado.";
        }
        catch
        {
            FooterStatusText.Text = "Não foi possível copiar o código Pix.";
        }
    }

    private void ClearPixVisuals()
    {
        QrBorder.Visibility = Visibility.Collapsed;
        QrImage.Source = null;
        PixCodeLabel.Visibility = Visibility.Collapsed;
        PixCodeBox.Visibility = Visibility.Collapsed;
        PixCodeBox.Clear();
        CopyPixButton.Visibility = Visibility.Collapsed;
        PixExpiryText.Text = "";
        _pixTimer.Stop();
    }

    private static string SelectedTag(ComboBox combo, string fallback) =>
        (combo.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? fallback;

    private static void SetComboByTag(ComboBox combo, string value)
    {
        combo.SelectedItem = combo.Items.Cast<object>().OfType<ComboBoxItem>()
            .FirstOrDefault(x => string.Equals(x.Tag?.ToString(), value, StringComparison.OrdinalIgnoreCase));
    }

    private static bool TryMoney(string text, out int cents)
    {
        cents = 0;
        text = text.Replace("R$", "", StringComparison.OrdinalIgnoreCase).Trim();
        if (!decimal.TryParse(text, NumberStyles.Number, PtBr, out var value) || value <= 0 || value > 21_000_000m) return false;
        cents = (int)Math.Round(value * 100m, MidpointRounding.AwayFromZero);
        return true;
    }

    private static string Digits(string value) => new(value.Where(char.IsDigit).ToArray());
    private static bool ValidTaxIdShape(string value)
    {
        var digits = Digits(value);
        return digits.Length is 11 or 14;
    }

    private static BitmapSource CreateQrNative(string value)
    {
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(value, QRCodeGenerator.ECCLevel.M, forceUtf8: true);
        var matrix = data.ModuleMatrix;
        if (matrix is null || matrix.Count == 0) throw new InvalidOperationException("QR inválido.");

        const int quietZone = 4;
        const int modulePixels = 7;
        var modules = matrix.Count;
        var size = (modules + quietZone * 2) * modulePixels;
        var visual = new DrawingVisual();
        using (var dc = visual.RenderOpen())
        {
            dc.DrawRectangle(Brushes.White, null, new Rect(0, 0, size, size));
            for (var y = 0; y < modules; y++)
            for (var x = 0; x < modules; x++)
            {
                if (!matrix[y][x]) continue;
                dc.DrawRectangle(Brushes.Black, null, new Rect((x + quietZone) * modulePixels, (y + quietZone) * modulePixels, modulePixels, modulePixels));
            }
        }
        var bitmap = new RenderTargetBitmap(size, size, 96, 96, PixelFormats.Pbgra32);
        bitmap.Render(visual);
        bitmap.Freeze();
        return bitmap;
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    protected override void OnClosed(EventArgs e)
    {
        _pixTimer.Stop();
        _pixTimer.Tick -= PixTimer_Tick;
        _api.Dispose();
        base.OnClosed(e);
    }
}
