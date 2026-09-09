using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow : Window
{
    private SecureSessionStore? _store;
    private EventMenuApiClient? _api;
    private UserInfo? _currentUser;
    private Dictionary<string, bool> _permissions = new();
    private List<Order> _orders = new();
    private List<Product> _products = new();

    public MainWindow()
    {
        InitializeComponent();
    }

    private async void Window_Loaded(object sender, RoutedEventArgs e)
    {
        try
        {
            _store = new SecureSessionStore();
            _api = new EventMenuApiClient(_store);
        }
        catch (Exception ex)
        {
            LoginStatus.Text = ex.Message;
            LoginButton.IsEnabled = false;
            return;
        }

        if (!_api.HasSavedSession) return;

        LoginStatus.Foreground = System.Windows.Media.Brushes.DimGray;
        LoginStatus.Text = "Restaurando sessão...";
        try
        {
            var me = await _api.MeAsync();
            await EnterShellAsync(me);
        }
        catch
        {
            LoginStatus.Foreground = System.Windows.Media.Brushes.Firebrick;
            LoginStatus.Text = "Sua sessão terminou. Entre novamente.";
        }
    }

    private async void LoginButton_Click(object sender, RoutedEventArgs e) => await LoginAsync();

    private async void PasswordBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter) await LoginAsync();
    }

    private async Task LoginAsync()
    {
        if (_api is null) return;
        var email = EmailBox.Text.Trim();
        var password = PasswordBox.Password;
        if (string.IsNullOrWhiteSpace(email) || string.IsNullOrWhiteSpace(password))
        {
            LoginStatus.Text = "Informe e-mail e senha.";
            return;
        }

        LoginButton.IsEnabled = false;
        LoginStatus.Foreground = System.Windows.Media.Brushes.DimGray;
        LoginStatus.Text = "Entrando...";
        try
        {
            await _api.LoginAsync(email, password);
            var me = await _api.MeAsync();
            PasswordBox.Clear();
            await EnterShellAsync(me);
        }
        catch (Exception ex)
        {
            LoginStatus.Foreground = System.Windows.Media.Brushes.Firebrick;
            LoginStatus.Text = ex.Message;
        }
        finally
        {
            LoginButton.IsEnabled = true;
        }
    }

    private async Task EnterShellAsync(MeResponse me)
    {
        if (me.User is null) throw new ApiClientException("O servidor não retornou o usuário da sessão.");
        _currentUser = me.User;
        _permissions = me.Permissions;

        TenantNameText.Text = string.IsNullOrWhiteSpace(_currentUser.TenantName) ? "Minha empresa" : _currentUser.TenantName;
        UserNameText.Text = _currentUser.Name;
        RoleText.Text = RoleLabel(_currentUser.Role);
        CashNavButton.Visibility = Can("cash") ? Visibility.Visible : Visibility.Collapsed;

        LoginPanel.Visibility = Visibility.Collapsed;
        ShellPanel.Visibility = Visibility.Visible;
        ShowView(DashboardView);
        await LoadInitialDataAsync();
    }

    private async Task LoadInitialDataAsync()
    {
        ConnectionText.Text = "Atualizando...";
        var anySuccess = false;

        if (Can("orders_create") || Can("orders_manage") || Can("orders_kitchen") || Can("orders_delivery"))
            anySuccess |= await TryLoadOrdersAsync(false);
        else
        {
            _orders = new();
            OrdersGrid.ItemsSource = _orders;
            OrdersCountText.Text = "0";
        }

        if (Can("orders_create"))
            anySuccess |= await TryLoadProductsAsync(false);
        else
        {
            _products = new();
            ProductsGrid.ItemsSource = _products;
            ProductsCountText.Text = "0";
        }

        if (Can("cash")) anySuccess |= await TryLoadCashAsync(false);
        ConnectionText.Text = anySuccess ? "Servidor conectado" : "Acesso limitado";
    }

    private async Task<bool> TryLoadOrdersAsync(bool showError = true)
    {
        if (_api is null) return false;
        try
        {
            var response = await _api.OrdersAsync();
            _orders = response.Orders;
            OrdersGrid.ItemsSource = _orders;
            OrdersCountText.Text = _orders.Count.ToString();
            return true;
        }
        catch (Exception ex)
        {
            if (showError) MessageBox.Show(ex.Message, "Pedidos", MessageBoxButton.OK, MessageBoxImage.Warning);
            return false;
        }
    }

    private async Task<bool> TryLoadProductsAsync(bool showError = true)
    {
        if (_api is null) return false;
        try
        {
            var response = await _api.ProductsAsync();
            _products = response.Products;
            ProductsGrid.ItemsSource = _products;
            ProductsCountText.Text = _products.Count.ToString();
            return true;
        }
        catch (Exception ex)
        {
            if (showError) MessageBox.Show(ex.Message, "Produtos", MessageBoxButton.OK, MessageBoxImage.Warning);
            return false;
        }
    }

    private async Task<bool> TryLoadCashAsync(bool showError = true)
    {
        if (_api is null || !Can("cash")) return false;
        try
        {
            var response = await _api.CashCurrentAsync();
            if (response.Session is null || response.Session.Count == 0)
            {
                CashStatusText.Text = "Nenhum caixa aberto";
                CashDetailsText.Text = "Abra o caixa pelo fluxo autorizado antes de iniciar recebimentos.";
            }
            else
            {
                CashStatusText.Text = "Caixa aberto";
                var id = Value(response.Session, "id");
                var opened = Value(response.Session, "opened_at");
                CashDetailsText.Text = $"Sessão {id}{(string.IsNullOrWhiteSpace(opened) ? "" : $" • aberta em {opened}")}";
            }
            return true;
        }
        catch (Exception ex)
        {
            CashStatusText.Text = "Não foi possível carregar";
            CashDetailsText.Text = ex.Message;
            if (showError) MessageBox.Show(ex.Message, "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return false;
        }
    }

    private static string Value(Dictionary<string, object?> data, string key)
    {
        if (!data.TryGetValue(key, out var value) || value is null) return "";
        if (value is System.Text.Json.JsonElement element) return element.ToString();
        return value.ToString() ?? "";
    }

    private bool Can(string key) => _permissions.TryGetValue(key, out var allowed) && allowed;

    private static string RoleLabel(string role) => role switch
    {
        "admin" => "Administrador",
        "manager" => "Gerência",
        "cashier" => "Caixa",
        "waiter" => "Garçom",
        "kitchen" => "Cozinha",
        "delivery" => "Entregador",
        _ => role
    };

    private void ShowView(Grid view)
    {
        DashboardView.Visibility = Visibility.Collapsed;
        OrdersView.Visibility = Visibility.Collapsed;
        ProductsView.Visibility = Visibility.Collapsed;
        CashView.Visibility = Visibility.Collapsed;
        view.Visibility = Visibility.Visible;
    }

    private void DashboardButton_Click(object sender, RoutedEventArgs e) => ShowView(DashboardView);
    private void OrdersButton_Click(object sender, RoutedEventArgs e) => ShowView(OrdersView);
    private void ProductsButton_Click(object sender, RoutedEventArgs e) => ShowView(ProductsView);
    private async void CashButton_Click(object sender, RoutedEventArgs e)
    {
        ShowView(CashView);
        await TryLoadCashAsync(false);
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadInitialDataAsync();
    private async void RefreshOrdersButton_Click(object sender, RoutedEventArgs e) => await TryLoadOrdersAsync();
    private async void RefreshProductsButton_Click(object sender, RoutedEventArgs e) => await TryLoadProductsAsync();
    private async void RefreshCashButton_Click(object sender, RoutedEventArgs e) => await TryLoadCashAsync();

    private async void LogoutButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null) return;
        try { await _api.LogoutAsync(); } catch { }
        _currentUser = null;
        _permissions.Clear();
        _orders.Clear();
        _products.Clear();
        ShellPanel.Visibility = Visibility.Collapsed;
        LoginPanel.Visibility = Visibility.Visible;
        EmailBox.Clear();
        PasswordBox.Clear();
        LoginStatus.Foreground = System.Windows.Media.Brushes.DimGray;
        LoginStatus.Text = "Sessão encerrada.";
        EmailBox.Focus();
    }

    protected override void OnClosed(EventArgs e)
    {
        _api?.Dispose();
        base.OnClosed(e);
    }
}
