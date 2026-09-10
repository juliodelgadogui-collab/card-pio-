using System.Globalization;
using System.Windows;
using System.Windows.Input;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class DeliveryCashReceiveWindow : Window
{
    private static readonly CultureInfo PtBr = new("pt-BR");
    private readonly OperationalActionsApiClient _api;
    private readonly int _orderId;
    private readonly int _totalCents;
    private bool _busy;

    public bool PaymentChanged { get; private set; }
    public DeliveryCashReceipt? Receipt { get; private set; }

    public DeliveryCashReceiveWindow(OperationalActionsApiClient api, int orderId, int totalCents)
    {
        _api = api;
        _orderId = orderId;
        _totalCents = Math.Max(0, totalCents);
        InitializeComponent();
        TitleText.Text = $"Receber pedido #{orderId}";
        TotalText.Text = OperationalDisplay.Money(_totalCents);
        Loaded += (_, _) =>
        {
            ReceivedBox.Text = (_totalCents / 100m).ToString("N2", PtBr);
            ReceivedBox.Focus();
            ReceivedBox.SelectAll();
            RefreshPreview();
        };
    }

    private bool TryReceived(out int cents)
    {
        cents = 0;
        var raw = ReceivedBox.Text.Trim();
        if (!decimal.TryParse(raw, NumberStyles.Number, PtBr, out var amount)
            && !decimal.TryParse(raw, NumberStyles.Number, CultureInfo.InvariantCulture, out amount))
            return false;
        if (amount <= 0 || amount > 1_000_000m) return false;
        cents = (int)Math.Round(amount * 100m, MidpointRounding.AwayFromZero);
        return cents > 0;
    }

    private void RefreshPreview()
    {
        if (!TryReceived(out var received))
        {
            ChangeText.Text = "—";
            ConfirmButton.IsEnabled = false;
            HintText.Text = "Informe um valor válido recebido do cliente.";
            return;
        }

        var change = Math.Max(0, received - _totalCents);
        ChangeText.Text = OperationalDisplay.Money(change);
        ConfirmButton.IsEnabled = !_busy && received >= _totalCents && _totalCents > 0;
        HintText.Text = received < _totalCents
            ? $"Faltam {OperationalDisplay.Money(_totalCents - received)} para completar o pedido."
            : change > 0
                ? $"Devolva {OperationalDisplay.Money(change)} de troco ao cliente."
                : "Valor exato. Confirme para registrar o pagamento.";
    }

    private async Task ConfirmAsync()
    {
        if (_busy || !TryReceived(out var received)) return;
        if (received < _totalCents)
        {
            StatusText.Text = "O valor recebido é menor que o total do pedido.";
            return;
        }

        _busy = true;
        ConfirmButton.IsEnabled = false;
        StatusText.Text = "Confirmando pagamento...";
        try
        {
            var response = await _api.DeliveryCashCollectAsync(_orderId, received);
            Receipt = response.Receipt ?? throw new InvalidOperationException("O pagamento foi processado sem comprovante de retorno.");
            PaymentChanged = Receipt.Paid;
            if (!PaymentChanged)
                throw new InvalidOperationException("O servidor ainda não confirmou o pagamento.");

            StatusText.Text = Receipt.ChangeCents > 0
                ? $"Pagamento confirmado. Troco: {Receipt.ChangeDisplay}."
                : "Pagamento confirmado.";
            DialogResult = true;
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            if (DialogResult != true) RefreshPreview();
        }
    }

    private void ReceivedBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e) => RefreshPreview();

    private async void ReceivedBox_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key != Key.Enter || !ConfirmButton.IsEnabled) return;
        e.Handled = true;
        await ConfirmAsync();
    }

    private async void ConfirmButton_Click(object sender, RoutedEventArgs e) => await ConfirmAsync();
    private void CancelButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível registrar o pagamento. Atualize a entrega e tente novamente."
            : message;
    }
}
