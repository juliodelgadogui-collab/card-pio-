using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class DeliveryTriageWindow : Window
{
    private readonly EventMenuApiClient _api;
    private List<OperatingUnit> _units = new();
    private bool _loading;

    public bool OperationChanged { get; private set; }

    public DeliveryTriageWindow(SecureSessionStore store)
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
        AssignButton.IsEnabled = false;
        UnitSelector.IsEnabled = false;
        FooterStatusText.Text = "Carregando pedidos sem unidade...";
        try
        {
            var unitsTask = _api.UnitsAsync();
            var ordersTask = _api.UnassignedDeliveriesAsync();
            await Task.WhenAll(unitsTask, ordersTask);

            _units = (await unitsTask).Units.Where(x => x.Id > 0).ToList();
            UnitSelector.ItemsSource = _units;
            UnitSelector.SelectedItem = _units.FirstOrDefault(x => x.IsDefault == 1) ?? _units.FirstOrDefault();

            var orders = (await ordersTask).Orders;
            OrdersGrid.ItemsSource = orders;
            OrdersGrid.SelectedItem = orders.FirstOrDefault();
            CountText.Text = $"Pedidos aguardando unidade • {orders.Count}";
            FooterStatusText.Text = orders.Count == 0
                ? "Nenhum pedido de Delivery aguardando direcionamento."
                : "Selecione um pedido e a unidade que assumirá a operação.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
            RefreshActions();
        }
    }

    private void RefreshActions()
    {
        var order = OrdersGrid.SelectedItem as UnassignedDeliveryOrder;
        SelectedOrderText.Text = order is null
            ? "Selecione um pedido."
            : $"Pedido #{order.Id} • {order.CustomerName}\n{order.DeliveryAddress}";
        UnitSelector.IsEnabled = !_loading && order is not null && _units.Count > 0;
        AssignButton.IsEnabled = UnitSelector.IsEnabled && UnitSelector.SelectedItem is OperatingUnit;
    }

    private void OrdersGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => RefreshActions();

    private async void AssignButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loading || OrdersGrid.SelectedItem is not UnassignedDeliveryOrder order || UnitSelector.SelectedItem is not OperatingUnit unit) return;
        if (MessageBox.Show($"Direcionar o pedido #{order.Id} para {unit.Name}?", "Triagem de Delivery", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;

        _loading = true;
        AssignButton.IsEnabled = false;
        UnitSelector.IsEnabled = false;
        try
        {
            await _api.AssignDeliveryUnitAsync(order.Id, unit.Id);
            OperationChanged = true;
            FooterStatusText.Text = $"Pedido #{order.Id} direcionado para {unit.Name}.";
        }
        catch (Exception ex)
        {
            FooterStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _loading = false;
        }
        await LoadAsync();
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível atualizar a triagem. Tente novamente."
            : message;
    }
}
