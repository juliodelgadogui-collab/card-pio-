using System.Globalization;
using System.Text.Json;
using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class OrderPaymentWindow : Window
{
    private static readonly CultureInfo PtBr = new("pt-BR");
    private readonly EventMenuApiClient _api;
    private readonly Order _order;
    private readonly bool _canCash;
    private int _remainingCents;
    private bool _loading;

    public bool PaymentChanged { get; private set; }

    public OrderPaymentWindow(EventMenuApiClient api, Order order, bool canCash)
    {
        InitializeComponent();
        _api = api;
        _order = order;
        _canCash = canCash;
        TitleText.Text = $"Receber pedido #{order.Id}";
        SubtitleText.Text = string.IsNullOrWhiteSpace(order.CustomerName)
            ? $"{order.ChannelDisplay} • confira o valor antes de receber."
            : $"{order.CustomerName} • {order.ChannelDisplay}";
        CashButton.Visibility = canCash ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) => await RefreshAsync(true);
    }

    private async Task RefreshAsync(bool showError)
    {
        if (_loading) return;
        _loading = true;
        try
        {
            StatusText.Text = "Atualizando valores...";
            var response = await _api.PaymentStatusAsync(_order.Id);
            var total = Value(response.Payment, "total_cents");
            var paid = Value(response.Payment, "paid_cents");
            _remainingCents = Value(response.Payment, "remaining_cents");

            TotalText.Text = Money(total > 0 ? total : _order.TotalCents);
            PaidText.Text = Money(paid);
            RemainingText.Text = Money(_remainingCents);
            AmountBox.Text = _remainingCents > 0 ? (_remainingCents / 100m).ToString("N2", PtBr) : "";

            var closed = _order.Status is "cancelled" or "completed";
            var canReceive = _remainingCents > 0 && !closed;
            CashButton.IsEnabled = canReceive && _canCash;
            PixButton.IsEnabled = canReceive;
            AmountBox.IsEnabled = canReceive;
            PaidBanner.Visibility = _remainingCents <= 0 ? Visibility.Visible : Visibility.Collapsed;
            StatusText.Text = closed && _remainingCents > 0
                ? "Este pedido está encerrado e não aceita novas cobranças."
                : _remainingCents <= 0
                    ? "O pedido está totalmente pago."
                    : "Escolha a forma de pagamento.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
            if (showError) MessageBox.Show(Friendly(ex.Message), "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            _loading = false;
        }
    }

    private async void CashButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canCash || _remainingCents <= 0) return;
        if (!TryAmount(out var amountCents)) return;
        if (amountCents > _remainingCents)
        {
            MessageBox.Show("O valor informado é maior que o restante do pedido.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        CashButton.IsEnabled = false;
        PixButton.IsEnabled = false;
        try
        {
            StatusText.Text = "Registrando pagamento...";
            var key = $"desktop-cash:{_order.Id}:{Guid.NewGuid():N}";
            await _api.PaymentCashAsync(_order.Id, amountCents, key);
            PaymentChanged = true;
            await RefreshAsync(false);
        }
        catch (Exception ex)
        {
            MessageBox.Show(Friendly(ex.Message), "Pagamento", MessageBoxButton.OK, MessageBoxImage.Warning);
            await RefreshAsync(false);
        }
    }

    private async void PixButton_Click(object sender, RoutedEventArgs e)
    {
        if (_remainingCents <= 0) return;
        if (!TryAmount(out var amountCents)) return;
        if (amountCents > _remainingCents)
        {
            MessageBox.Show("O valor informado é maior que o restante do pedido.", "Pix", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        var window = new PixPaymentWindow(_api, _order.Id, amountCents) { Owner = this };
        window.ShowDialog();
        if (window.PaymentConfirmed) PaymentChanged = true;
        await RefreshAsync(false);
    }

    private bool TryAmount(out int amountCents)
    {
        amountCents = 0;
        var value = AmountBox.Text.Trim();
        if (!decimal.TryParse(value, NumberStyles.Number, PtBr, out var amount)
            && !decimal.TryParse(value, NumberStyles.Number, CultureInfo.InvariantCulture, out amount))
        {
            MessageBox.Show("Informe um valor válido.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Information);
            AmountBox.Focus();
            AmountBox.SelectAll();
            return false;
        }
        if (amount <= 0)
        {
            MessageBox.Show("Informe um valor maior que zero.", "Pagamento", MessageBoxButton.OK, MessageBoxImage.Information);
            AmountBox.Focus();
            AmountBox.SelectAll();
            return false;
        }
        amountCents = (int)Math.Round(amount * 100m, MidpointRounding.AwayFromZero);
        return amountCents > 0;
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await RefreshAsync(true);
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static int Value(Dictionary<string, JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
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
