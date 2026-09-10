using System.Globalization;
using System.Windows;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class PixPaymentWindow : Window
{
    private static readonly CultureInfo PtBr = new("pt-BR");
    private readonly EventMenuApiClient _api;
    private readonly int _orderId;
    private readonly DispatcherTimer _pollTimer;
    private bool _polling;

    public bool PaymentConfirmed { get; private set; }

    public PixPaymentWindow(EventMenuApiClient api, int orderId, int defaultAmountCents)
    {
        InitializeComponent();
        _api = api;
        _orderId = orderId;
        OrderText.Text = $"Pedido #{orderId}";
        AmountBox.Text = (defaultAmountCents / 100m).ToString("N2", PtBr);

        _pollTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(4) };
        _pollTimer.Tick += PollTimer_Tick;
    }

    private async void GenerateButton_Click(object sender, RoutedEventArgs e)
    {
        var taxId = new string(TaxIdBox.Text.Where(char.IsDigit).ToArray());
        if (taxId.Length is not (11 or 14))
        {
            MessageBox.Show("Informe um CPF ou CNPJ válido.", "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(AmountBox.Text, out var amountCents) || amountCents <= 0)
        {
            MessageBox.Show("Informe um valor maior que zero.", "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        GenerateButton.IsEnabled = false;
        StatusText.Text = "Preparando Pix...";
        try
        {
            var response = await _api.PixCreateAsync(_orderId, taxId, amountCents);
            var pix = response.Pix ?? throw new ApiClientException("Não foi possível gerar o Pix.");
            if (string.IsNullOrWhiteSpace(pix.CopyPaste)) throw new ApiClientException("O código Pix não foi recebido.");

            CopyPasteBox.Text = pix.CopyPaste;
            CopyPasteBox.Visibility = Visibility.Visible;
            CopyPasteLabel.Visibility = Visibility.Visible;
            CopyButton.Visibility = Visibility.Visible;
            ExpiresText.Text = string.IsNullOrWhiteSpace(pix.ExpiresAt) ? "" : $"Válido até {ServerTimeDisplay.Local(pix.ExpiresAt)}";
            StatusText.Text = pix.Reused ? "Pix já existente. Aguardando pagamento..." : "Pix pronto. Aguardando pagamento...";

            try
            {
                QrImage.Source = QrCodeRenderer.Create(pix.CopyPaste, 6);
                QrBorder.Visibility = Visibility.Visible;
            }
            catch
            {
                QrImage.Source = null;
                QrBorder.Visibility = Visibility.Collapsed;
                StatusText.Text = "Pix pronto. Use o código Copia e Cola abaixo.";
            }

            PollingText.Text = "Verificando pagamento automaticamente...";
            _pollTimer.Start();
            await CheckPaymentAsync();
        }
        catch (Exception ex)
        {
            StatusText.Text = "Não foi possível gerar o Pix.";
            MessageBox.Show(Friendly(ex.Message), "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
            GenerateButton.IsEnabled = true;
        }
    }

    private async void PollTimer_Tick(object? sender, EventArgs e) => await CheckPaymentAsync();

    private async Task CheckPaymentAsync()
    {
        if (_polling || PaymentConfirmed) return;
        _polling = true;
        try
        {
            var response = await _api.PaymentStatusAsync(_orderId);
            var remaining = ReadInt(response.Payment, "remaining_cents");
            var paid = ReadInt(response.Payment, "paid_cents");
            var total = ReadInt(response.Payment, "total_cents");
            if (remaining <= 0 && total > 0)
            {
                PaymentConfirmed = true;
                _pollTimer.Stop();
                StatusText.Text = $"Pagamento confirmado: {Money(total)}.";
                PollingText.Text = "Pagamento recebido.";
                GenerateButton.IsEnabled = false;
                CopyButton.IsEnabled = false;
            }
            else
            {
                PollingText.Text = $"Pago {Money(paid)} • falta {Money(remaining)}";
            }
        }
        catch (Exception ex)
        {
            PollingText.Text = $"Não foi possível verificar agora: {Friendly(ex.Message)}";
        }
        finally
        {
            _polling = false;
        }
    }

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(CopyPasteBox.Text)) return;
        try
        {
            Clipboard.SetText(CopyPasteBox.Text);
            StatusText.Text = "Código Pix copiado.";
        }
        catch
        {
            MessageBox.Show("Não foi possível copiar o código.", "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    protected override void OnClosed(EventArgs e)
    {
        _pollTimer.Stop();
        base.OnClosed(e);
    }

    private static bool TryMoney(string text, out int cents)
    {
        cents = 0;
        text = text.Trim().Replace("R$", "", StringComparison.OrdinalIgnoreCase).Trim();
        if (!decimal.TryParse(text, NumberStyles.Number | NumberStyles.AllowCurrencySymbol, PtBr, out var value) &&
            !decimal.TryParse(text, NumberStyles.Number, CultureInfo.InvariantCulture, out value)) return false;
        if (value <= 0 || value > 21_000_000m) return false;
        cents = (int)Math.Round(value * 100m, MidpointRounding.AwayFromZero);
        return true;
    }

    private static int ReadInt(Dictionary<string, System.Text.Json.JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == System.Text.Json.JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private static string Money(int cents) => (cents / 100m).ToString("C2", PtBr);

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir o pagamento. Tente novamente."
            : message;
    }
}
