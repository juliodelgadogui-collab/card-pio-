using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ProductSplitPaymentWindow : Window
{
    private readonly SecureSessionStore _store;
    private readonly TabPaymentApiClient _api;
    private readonly int _tabId;
    private readonly bool _canCash;
    private TabPaymentGroup? _group;
    private bool _busy;

    public bool PaymentChanged { get; private set; }

    public ProductSplitPaymentWindow(SecureSessionStore store, TabPaymentApiClient api, int tabId, bool canCash)
    {
        _store = store;
        _api = api;
        _tabId = tabId;
        _canCash = canCash;
        InitializeComponent();
        PaymentMethodCombo.Items.Add(new ComboBoxItem { Content = "Pix", Tag = "pix" });
        if (canCash) PaymentMethodCombo.Items.Add(new ComboBoxItem { Content = "Dinheiro", Tag = "cash" });
        PaymentMethodCombo.SelectedIndex = 0;
        Loaded += async (_, _) => await LoadAsync();
    }

    private async Task LoadAsync()
    {
        if (_busy) return;
        _busy = true;
        ChargeButton.IsEnabled = false;
        FooterText.Text = "Carregando produtos da comanda...";
        try
        {
            var account = (await _api.AccountAsync(_tabId)).Account
                ?? throw new InvalidOperationException("Comanda não encontrada.");
            if (account.OpenGroup is not null)
            {
                _group = account.OpenGroup;
                ItemsGrid.ItemsSource = Array.Empty<TabAccountItem>();
                FooterText.Text = "Existe uma cobrança em andamento nesta comanda. Finalize ou cancele antes de iniciar outra divisão.";
                return;
            }

            _group = null;
            var available = account.Items.Where(x => x.SplitUsed != 1).ToList();
            ItemsGrid.ItemsSource = available;
            AvailableCountText.Text = $"{available.Count} disponível(is)";
            var used = account.Items.Count - available.Count;
            FooterText.Text = used > 0
                ? $"{used} item(ns) já fazem parte de outra divisão e não podem ser selecionados novamente."
                : "Selecione uma ou mais linhas para montar esta cobrança.";
            RefreshSelection();
        }
        catch (Exception ex)
        {
            FooterText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            RefreshSelection();
        }
    }

    private void RefreshSelection()
    {
        var selected = ItemsGrid.SelectedItems.Cast<TabAccountItem>().ToList();
        var total = selected.Sum(x => x.TotalCents);
        SelectedCountText.Text = selected.Count.ToString();
        SelectedTotalText.Text = TabPaymentDisplay.Money(total);
        var method = SelectedTag();
        PixPanel.Visibility = method == "pix" ? Visibility.Visible : Visibility.Collapsed;
        ChargeButton.Content = method == "cash" ? "Receber em dinheiro" : "Gerar Pix";
        ChargeButton.IsEnabled = !_busy && _group is null && selected.Count > 0;
        StatusText.Text = selected.Count == 0
            ? "Selecione os produtos que serão pagos juntos."
            : $"Esta parte da conta será de {TabPaymentDisplay.Money(total)} por {selected.Count} item(ns).";
    }

    private async Task ChargeAsync()
    {
        if (_busy || _group is not null) return;
        var ids = ItemsGrid.SelectedItems.Cast<TabAccountItem>().Select(x => x.OrderItemId).Distinct().ToArray();
        if (ids.Length == 0) return;
        var method = SelectedTag();
        if (method == "cash" && !_canCash)
        {
            StatusText.Text = "Seu usuário não pode receber esta conta em dinheiro.";
            return;
        }
        var taxId = Digits(TaxIdBox.Text);
        if (method == "pix" && taxId.Length is not (11 or 14))
        {
            StatusText.Text = "Informe o CPF ou CNPJ do pagador para gerar o Pix.";
            TaxIdBox.Focus();
            return;
        }

        _busy = true;
        ChargeButton.IsEnabled = false;
        StatusText.Text = method == "cash" ? "Registrando recebimento..." : "Preparando Pix...";
        try
        {
            var key = $"desktop-tab-product:{_tabId}:{Guid.NewGuid():N}";
            var response = await _api.CreateGroupAsync(_tabId, "product", method, new { item_ids = ids }, key);
            _group = response.Group ?? throw new InvalidOperationException("Não foi possível iniciar a cobrança.");
            var groupId = _group.Id;
            if (method == "cash")
            {
                if (_group.Status != "paid") throw new InvalidOperationException("O recebimento ainda não foi confirmado.");
                PaymentChanged = true;
                OfferGroupReceipt(groupId);
                DialogResult = true;
                return;
            }

            await GeneratePixAsync(groupId, taxId);
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
            _group = null;
        }
        finally
        {
            _busy = false;
            RefreshSelection();
        }
    }

    private async Task GeneratePixAsync(int groupId, string taxId)
    {
        var response = await _api.CreatePixAsync(groupId, taxId);
        var pix = response.Pix ?? throw new InvalidOperationException("Não foi possível gerar o Pix.");
        if (string.IsNullOrWhiteSpace(pix.CopyPaste)) throw new InvalidOperationException("O código Pix não foi recebido.");

        var window = new ProductSplitPixWindow(_api, groupId, pix) { Owner = this };
        window.ShowDialog();
        if (window.PaymentConfirmed)
        {
            PaymentChanged = true;
            OfferGroupReceipt(groupId);
            DialogResult = true;
        }
        else
        {
            StatusText.Text = "Pix ainda aguardando pagamento. Ao fechar, a conta será atualizada antes de permitir outra cobrança.";
        }
    }

    private void OfferGroupReceipt(int groupId)
    {
        if (groupId < 1) return;
        if (MessageBox.Show(
                "Pagamento confirmado. Deseja abrir o comprovante desta parte da conta?",
                "Comprovante",
                MessageBoxButton.YesNo,
                MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        try
        {
            new GroupReceiptWindow(_store, groupId) { Owner = this }.ShowDialog();
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
    }

    private string SelectedTag() => (PaymentMethodCombo.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "pix";
    private static string Digits(string value) => new(value.Where(char.IsDigit).ToArray());

    private void ItemsGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshSelection();
    private void PaymentMethodCombo_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshSelection();
    private async void ChargeButton_Click(object sender, RoutedEventArgs e) => await ChargeAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir esta cobrança. Atualize a comanda e tente novamente."
            : message;
    }
}
