using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Data;
using System.Windows.Input;
using System.Windows.Media;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _ordersUxReady;
    private TextBox? _ordersSearchBox;
    private ComboBox? _ordersStatusFilter;
    private ComboBox? _ordersPaymentFilter;
    private TextBlock? _ordersVisibleCount;
    private DependencyPropertyDescriptor? _ordersItemsSourceDescriptor;

    private void EnsureOrdersUx()
    {
        if (_ordersUxReady) return;
        _ordersUxReady = true;

        if (OrdersGrid.Columns.Count >= 7)
        {
            if (OrdersGrid.Columns[2] is DataGridTextColumn channel) channel.Binding = new Binding(nameof(Order.ChannelDisplay));
            if (OrdersGrid.Columns[3] is DataGridTextColumn status) status.Binding = new Binding(nameof(Order.StatusDisplay));
            if (OrdersGrid.Columns[4] is DataGridTextColumn payment) payment.Binding = new Binding(nameof(Order.PaymentStatusDisplay));
            if (OrdersGrid.Columns[6] is DataGridTextColumn created) created.Binding = new Binding(nameof(Order.CreatedDisplay));
        }

        StackPanel? actionBar = OrdersView.Children.OfType<StackPanel>()
            .FirstOrDefault(x => Grid.GetRow(x) == 2);

        // Layout novo: ações ficam em um Grid, com filtros à esquerda e botões à direita.
        if (actionBar is null)
        {
            var rowGrid = OrdersView.Children.OfType<Grid>().FirstOrDefault(x => Grid.GetRow(x) == 2);
            if (rowGrid is not null)
            {
                foreach (var hint in rowGrid.Children.OfType<TextBlock>().ToList())
                    hint.Visibility = Visibility.Collapsed;

                actionBar = new StackPanel
                {
                    Orientation = Orientation.Horizontal,
                    HorizontalAlignment = HorizontalAlignment.Left,
                    VerticalAlignment = VerticalAlignment.Center,
                };
                Grid.SetColumn(actionBar, 0);
                rowGrid.Children.Add(actionBar);
            }
        }

        if (actionBar is not null)
        {
            _ordersSearchBox = new TextBox
            {
                Width = 205,
                Height = 38,
                Margin = new Thickness(0, 0, 8, 0),
                VerticalContentAlignment = VerticalAlignment.Center,
                ToolTip = "Buscar pedido, cliente ou entregador",
            };
            _ordersSearchBox.TextChanged += (_, _) => ApplyOrdersFilter();

            _ordersStatusFilter = CreateFilter(new[]
            {
                ("Todos os status", "all"), ("Pendentes", "pending"), ("Em preparo", "preparing"),
                ("Prontos", "ready"), ("Em rota", "out_for_delivery"), ("Concluídos", "completed"), ("Cancelados", "cancelled")
            }, 142);
            _ordersStatusFilter.SelectionChanged += (_, _) => ApplyOrdersFilter();

            _ordersPaymentFilter = CreateFilter(new[]
            {
                ("Todos pagamentos", "all"), ("Não pagos", "unpaid"), ("Pendentes", "pending"),
                ("Parciais", "partially_paid"), ("Pagos", "paid")
            }, 150);
            _ordersPaymentFilter.SelectionChanged += (_, _) => ApplyOrdersFilter();

            _ordersVisibleCount = new TextBlock
            {
                VerticalAlignment = VerticalAlignment.Center,
                Margin = new Thickness(4, 0, 12, 0),
                Foreground = new SolidColorBrush(Color.FromRgb(100, 116, 139)),
                FontSize = 11,
            };

            actionBar.Children.Add(_ordersSearchBox);
            actionBar.Children.Add(_ordersStatusFilter);
            actionBar.Children.Add(_ordersPaymentFilter);
            actionBar.Children.Add(_ordersVisibleCount);
        }

        OrdersGrid.LoadingRow += OrdersGrid_OperationalLoadingRow;
        PreviewKeyDown += MainWindow_OrdersPreviewKeyDown;
        _ordersItemsSourceDescriptor = DependencyPropertyDescriptor.FromProperty(ItemsControl.ItemsSourceProperty, typeof(DataGrid));
        _ordersItemsSourceDescriptor?.AddValueChanged(OrdersGrid, OrdersGrid_ItemsSourceChanged);
        ApplyOrdersFilter();
    }

    private static ComboBox CreateFilter(IEnumerable<(string Label, string Value)> items, double width)
    {
        var combo = new ComboBox { Width = width, Height = 38, Margin = new Thickness(0, 0, 8, 0) };
        foreach (var item in items) combo.Items.Add(new ComboBoxItem { Content = item.Label, Tag = item.Value });
        combo.SelectedIndex = 0;
        return combo;
    }

    private void OrdersGrid_ItemsSourceChanged(object? sender, EventArgs e) => ApplyOrdersFilter();

    private void ApplyOrdersFilter()
    {
        if (!_ordersUxReady || OrdersGrid.ItemsSource is null) return;
        var view = CollectionViewSource.GetDefaultView(OrdersGrid.ItemsSource);
        if (view is null) return;

        var search = _ordersSearchBox?.Text.Trim() ?? "";
        var status = (_ordersStatusFilter?.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "all";
        var payment = (_ordersPaymentFilter?.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "all";

        view.Filter = value =>
        {
            if (value is not Order order) return false;
            if (status != "all" && !order.Status.Equals(status, StringComparison.OrdinalIgnoreCase)) return false;
            if (payment != "all" && !order.PaymentStatus.Equals(payment, StringComparison.OrdinalIgnoreCase)) return false;
            if (search.Length == 0) return true;
            return order.Id.ToString().Contains(search, StringComparison.OrdinalIgnoreCase)
                || (order.CustomerName ?? "").Contains(search, StringComparison.OrdinalIgnoreCase)
                || (order.DeliveryName ?? "").Contains(search, StringComparison.OrdinalIgnoreCase)
                || order.ChannelDisplay.Contains(search, StringComparison.OrdinalIgnoreCase);
        };
        view.Refresh();
        if (_ordersVisibleCount is not null) _ordersVisibleCount.Text = $"{view.Cast<object>().Count()} exibido(s)";
    }

    private void OrdersGrid_OperationalLoadingRow(object? sender, DataGridRowEventArgs e)
    {
        if (e.Row.Item is not Order order) return;
        if (order.Status.Equals("cancelled", StringComparison.OrdinalIgnoreCase))
        {
            e.Row.Opacity = 0.62;
            e.Row.ToolTip = "Pedido cancelado.";
            return;
        }
        e.Row.Opacity = 1;
        if (order.PaymentStatus is "unpaid" or "pending" && order.Status is not "completed")
        {
            e.Row.Background = new SolidColorBrush(Color.FromRgb(255, 251, 235));
            e.Row.ToolTip = "Pagamento pendente.";
        }
        else if (order.Status.Equals("ready", StringComparison.OrdinalIgnoreCase))
        {
            e.Row.Background = new SolidColorBrush(Color.FromRgb(236, 253, 245));
            e.Row.ToolTip = "Pedido pronto para a próxima etapa.";
        }
        else
        {
            e.Row.ClearValue(Control.BackgroundProperty);
            e.Row.ToolTip = null;
        }
    }

    private async void MainWindow_OrdersPreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (ShellPanel.Visibility != Visibility.Visible) return;
        if (e.Key == Key.F3 && (Can("orders_view") || Can("orders_create") || Can("orders_manage") || Can("orders_kitchen") || Can("orders_delivery")))
        {
            ShowView(OrdersView);
            _ordersSearchBox?.Focus();
            _ordersSearchBox?.SelectAll();
            e.Handled = true;
            return;
        }
        if (e.Key == Key.F5 && OrdersView.Visibility == Visibility.Visible)
        {
            e.Handled = true;
            await TryLoadOrdersAsync(true);
        }
    }

    private void DisposeOrdersUx()
    {
        if (!_ordersUxReady) return;
        OrdersGrid.LoadingRow -= OrdersGrid_OperationalLoadingRow;
        PreviewKeyDown -= MainWindow_OrdersPreviewKeyDown;
        _ordersItemsSourceDescriptor?.RemoveValueChanged(OrdersGrid, OrdersGrid_ItemsSourceChanged);
        _ordersItemsSourceDescriptor = null;
        _ordersUxReady = false;
    }
}
