using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ShiftSummaryWindow : Window
{
    private readonly EventMenuApiClient _api;
    private bool _loading;

    public ShiftSummaryWindow(SecureSessionStore store)
    {
        _api = new EventMenuApiClient(store);
        InitializeComponent();
        Loaded += async (_, _) => await LoadAsync();
        Closed += (_, _) => _api.Dispose();
    }

    private async Task LoadAsync()
    {
        if (_loading) return;
        _loading = true;
        FooterStatusText.Text = "Atualizando resumo do turno...";
        try
        {
            var summary = (await _api.ShiftSummaryAsync()).Summary;
            if (summary?.Shift is null)
            {
                ClearView();
                FooterStatusText.Text = "Nenhum turno foi encontrado para este usuário.";
                return;
            }

            var shift = summary.Shift;
            ShiftSubtitleText.Text = $"{shift.UserName} • turno #{shift.Id}";
            OrdersCountText.Text = summary.Orders.Qty.ToString();
            OrdersTotalText.Text = summary.Orders.TotalDisplay;
            StartedText.Text = shift.StartedDisplay;
            UnitText.Text = string.IsNullOrWhiteSpace(shift.UnitName) ? "Sem unidade" : shift.UnitName;
            ModeText.Text = shift.ModeDisplay;
            ShiftStateText.Text = shift.Status == "open" ? "Em andamento" : $"Encerrado • {shift.EndedDisplay}";
            MethodsGrid.ItemsSource = summary.ByMethod;

            if (summary.DeliveryCash is { } cash)
            {
                DeliveryCashCard.Visibility = Visibility.Visible;
                CashCollectedText.Text = $"Recebido em dinheiro: {cash.CollectedDisplay}";
                CashHandoffText.Text = $"Entregue ao caixa: {cash.HandoffDisplay}";
                CashOutstandingText.Text = $"Pendente de entrega: {cash.OutstandingDisplay}";
            }
            else DeliveryCashCard.Visibility = Visibility.Collapsed;

            if (summary.DeliveryCommission is { } commission)
            {
                CommissionCard.Visibility = Visibility.Visible;
                CommissionDeliveriesText.Text = $"{commission.Deliveries} entrega(s) concluída(s)";
                CommissionRevenueText.Text = $"Vendas entregues: {commission.RevenueDisplay}";
                CommissionTotalText.Text = $"Comissão: {commission.CommissionDisplay}";
            }
            else CommissionCard.Visibility = Visibility.Collapsed;

            FooterStatusText.Text = "Resumo atualizado com os movimentos registrados neste turno.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
    }

    private void ClearView()
    {
        ShiftSubtitleText.Text = "Resumo das operações realizadas neste turno.";
        OrdersCountText.Text = "—";
        OrdersTotalText.Text = "";
        StartedText.Text = "—";
        UnitText.Text = "";
        ModeText.Text = "—";
        ShiftStateText.Text = "";
        MethodsGrid.ItemsSource = null;
        DeliveryCashCard.Visibility = Visibility.Collapsed;
        CommissionCard.Visibility = Visibility.Collapsed;
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível carregar o resumo do turno."
            : message;
    }
}
