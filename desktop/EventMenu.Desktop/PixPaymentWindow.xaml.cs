using System.Globalization;
using System.Windows;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
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
            MessageBox.Show("Informe um CPF ou CNPJ válido.", "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(AmountBox.Text, out var amountCents) || amountCents <= 0)
        {
            MessageBox.Show("Informe um valor maior que zero.", "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        GenerateButton.IsEnabled = false;
        StatusText.Text = "Gerando cobrança PIX no servidor...";
        try
        {
            var response = await _api.PixCreateAsync(_orderId, taxId, amountCents);
            var pix = response.Pix ?? throw new ApiClientException("O servidor não retornou a cobrança PIX.");
            if (string.IsNullOrWhiteSpace(pix.CopyPaste)) throw new ApiClientException("O código PIX não foi retornado pelo provedor.");

            CopyPasteBox.Text = pix.CopyPaste;
            CopyPasteBox.Visibility = Visibility.Visible;
            CopyPasteLabel.Visibility = Visibility.Visible;
            CopyButton.Visibility = Visibility.Visible;
            ExpiresText.Text = string.IsNullOrWhiteSpace(pix.ExpiresAt) ? "" : $"Validade informada pelo provedor: {pix.ExpiresAt}";
            StatusText.Text = pix.Reused ? "Cobrança PIX já existente reutilizada. Aguardando pagamento..." : "PIX criado. Aguardando pagamento...";

            if (!string.IsNullOrWhiteSpace(pix.ImageUrl) && Uri.TryCreate(pix.ImageUrl, UriKind.Absolute, out var imageUri))
            {
                try
                {
                    var bitmap = new BitmapImage();
                    bitmap.BeginInit();
                    bitmap.CacheOption = BitmapCacheOption.OnLoad;
                    bitmap.UriSource = imageUri;
                    bitmap.EndInit();
                    bitmap.Freeze();
                    QrImage.Source = bitmap;
                    QrBorder.Visibility = Visibility.Visible;
                }
                catch
                {
                    QrBorder.Visibility = Visibility.Collapsed;
                }
            }
            else
            {
                QrBorder.Visibility = Visibility.Collapsed;
            }

            PollingText.Text = "Verificando confirmação automaticamente...";
            _pollTimer.Start();
            await CheckPaymentAsync();
        }
        catch (Exception ex)
        {
            StatusText.Text = "Não foi possível gerar o PIX.";
            MessageBox.Show(ex.Message, "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
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
                PollingText.Text = "PIX confirmado pelo servidor.";
                GenerateButton.IsEnabled = false;
                CopyButton.IsEnabled = false;
            }
            else
            {
                PollingText.Text = $"Pago {Money(paid)} • aguardando {Money(remaining)}";
            }
        }
        catch (Exception ex)
        {
            PollingText.Text = $"Não foi possível consultar agora: {ex.Message}";
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
            StatusText.Text = "Código PIX copiado.";
        }
        catch
        {
            MessageBox.Show("Não foi possível copiar o código para a área de transferência.", "PIX", MessageBoxButton.OK, MessageBoxImage.Warning);
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
}
