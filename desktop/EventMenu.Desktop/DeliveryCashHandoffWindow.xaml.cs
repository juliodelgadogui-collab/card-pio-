using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class DeliveryCashHandoffWindow : Window
{
    private readonly OperationalActionsApiClient _api;
    private DeliveryCashBalance? _balance;
    private DeliveryCashHandoff? _handoff;
    private bool _busy;

    public bool HandoffChanged { get; private set; }

    public DeliveryCashHandoffWindow(OperationalActionsApiClient api)
    {
        _api = api;
        InitializeComponent();
        Loaded += async (_, _) => await LoadBalanceAsync();
    }

    private async Task LoadBalanceAsync()
    {
        if (_busy) return;
        _busy = true;
        CreateQrButton.IsEnabled = false;
        FooterStatusText.Text = "Atualizando valores...";
        try
        {
            _balance = (await _api.DeliveryCashOutstandingAsync()).Cash
                ?? throw new InvalidOperationException("Não foi possível carregar o saldo do turno.");

            CollectedText.Text = _balance.CollectedDisplay;
            ConfirmedText.Text = _balance.ConfirmedDisplay;
            OutstandingText.Text = _balance.OutstandingDisplay;
            CreateQrButton.IsEnabled = _balance.OutstandingCents > 0;

            if (_handoff is not null && _balance.OutstandingCents == 0)
            {
                HandoffStatusText.Text = "Repasse confirmado pelo caixa.";
                QrHintText.Text = "O valor foi recebido e registrado no caixa.";
                HandoffChanged = true;
            }
            else if (_handoff is not null)
            {
                HandoffStatusText.Text = "QR gerado • aguardando o caixa confirmar.";
                QrHintText.Text = "Peça ao caixa para ler este QR Code no EventMenu Desktop.";
            }
            else
            {
                HandoffStatusText.Text = _balance.OutstandingCents > 0
                    ? "Há dinheiro pendente para repasse."
                    : "Nenhum valor pendente para entregar ao caixa.";
            }

            FooterStatusText.Text = _balance.OutstandingCents > 0
                ? $"Valor pendente: {_balance.OutstandingDisplay}."
                : "Seu repasse está em dia.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            CreateQrButton.IsEnabled = (_balance?.OutstandingCents ?? 0) > 0;
        }
    }

    private async Task CreateQrAsync()
    {
        if (_busy || (_balance?.OutstandingCents ?? 0) <= 0) return;
        _busy = true;
        CreateQrButton.IsEnabled = false;
        FooterStatusText.Text = "Gerando QR Code...";
        try
        {
            _handoff = (await _api.CreateDeliveryHandoffAsync()).Handoff
                ?? throw new InvalidOperationException("O servidor não retornou o repasse.");
            if (string.IsNullOrWhiteSpace(_handoff.QrPayload))
                throw new InvalidOperationException("O servidor não retornou o conteúdo do QR Code.");

            QrImage.Source = QrCodeRenderer.Create(_handoff.QrPayload, 7);
            QrAmountText.Text = _handoff.AmountDisplay;
            QrHintText.Text = "Entregue o dinheiro e peça ao caixa para ler este QR Code.";
            HandoffStatusText.Text = _handoff.StatusDisplay;
            FooterStatusText.Text = "QR pronto para conferência no caixa.";
        }
        catch (Exception ex)
        {
            QrImage.Source = null;
            QrAmountText.Text = "Não foi possível gerar";
            QrHintText.Text = "Atualize a tela e tente novamente.";
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            CreateQrButton.IsEnabled = (_balance?.OutstandingCents ?? 0) > 0;
        }
    }

    private async void CreateQrButton_Click(object sender, RoutedEventArgs e) => await CreateQrAsync();
    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadBalanceAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível atualizar o repasse. Tente novamente."
            : message;
    }
}
