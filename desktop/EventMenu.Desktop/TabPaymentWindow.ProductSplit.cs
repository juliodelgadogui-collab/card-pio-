using System.Windows;

namespace EventMenu.Desktop;

public partial class TabPaymentWindow
{
    private async void ProductSplitButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || _account is null || _account.RemainingCents <= 0) return;
        if (_currentGroup is not null)
        {
            FooterStatusText.Text = "Finalize ou cancele a cobrança em andamento antes de dividir por produtos.";
            return;
        }

        var window = new ProductSplitPaymentWindow(_store, _api, _tabId, _canCash) { Owner = this };
        window.ShowDialog();
        if (window.PaymentChanged) PaymentChanged = true;

        // Sempre relê a conta: um Pix pode ter sido criado e continuar pendente mesmo que a janela seja fechada.
        await LoadAccountAsync();
    }
}
