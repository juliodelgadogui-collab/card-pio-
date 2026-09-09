using System.Windows;
using System.Windows.Controls;
using System.Windows.Data;
using System.Windows.Input;
using System.Windows.Media;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private static readonly Brush OutOfStockBackground = new SolidColorBrush(Color.FromRgb(254, 243, 242));
    private static readonly Brush OutOfStockForeground = new SolidColorBrush(Color.FromRgb(180, 35, 24));
    private bool _posStockUxReady;
    private DateTimeOffset _lastOperationalPermissionRefresh = DateTimeOffset.MinValue;

    private void EnsurePosStockUx()
    {
        if (_posStockUxReady) return;
        _posStockUxReady = true;
        EnsureOrdersUx();

        ProductsGrid.LoadingRow += ProductsGrid_StockLoadingRow;
        ProductsGrid.PreviewMouseDoubleClick += ProductsGrid_StockPreviewMouseDoubleClick;
        ProductSearchBox.PreviewKeyDown += ProductSearchBox_PosPreviewKeyDown;
        CreateOrderButton.PreviewMouseDown += CreateOrderButton_StockPreviewMouseDown;
        PreviewKeyDown += MainWindow_PosPreviewKeyDown;

        if (ProductsGrid.Columns.Count > 3 && ProductsGrid.Columns[3] is DataGridTextColumn stockColumn)
        {
            stockColumn.Binding = new Binding(nameof(Product.StockDisplay));
            stockColumn.Header = "Estoque";
        }

        if (!ProductsGrid.Columns.Any(c => string.Equals(c.Header?.ToString(), "Disponibilidade", StringComparison.OrdinalIgnoreCase)))
        {
            ProductsGrid.Columns.Add(new DataGridTextColumn
            {
                Header = "Disponibilidade",
                Binding = new Binding(nameof(Product.StockStatusLabel)),
                Width = new DataGridLength(110),
                IsReadOnly = true,
            });
        }
    }

    private void ProductsGrid_StockLoadingRow(object? sender, DataGridRowEventArgs e)
    {
        if (e.Row.Item is not Product product) return;
        if (product.IsOutOfStock)
        {
            e.Row.Background = OutOfStockBackground;
            e.Row.Foreground = OutOfStockForeground;
            e.Row.Opacity = 0.72;
            e.Row.ToolTip = "Produto zerado. O PDV não permite adicionar nova quantidade enquanto não houver saldo.";
        }
        else
        {
            e.Row.ClearValue(Control.BackgroundProperty);
            e.Row.ClearValue(Control.ForegroundProperty);
            e.Row.Opacity = 1;
            e.Row.ToolTip = product.TrackStock == 1
                ? $"Saldo informado pelo catálogo: {product.StockDisplay}. O servidor valida o estoque novamente ao criar o pedido."
                : "Produto sem controle de estoque.";
        }
    }

    private void ProductsGrid_StockPreviewMouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        if (ProductsGrid.SelectedItem is not Product product || !product.IsOutOfStock) return;
        e.Handled = true;
        ShowOutOfStock(product);
    }

    private void CreateOrderButton_StockPreviewMouseDown(object sender, MouseButtonEventArgs e)
    {
        if (ValidateCartAgainstKnownStock()) return;
        e.Handled = true;
    }

    private void ProductSearchBox_PosPreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Escape)
        {
            ProductSearchBox.Clear();
            e.Handled = true;
            return;
        }

        if (e.Key != Key.Enter) return;

        var search = ProductSearchBox.Text.Trim();
        Product? product = null;
        var exactSku = false;
        if (search.Length > 0)
        {
            product = _products.FirstOrDefault(x => !string.IsNullOrWhiteSpace(x.Sku) && x.Sku.Equals(search, StringComparison.OrdinalIgnoreCase));
            exactSku = product is not null;
        }
        product ??= ProductsGrid.SelectedItem as Product;
        product ??= ProductsGrid.Items.Cast<object>().OfType<Product>().FirstOrDefault();
        if (product is null)
        {
            e.Handled = true;
            return;
        }

        ProductsGrid.SelectedItem = product;
        ProductsGrid.ScrollIntoView(product);
        if (product.IsOutOfStock)
        {
            ShowOutOfStock(product);
            e.Handled = true;
            return;
        }

        AddSelectedProductToCart();
        if (exactSku) ProductSearchBox.Clear();
        e.Handled = true;
    }

    private void MainWindow_PosPreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (ShellPanel.Visibility != Visibility.Visible) return;

        if (e.Key == Key.F2 && Can("orders_create"))
        {
            ShowView(PosView);
            ProductSearchBox.Focus();
            ProductSearchBox.SelectAll();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F9 && PosView.Visibility == Visibility.Visible && Can("orders_create"))
        {
            if (!ValidateCartAgainstKnownStock())
            {
                e.Handled = true;
                return;
            }
            CreateOrderButton.RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            e.Handled = true;
        }
    }

    private bool ValidateCartAgainstKnownStock()
    {
        foreach (var line in _cart)
        {
            var product = _products.FirstOrDefault(p => p.Id == line.ProductId);
            if (product is null || product.TrackStock != 1 || !product.StockQty.HasValue) continue;
            if (line.Quantity <= product.StockQty.Value) continue;

            MessageBox.Show(
                product.StockQty.Value <= 0
                    ? $"{product.Name} está zerado e precisa ser removido do pedido."
                    : $"{product.Name}: o carrinho tem {line.Quantity:0.###}, mas o saldo informado é {product.StockQty.Value:0.###}.",
                "Conferir estoque",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            ProductsGrid.SelectedItem = product;
            return false;
        }
        return true;
    }

    private static void ShowOutOfStock(Product product)
    {
        MessageBox.Show(
            $"{product.Name} está sem saldo disponível no catálogo. Atualize o estoque antes de vender uma nova unidade.",
            "Produto zerado",
            MessageBoxButton.OK,
            MessageBoxImage.Information);
    }

    private async Task RefreshOperationalPermissionsAsync(bool force = false)
    {
        if (_api is null || ShellPanel.Visibility != Visibility.Visible) return;
        var now = DateTimeOffset.UtcNow;
        if (!force && now - _lastOperationalPermissionRefresh < TimeSpan.FromSeconds(45)) return;
        _lastOperationalPermissionRefresh = now;

        try
        {
            var context = await _api.GoContextAsync();
            if (context.Permissions.Count == 0) return;
            foreach (var permission in context.Permissions)
                _permissions[permission.Key] = permission.Value;
            RefreshDesktopPermissionUi();
        }
        catch
        {
            // A lista reduzida recebida no login continua sendo usada se o contexto operacional falhar.
        }
    }

    private void RefreshDesktopPermissionUi()
    {
        PosNavButton.Visibility = Can("orders_create") ? Visibility.Visible : Visibility.Collapsed;
        CashNavButton.Visibility = Can("cash") ? Visibility.Visible : Visibility.Collapsed;
        TablesNavButton.Visibility = Can("tables") ? Visibility.Visible : Visibility.Collapsed;
        PreparingButton.Visibility = Can("orders_manage") || Can("orders_kitchen") ? Visibility.Visible : Visibility.Collapsed;
        ReadyButton.Visibility = PreparingButton.Visibility;
        CompleteButton.Visibility = Can("orders_manage") || Can("orders_delivery") ? Visibility.Visible : Visibility.Collapsed;

        if (_productionNavButton is not null)
            _productionNavButton.Visibility = Can("orders_kitchen") || Can("orders_dispatch") || Can("production_print") || Can("production_manage") ? Visibility.Visible : Visibility.Collapsed;
        if (_inventoryNavButton is not null)
            _inventoryNavButton.Visibility = Can("inventory") ? Visibility.Visible : Visibility.Collapsed;
        if (_deliveryMonitorButton is not null)
            _deliveryMonitorButton.Visibility = Can("delivery_assign") || Can("reports") ? Visibility.Visible : Visibility.Collapsed;
        if (_hubNavButton is not null)
            _hubNavButton.Visibility = Can("hardware_manage") ? Visibility.Visible : Visibility.Collapsed;
        if (_hardwareSettingsButton is not null)
            _hardwareSettingsButton.Visibility = Can("hardware_manage") || Can("fiscal_manage") ? Visibility.Visible : Visibility.Collapsed;
    }

    private void DisposePosStockUx()
    {
        if (!_posStockUxReady) return;
        DisposeOrdersUx();
        ProductsGrid.LoadingRow -= ProductsGrid_StockLoadingRow;
        ProductsGrid.PreviewMouseDoubleClick -= ProductsGrid_StockPreviewMouseDoubleClick;
        ProductSearchBox.PreviewKeyDown -= ProductSearchBox_PosPreviewKeyDown;
        CreateOrderButton.PreviewMouseDown -= CreateOrderButton_StockPreviewMouseDown;
        PreviewKeyDown -= MainWindow_PosPreviewKeyDown;
        _posStockUxReady = false;
    }
}
