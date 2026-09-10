using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class GroupReceiptWindow : Window
{
    private readonly GroupReceiptApiClient _api;
    private readonly int _groupId;
    private GroupReceipt? _receipt;
    private bool _busy;

    public GroupReceiptWindow(SecureSessionStore store, int groupId)
    {
        _api = new GroupReceiptApiClient(store);
        _groupId = groupId;
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
            _receipt = (await _api.GetAsync(_groupId)).Receipt
                ?? throw new InvalidOperationException("Comprovante da divisão não encontrado.");
            Render(_receipt);
            PrintButton.IsEnabled = true;
            StatusText.Text = $"{_receipt.ReceiptNumber} • {_receipt.DateDisplay}";
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

    private void Render(GroupReceipt receipt)
    {
        SubtitleText.Text = $"{receipt.TenantName} • {receipt.ReceiptNumber} • {receipt.DateDisplay}";
        AmountText.Text = receipt.AmountDisplay;
        MethodText.Text = receipt.MethodDisplay;
        SplitText.Text = receipt.SplitDisplay;
        StateText.Text = receipt.StatusDisplay;
        ItemsGrid.ItemsSource = receipt.Items;
        AllocationsGrid.ItemsSource = receipt.Allocations;

        var location = string.Join(" • ", new[]
        {
            receipt.TableName,
            receipt.TabId.HasValue ? $"Comanda #{receipt.TabId.Value}" : "",
            receipt.TabLabel
        }.Where(x => !string.IsNullOrWhiteSpace(x)));
        LocationText.Text = string.IsNullOrWhiteSpace(location) ? "Divisão da conta" : location;
        OperatorText.Text = string.IsNullOrWhiteSpace(receipt.OperatorName) ? "" : $"Operador: {receipt.OperatorName}";
        ConfirmedText.Text = $"Confirmado: {receipt.ConfirmedDisplay}";
    }

    private void PrintButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || _receipt is null) return;
        try
        {
            StatusText.Text = ReceiptPrinter.PrintGroupReceipt(_receipt)
                ? "Comprovante enviado para impressão."
                : "Impressão cancelada.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível carregar ou imprimir este comprovante."
            : message;
    }
}
