using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class TableBoardWindow : Window
{
    private readonly EventMenuApiClient _api;
    private readonly SecureSessionStore _store;
    private readonly bool _canPayments;
    private readonly bool _canCash;
    private bool _loading;

    public bool Changed { get; private set; }

    public TableBoardWindow(EventMenuApiClient api, SecureSessionStore store, bool canPayments, bool canCash)
    {
        _api = api;
        _store = store;
        _canPayments = canPayments;
        _canCash = canCash;
        InitializeComponent();
        PaymentButton.Visibility = _canPayments ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) => await LoadAsync();
    }

    private async Task LoadAsync(int? selectTableId = null)
    {
        if (_loading) return;
        _loading = true;
        OpenButton.IsEnabled = false;
        CloseTabButton.IsEnabled = false;
        PaymentButton.IsEnabled = false;
        try
        {
            var response = await _api.TablesAsync();
            TablesList.ItemsSource = response.Tables;
            var selected = selectTableId.HasValue
                ? response.Tables.FirstOrDefault(x => x.Id == selectTableId.Value)
                : response.Tables.FirstOrDefault();
            TablesList.SelectedItem = selected;
            if (selected is null)
            {
                SelectedTableText.Text = "Nenhuma mesa cadastrada";
                SelectedTableDetailText.Text = "Cadastre as mesas no painel de gestão antes de usar o salão.";
            }
        }
        catch (Exception ex)
        {
            SelectedTableText.Text = "Não foi possível carregar o salão";
            SelectedTableDetailText.Text = ex.Message;
        }
        finally
        {
            _loading = false;
            RefreshSelection();
        }
    }

    private void RefreshSelection()
    {
        if (TablesList.SelectedItem is not TableInfo table)
        {
            OpenButton.IsEnabled = false;
            CloseTabButton.IsEnabled = false;
            PaymentButton.IsEnabled = false;
            return;
        }

        SelectedTableText.Text = table.Name;
        SelectedTableDetailText.Text = table.TabId is > 0
            ? $"{table.StatusDisplay} • {table.UnpaidDisplay} em aberto"
            : $"{table.StatusDisplay} • {table.Seats} lugar(es)";
        TabLabelBox.Text = table.TabLabel ?? "";
        TabLabelBox.IsEnabled = table.TabId is null;
        OpenButton.IsEnabled = !_loading && table.TabId is null && !table.Status.Equals("inactive", StringComparison.OrdinalIgnoreCase);
        PaymentButton.IsEnabled = !_loading && _canPayments && table.TabId is > 0 && table.UnpaidCents > 0;
        CloseTabButton.IsEnabled = !_loading && table.TabId is > 0;
    }

    private async void OpenButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || TablesList.SelectedItem is not TableInfo table || table.TabId is > 0) return;
        _loading = true;
        OpenButton.IsEnabled = false;
        try
        {
            await _api.TableOpenAsync(table.Id, TabLabelBox.Text.Trim());
            Changed = true;
            await LoadAfterMutationAsync(table.Id);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Mesas e comandas", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            _loading = false;
            RefreshSelection();
        }
    }

    private async void PaymentButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || !_canPayments || TablesList.SelectedItem is not TableInfo table || table.TabId is null) return;
        var window = new TabPaymentWindow(_store, table.TabId.Value, _canCash) { Owner = this };
        window.ShowDialog();
        if (window.PaymentChanged)
        {
            Changed = true;
            await LoadAsync(table.Id);
        }
    }

    private async void CloseTabButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || TablesList.SelectedItem is not TableInfo table || table.TabId is null) return;
        if (table.UnpaidCents > 0)
        {
            MessageBox.Show("A comanda ainda possui valor a receber. Quite a conta antes de fechar.", "Mesas e comandas", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        if (MessageBox.Show($"Fechar a comanda da {table.Name}?", "Mesas e comandas", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;

        _loading = true;
        CloseTabButton.IsEnabled = false;
        try
        {
            await _api.TableCloseAsync(table.TabId.Value);
            Changed = true;
            await LoadAfterMutationAsync(table.Id);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Mesas e comandas", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            _loading = false;
            RefreshSelection();
        }
    }

    private async Task LoadAfterMutationAsync(int tableId)
    {
        var response = await _api.TablesAsync();
        TablesList.ItemsSource = response.Tables;
        TablesList.SelectedItem = response.Tables.FirstOrDefault(x => x.Id == tableId);
    }

    private void TablesList_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshSelection();
    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync((TablesList.SelectedItem as TableInfo)?.Id);
}
