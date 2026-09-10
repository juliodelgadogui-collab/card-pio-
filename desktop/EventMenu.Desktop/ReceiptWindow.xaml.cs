using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ReceiptWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly int _orderId;
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
        StatusText.Text = "Carregando comprovante...";
        try
        {
            var response = await _api.OrderReceiptAsync(_orderId);
            var receipt = response.Receipt ?? throw new InvalidOperationException("Comprovante não encontrado.");
            Render(receipt);
            StatusText.Text = $"{receipt.ReceiptNumber} • {receipt.CreatedDisplay}";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
        }
    }

    private void Render(OrderReceipt receipt)
    {
        TitleText.Text = $"Comprovante do pedido #{receipt.OrderId}";
        SubtitleText.Text = receipt.CreatedDisplay;

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

    private static string JoinNonEmpty(string separator, params string[] values) =>
        string.Join(separator, values.Where(x => !string.IsNullOrWhiteSpace(x)));

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
