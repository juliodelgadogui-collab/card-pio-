using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class InventoryMonitorWindow : Window
{
    private readonly InventoryMonitorApiClient _api;
    private readonly DispatcherTimer _timer;
    private readonly TextBox _searchBox;
    private List<InventoryProductRow> _products = new();
    private bool _busy;

    public InventoryMonitorWindow(InventoryMonitorApiClient api)
    {
        InitializeComponent();
        _api = api;
        _searchBox = new TextBox
        {
            Width = 220,
            Height = 34,
            Margin = new Thickness(0, 0, 12, 0),
            VerticalContentAlignment = VerticalAlignment.Center,
            ToolTip = "Buscar por produto ou SKU",
        };
        _searchBox.TextChanged += (_, _) => ApplyLocalFilter();
        if (LowOnlyBox.Parent is StackPanel headerActions)
            headerActions.Children.Insert(0, _searchBox);

        StockGrid.LoadingRow += StockGrid_LoadingRow;
        PreviewKeyDown += InventoryMonitorWindow_PreviewKeyDown;
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(30) };
        _timer.Tick += async (_, _) => await RefreshAsync(false);
        Loaded += async (_, _) => { await RefreshAsync(true); _timer.Start(); };
        Closed += (_, _) =>
        {
            _timer.Stop();
            StockGrid.LoadingRow -= StockGrid_LoadingRow;
            PreviewKeyDown -= InventoryMonitorWindow_PreviewKeyDown;
        };
    }

    private async Task RefreshAsync(bool showError)
    {
        if (_busy) return;
        _busy = true;
        try
        {
            StatusText.Text = "Atualizando estoque...";
            var data = await _api.SnapshotAsync(LowOnlyBox.IsChecked == true);
            UnitText.Text = string.IsNullOrWhiteSpace(data.Unit.Name) ? "Unidade atual" : data.Unit.Name;
            ControlledText.Text = data.Summary.Controlled.ToString();
            LowText.Text = data.Summary.Low.ToString();
            ZeroText.Text = data.Summary.Zero.ToString();
            ReservedText.Text = data.Summary.ReservedQty.ToString("0.###");
            _products = data.Products;
            ApplyLocalFilter();
            StatusText.Text = data.Summary.Zero > 0
                ? $"Atenção: {data.Summary.Zero} item(ns) zerado(s) e {data.Summary.Low} item(ns) no grupo de reposição. Atualizado às {DateTime.Now:HH:mm}."
                : data.Summary.Low > 0
                    ? $"Atenção: {data.Summary.Low} item(ns) exigem reposição. Atualizado às {DateTime.Now:HH:mm}."
                    : $"Estoque dentro do mínimo configurado. Atualizado às {DateTime.Now:HH:mm}.";
        }
        catch (Exception ex)
        {
            StatusText.Text = "Não foi possível atualizar o estoque.";
            if (showError) MessageBox.Show(ex.Message, "Estoque", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally { _busy = false; }
    }

    private void ApplyLocalFilter()
    {
        var search = _searchBox.Text.Trim();
        IEnumerable<InventoryProductRow> query = _products;
        if (search.Length > 0)
            query = query.Where(x => x.Name.Contains(search, StringComparison.OrdinalIgnoreCase) || (x.Sku?.Contains(search, StringComparison.OrdinalIgnoreCase) ?? false));
        var rows = query
            .OrderBy(x => x.StockStatus == "zero" ? 0 : x.StockStatus == "low" ? 1 : 2)
            .ThenBy(x => x.Name)
            .ToList();
        StockGrid.ItemsSource = rows;
    }

    private void StockGrid_LoadingRow(object? sender, DataGridRowEventArgs e)
    {
        if (e.Row.Item is not InventoryProductRow product) return;
        switch (product.StockStatus)
        {
            case "zero":
                e.Row.Background = new SolidColorBrush(Color.FromRgb(254, 243, 242));
                e.Row.Foreground = new SolidColorBrush(Color.FromRgb(180, 35, 24));
                e.Row.ToolTip = "Sem saldo disponível. Reposição necessária.";
                break;
            case "low":
                e.Row.Background = new SolidColorBrush(Color.FromRgb(255, 250, 235));
                e.Row.Foreground = new SolidColorBrush(Color.FromRgb(147, 55, 13));
                e.Row.ToolTip = "Saldo igual ou abaixo do estoque mínimo configurado.";
                break;
            default:
                e.Row.ClearValue(Control.BackgroundProperty);
                e.Row.ClearValue(Control.ForegroundProperty);
                e.Row.ToolTip = $"Disponível: {product.AvailableDisplay} • reservado: {product.ReservedDisplay}.";
                break;
        }
    }

    private async void InventoryMonitorWindow_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.F5)
        {
            e.Handled = true;
            await RefreshAsync(true);
        }
        else if (e.Key == Key.F && Keyboard.Modifiers.HasFlag(ModifierKeys.Control))
        {
            e.Handled = true;
            _searchBox.Focus();
            _searchBox.SelectAll();
        }
        else if (e.Key == Key.Escape && _searchBox.IsKeyboardFocusWithin)
        {
            _searchBox.Clear();
            e.Handled = true;
        }
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await RefreshAsync(true);
    private async void LowOnlyBox_Changed(object sender, RoutedEventArgs e)
    {
        if (!IsLoaded) return;
        await RefreshAsync(false);
    }
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
