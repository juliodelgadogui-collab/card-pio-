using System.Globalization;
using System.Windows;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class OrderRequestWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private readonly int _orderId;
    private readonly bool _discount;
    private readonly int _orderTotalCents;
    private bool _busy;

    public bool Submitted { get; private set; }

    public OrderRequestWindow(SecureSessionStore store, int orderId, int orderTotalCents, bool discount)
    {
        _api = new OperationalActionsApiClient(store);
        _orderId = orderId;
        _orderTotalCents = orderTotalCents;
        _discount = discount;
        InitializeComponent();
        Closed += (_, _) => _api.Dispose();

        if (_discount)
        {
            Title = "Solicitar desconto • EventMenu";
            TitleText.Text = "Solicitar desconto";
            SubtitleText.Text = $"Pedido #{orderId} • total {(orderTotalCents / 100m).ToString("C2", new CultureInfo("pt-BR"))}";
            AmountPanel.Visibility = Visibility.Visible;
            InfoText.Text = "O desconto só será aplicado depois da autorização exigida pela empresa. Pedido já pago ou com cobrança em andamento não aceita alteração de valor.";
            Loaded += (_, _) => AmountBox.Focus();
        }
        else
        {
            Title = "Solicitar cancelamento • EventMenu";
            TitleText.Text = "Solicitar cancelamento";
            SubtitleText.Text = $"Pedido #{orderId}";
            AmountPanel.Visibility = Visibility.Collapsed;
            ReasonLabel.Text = "Motivo do cancelamento";
            InfoText.Text = "O pedido não será cancelado imediatamente. A solicitação seguirá para autorização quando a função do usuário exigir aprovação.";
            Loaded += (_, _) => ReasonBox.Focus();
        }
    }

    private async void SubmitButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy) return;
        var reason = ReasonBox.Text.Trim();
        if (reason.Length < 3)
        {
            StatusText.Text = "Informe um motivo para continuar.";
            ReasonBox.Focus();
            return;
        }

        var amountCents = 0;
        if (_discount)
        {
            if (!decimal.TryParse(AmountBox.Text.Trim(), NumberStyles.Number, new CultureInfo("pt-BR"), out var amount) || amount <= 0)
            {
                StatusText.Text = "Informe um valor de desconto válido.";
                AmountBox.Focus();
                return;
            }
            amountCents = (int)Math.Round(amount * 100m, MidpointRounding.AwayFromZero);
            if (amountCents > _orderTotalCents)
            {
                StatusText.Text = "O desconto não pode ser maior que o total atual do pedido.";
                AmountBox.Focus();
                return;
            }
        }

        _busy = true;
        SubmitButton.IsEnabled = false;
        StatusText.Text = "Enviando solicitação...";
        try
        {
            if (_discount) await _api.RequestDiscountAsync(_orderId, amountCents, reason);
            else await _api.RequestCancellationAsync(_orderId, reason);

            Submitted = true;
            StatusText.Text = "Solicitação enviada.";
            DialogResult = true;
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
            SubmitButton.IsEnabled = true;
        }
        finally
        {
            _busy = false;
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
