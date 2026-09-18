using System.Globalization;
using System.Text.Json;
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
    private int? _pixPaymentId;
    private string _pixProvider = "Pix";

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
        if (taxId.Length != 0 && taxId.Length is not (11 or 14))
        {
            MessageBox.Show("Se informar CPF ou CNPJ, use um documento válido. Você também pode deixar o campo vazio para o servidor usar os dados configurados da empresa.", "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(AmountBox.Text, out var amountCents) || amountCents <= 0)
        {
            MessageBox.Show("Informe um valor maior que zero.", "Pix", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        GenerateButton.IsEnabled = false;
        StatusText.Text = "Preparando Pix no servidor...";
        try
        {
            // Provider selection belongs to EventMenu Server. Desktop intentionally
            // sends no provider and only renders the normalized charge returned.
            var response = await _api.PixCreateAsync(_orderId, taxId, amountCents);
            var pix = response.Pix ?? throw new ApiClientException("Não foi possível gerar o Pix.");
            if (string.IsNullOrWhiteSpace(pix.CopyPaste)) throw new ApiClientException("O código Pix não foi recebido.");

            _pixPaymentId = pix.PaymentId;
            _pixProvider = pix.ProviderDisplay;
            CopyPasteBox.Text = pix.CopyPaste;
            CopyPasteBox.Visibility = Visibility.Visible;
            CopyPasteLabel.Visibility = Visibility.Visible;
            CopyButton.Visibility = Visibility.Visible;
            ExpiresText.Text = string.IsNullOrWhiteSpace(pix.ExpiresAt) ? "" : $"Válido até {ServerTimeDisplay.Local(pix.ExpiresAt)}";
            StatusText.Text = pix.Reused
                ? $"Pix {_pixProvider} já existente. Aguardando pagamento..."
                : $"Pix {_pixProvider} pronto. Aguardando pagamento...";

            try
            {
                QrImage.Source = QrCodeRenderer.Create(pix.CopyPaste, 6);
                QrBorder.Visibility = Visibility.Visible;
            }
            catch
            {
                QrImage.Source = null;
                QrBorder.Visibility = Visibility.Collapsed;
                StatusText.Text = $"Pix {_pixProvider} pronto. Use o código Copia e Cola abaixo.";
            }

            PollingText.Text = "Verificando confirmação no servidor...";
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
            var latestPixId = ReadNestedInt(response.Payment, "latest_pix", "payment_id");
            var latestPixStatus = ReadNestedString(response.Payment, "latest_pix", "status");
            var latestProvider = ProviderDisplay(ReadNestedString(response.Payment, "latest_pix", "provider"));

            if (remaining <= 0 && total > 0)
            {
                PaymentConfirmed = true;
                _pollTimer.Stop();
                StatusText.Text = $"Pagamento confirmado: {Money(total)}.";
                PollingText.Text = "Pagamento recebido e confirmado pelo servidor.";
                GenerateButton.IsEnabled = false;
                CopyButton.IsEnabled = false;
                return;
            }

            if (_pixPaymentId.HasValue && latestPixId == _pixPaymentId.Value)
            {
                if (latestPixStatus == "paid")
                {
                    StatusText.Text = $"Pix {latestProvider} confirmado. O pedido ainda possui saldo de {Money(remaining)}.";
                }
                else if (latestPixStatus is "failed" or "cancelled")
                {
                    _pollTimer.Stop();
                    StatusText.Text = $"A cobrança Pix {latestProvider} não foi concluída. Você pode gerar uma nova cobrança.";
                    PollingText.Text = "Cobrança encerrada sem confirmação.";
                    GenerateButton.IsEnabled = true;
                    return;
                }
            }

            PollingText.Text = $"Pago {Money(paid)} • falta {Money(remaining)}";
        }
        catch (Exception ex)
        {
            // A transient status failure must never break the POS. Keep the QR usable
            // and let the next polling cycle retry automatically.
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
            StatusText.Text = $"Código Pix {_pixProvider} copiado.";
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

    private static int ReadInt(Dictionary<string, JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private static int ReadNestedInt(Dictionary<string, JsonElement>? data, string objectKey, string key)
    {
        if (data is null || !data.TryGetValue(objectKey, out var obj) || obj.ValueKind != JsonValueKind.Object) return 0;
        if (!obj.TryGetProperty(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private static string ReadNestedString(Dictionary<string, JsonElement>? data, string objectKey, string key)
    {
        if (data is null || !data.TryGetValue(objectKey, out var obj) || obj.ValueKind != JsonValueKind.Object) return "";
        return obj.TryGetProperty(key, out var value) && value.ValueKind != JsonValueKind.Null ? value.ToString() : "";
    }

    private static string ProviderDisplay(string provider) => provider switch
    {
        "mercadopago" => "Mercado Pago",
        "pagbank" => "PagBank",
        _ => string.IsNullOrWhiteSpace(provider) ? "Pix" : provider
    };

    private static string Money(int cents) => (cents / 100m).ToString("C2", PtBr);

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir o pagamento. Tente novamente."
            : message;
    }
}
