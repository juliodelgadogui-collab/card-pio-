using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ReceiptWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly int _orderId;
    private OrderReceipt? _receipt;
    private bool _busy;

    public ReceiptWindow(SecureSessionStore store, int orderId)
    {
        _api = new OperationalActionsApiClient(store);
        _orderId = orderId;
        InitializeComponent();
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync()
    {
        if (_busy) return;
        _busy = true;
        PrintButton.IsEnabled = false;
        StatusText.Text = "Carregando cupom...";
        try
        {
            var response = await _api.OrderReceiptAsync(_orderId);
            _receipt = response.Receipt ?? throw new InvalidOperationException("Comprovante não encontrado.");
            Render(_receipt);
            PrintButton.IsEnabled = true;
            StatusText.Text = $"{_receipt.ReceiptNumber} • {_receipt.CreatedDisplay}";
        }
        catch (Exception ex)
        {
            _receipt = null;
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
        }
    }

    private void Render(OrderReceipt receipt)
    {
        TitleText.Text = $"Cupom do pedido #{receipt.OrderId}";
        SubtitleText.Text = $"{receipt.CreatedDisplay} • padrão térmico do site";

        var tradeName = string.IsNullOrWhiteSpace(receipt.Identity.TradeName) ? receipt.TenantName : receipt.Identity.TradeName;
        BusinessNameText.Text = string.IsNullOrWhiteSpace(tradeName) ? "EventMenu" : tradeName;
        BusinessDetailText.Text = JoinNonEmpty("\n", receipt.Identity.LegalName, DocumentLine(receipt.Identity.Document), receipt.Identity.Address);
        IdentityExtraText.Text = JoinNonEmpty(" • ", PhoneLine(receipt.Identity.Phone), receipt.Identity.Email);
        IdentityExtraText.Visibility = string.IsNullOrWhiteSpace(IdentityExtraText.Text) ? Visibility.Collapsed : Visibility.Visible;

        // 58 mm and 80 mm use the same proportions as the website receipt.
        ReceiptPaper.Width = receipt.Identity.PaperWidth == "58" ? 326 : 430;

        ReceiptNumberText.Text = $"Documento operacional EventMenu · {receipt.ReceiptNumber}";
        OrderText.Text = $"#{receipt.OrderId}";
        ReceiptDateText.Text = receipt.CreatedDisplay;
        ReceiptChannelText.Text = ChannelLabel(receipt.Channel);
        ReceiptCustomerText.Text = receipt.CustomerName;
        ReceiptPhoneText.Text = receipt.CustomerPhone;
        ReceiptLocationText.Text = JoinNonEmpty(" • ", receipt.TableName, receipt.TabLabel);
        ReceiptOperatorText.Text = receipt.CreatedByName;

        CustomerRow.Visibility = Has(receipt.CustomerName);
        PhoneRow.Visibility = Has(receipt.CustomerPhone);
        LocationRow.Visibility = Has(ReceiptLocationText.Text);
        OperatorRow.Visibility = Has(receipt.CreatedByName);

        ReceiptItemsList.ItemsSource = receipt.Items;
        ReceiptPaymentsList.ItemsSource = receipt.Payments;

        SubtotalText.Text = receipt.SubtotalDisplay;
        DiscountText.Text = $"- {receipt.DiscountDisplay}";
        DeliveryFeeText.Text = receipt.DeliveryFeeDisplay;
        DiscountRow.Visibility = receipt.DiscountCents > 0 ? Visibility.Visible : Visibility.Collapsed;
        DeliveryFeeRow.Visibility = receipt.DeliveryFeeCents > 0 ? Visibility.Visible : Visibility.Collapsed;
        TotalText.Text = receipt.TotalDisplay;
        PaidText.Text = receipt.PaidDisplay;
        RemainingText.Text = receipt.RemainingDisplay;
        RemainingRow.Visibility = receipt.RemainingCents > 0 ? Visibility.Visible : Visibility.Collapsed;

        FooterMessageText.Text = string.IsNullOrWhiteSpace(receipt.Identity.Footer) ? "Obrigado pela preferência!" : receipt.Identity.Footer;
        PrintedAtText.Text = $"EventMenu · visualização em {DateTime.Now:dd/MM/yyyy HH:mm:ss}";
    }

    private void PrintButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || _receipt is null) return;
        try
        {
            StatusText.Text = SiteReceiptPrinter.Print(_receipt)
                ? "Cupom enviado para impressão."
                : "Impressão cancelada.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
    }

    private static Visibility Has(string? value) => string.IsNullOrWhiteSpace(value) ? Visibility.Collapsed : Visibility.Visible;
    private static string DocumentLine(string value) => string.IsNullOrWhiteSpace(value) ? "" : $"CPF/CNPJ: {value}";
    private static string PhoneLine(string value) => string.IsNullOrWhiteSpace(value) ? "" : $"Tel/WhatsApp: {value}";

    private static string ChannelLabel(string channel) => channel switch
    {
        "counter" => "Balcão / PDV",
        "pickup" => "Retirada",
        "delivery" => "Delivery",
        "table" => "Mesa / comanda",
        "event" => "Evento / Ingresso",
        "bar" or "event_bar" => "Evento / Bar",
        _ => channel
    };

    private static string JoinNonEmpty(string separator, params string[] values) =>
        string.Join(separator, values.Where(x => !string.IsNullOrWhiteSpace(x)));

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível carregar ou imprimir o comprovante."
            : message;
    }
}
