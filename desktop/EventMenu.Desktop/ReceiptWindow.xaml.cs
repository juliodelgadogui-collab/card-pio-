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
        StatusText.Text = "Carregando comprovante...";
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
        TitleText.Text = $"Comprovante do pedido #{receipt.OrderId}";
        SubtitleText.Text = $"{receipt.CreatedDisplay} • comprovante não fiscal";

        var tradeName = string.IsNullOrWhiteSpace(receipt.Identity.TradeName) ? receipt.TenantName : receipt.Identity.TradeName;
        BusinessNameText.Text = tradeName;
        BusinessDetailText.Text = JoinNonEmpty(" • ", receipt.Identity.Document, receipt.Identity.Address, receipt.Identity.Phone);

        OrderText.Text = $"#{receipt.OrderId}";
        CustomerText.Text = JoinNonEmpty(" • ", receipt.CustomerDisplay, receipt.LocationDisplay);
        TotalText.Text = receipt.TotalDisplay;
        PaidText.Text = receipt.RemainingCents <= 0
            ? $"Pago: {receipt.PaidDisplay}"
            : $"Pago: {receipt.PaidDisplay} • falta {receipt.RemainingDisplay}";

        ReceiptItemsGrid.ItemsSource = receipt.Items;
        PaymentsGrid.ItemsSource = receipt.Payments;

        SubtotalText.Text = receipt.SubtotalDisplay;
        DiscountText.Text = receipt.DiscountCents > 0 ? $"- {receipt.DiscountDisplay}" : receipt.DiscountDisplay;
        DeliveryFeeText.Text = receipt.DeliveryFeeDisplay;
        SummaryTotalText.Text = receipt.TotalDisplay;
        SummaryPaidText.Text = receipt.PaidDisplay;
        RemainingText.Text = receipt.RemainingDisplay;
        FooterMessageText.Text = string.IsNullOrWhiteSpace(receipt.Identity.Footer) ? "Obrigado pela preferência!" : receipt.Identity.Footer;
    }

    private void PrintButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || _receipt is null) return;
        try
        {
            StatusText.Text = ReceiptPrinter.PrintReceipt(_receipt)
                ? "Comprovante enviado para impressão."
                : "Impressão cancelada.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
    }

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
