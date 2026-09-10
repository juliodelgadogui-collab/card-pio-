using System.Windows;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ProductSplitPixWindow : Window
{
    private readonly TabPaymentApiClient _api;
    private readonly int _groupId;
    private readonly DispatcherTimer _timer;
    private bool _checking;

    public bool PaymentConfirmed { get; private set; }

    public ProductSplitPixWindow(TabPaymentApiClient api, int groupId, TabPixCharge pix)
    {
        _api = api;
        _groupId = groupId;
        InitializeComponent();
        AmountText.Text = $"Valor: {pix.AmountDisplay}";
        PixCodeBox.Text = pix.CopyPaste;
        ExpiryText.Text = string.IsNullOrWhiteSpace(pix.ExpiresAt) ? "" : $"Válido até {pix.ExpiresDisplay}";
        QrImage.Source = QrCodeRenderer.Create(pix.CopyPaste, 6);
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(4) };
        _timer.Tick += Timer_Tick;
        Loaded += async (_, _) =>
        {
            _timer.Start();
            await CheckAsync();
        };
    }

    private async Task CheckAsync()
    {
        if (_checking || PaymentConfirmed) return;
        _checking = true;
        try
        {
            var response = await _api.PixStatusAsync(_groupId);
            if (response.Paid || response.Group?.Status == "paid")
            {
                PaymentConfirmed = true;
                _timer.Stop();
                StatusText.Text = "Pagamento confirmado.";
            }
            else
            {
                StatusText.Text = response.Group?.Status == "attention"
                    ? "Pagamento recebido, mas a conta precisa de conferência."
                    : "Aguardando pagamento...";
            }
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally { _checking = false; }
    }

    private async void Timer_Tick(object? sender, EventArgs e) => await CheckAsync();
    private async void CheckButton_Click(object sender, RoutedEventArgs e) => await CheckAsync();

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PixCodeBox.Text)) return;
        try
        {
            Clipboard.SetText(PixCodeBox.Text);
            StatusText.Text = "Código Pix copiado.";
        }
        catch
        {
            StatusText.Text = "Não foi possível copiar o código Pix.";
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    protected override void OnClosed(EventArgs e)
    {
        _timer.Stop();
        base.OnClosed(e);
    }

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível verificar o pagamento agora."
            : message;
    }
}
