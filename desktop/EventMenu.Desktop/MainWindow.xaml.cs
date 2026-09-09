using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow : Window
{
    private static readonly CultureInfo PtBr = new("pt-BR");
    private SecureSessionStore? _store;
    private EventMenuApiClient? _api;
    private UserInfo? _currentUser;
    private Dictionary<string, bool> _permissions = new();
    private List<Order> _orders = new();
    private List<Product> _products = new();
    private List<TableInfo> _tables = new();
    private List<OperatingUnit> _units = new();
    private Dictionary<string, JsonElement>? _currentShift;
    private readonly ObservableCollection<CartLine> _cart = new();
    private readonly DispatcherTimer _refreshTimer;
    private bool _autoRefreshing;

    public MainWindow()
    {
        InitializeComponent();
        CartGrid.ItemsSource = _cart;
        ChannelSelector.ItemsSource = new[]
        {
            new ChannelOption { Value = "counter", Label = "Balcão" },
            new ChannelOption { Value = "pickup", Label = "Retirada" },
            new ChannelOption { Value = "delivery", Label = "Delivery" },
            new ChannelOption { Value = "table", Label = "Mesa" }
        };
        ChannelSelector.SelectedIndex = 0;
        CashMovementType.SelectedIndex = 0;

        _refreshTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(12) };
        _refreshTimer.Tick += AutoRefreshTimer_Tick;
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

        LoginStatus.Foreground = Brushes.DimGray;
        LoginStatus.Text = "Restaurando sessão...";
        try
        {
            var me = await _api.MeAsync();
            await EnterShellAsync(me);
        }
        catch
        {
            LoginStatus.Foreground = Brushes.Firebrick;
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
        LoginStatus.Foreground = Brushes.DimGray;
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
            LoginStatus.Foreground = Brushes.Firebrick;
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
        PosNavButton.Visibility = Can("orders_create") ? Visibility.Visible : Visibility.Collapsed;
        CashNavButton.Visibility = Can("cash") ? Visibility.Visible : Visibility.Collapsed;
        TablesNavButton.Visibility = Can("tables") ? Visibility.Visible : Visibility.Collapsed;
        PreparingButton.Visibility = Can("orders_manage") || Can("orders_kitchen") ? Visibility.Visible : Visibility.Collapsed;
        ReadyButton.Visibility = PreparingButton.Visibility;
        CompleteButton.Visibility = Can("orders_manage") || Can("orders_delivery") ? Visibility.Visible : Visibility.Collapsed;

        LoginPanel.Visibility = Visibility.Collapsed;
        ShellPanel.Visibility = Visibility.Visible;
        ShowView(DashboardView);

        await LoadContextAsync();
        await LoadInitialDataAsync();
        _refreshTimer.Start();
    }

    private async Task LoadContextAsync()
    {
        if (_api is null) return;
        try
        {
            var contextTask = _api.GoContextAsync();
            var unitsTask = _api.UnitsAsync();
            await Task.WhenAll(contextTask, unitsTask);
            var context = await contextTask;
            var units = await unitsTask;

            _currentShift = context.Shift;
            _units = units.Units;
            UnitSelector.ItemsSource = _units;

            var modes = context.Modes.Select(ModeOption).ToList();
            ModeSelector.ItemsSource = modes;

            var shiftMode = ShiftValue("mode");
            var shiftUnitId = ShiftInt("unit_id");
            if (HasShift)
            {
                ModeSelector.SelectedItem = modes.FirstOrDefault(x => x.Value == shiftMode);
                UnitSelector.SelectedItem = _units.FirstOrDefault(x => x.Id == shiftUnitId);
                ShiftStatusText.Text = $"{ModeLabel(shiftMode)} ativo";
                var unitName = ShiftValue("unit_name");
                if (!string.IsNullOrWhiteSpace(unitName)) ShiftStatusText.Text += $" • {unitName}";
                ShiftButton.Content = "Encerrar turno";
                ModeSelector.IsEnabled = false;
                UnitSelector.IsEnabled = false;
            }
            else
            {
                ModeSelector.SelectedIndex = modes.Count > 0 ? 0 : -1;
                var preferredUnit = _units.FirstOrDefault(x => x.IsDefault == 1) ?? (_units.Count == 1 ? _units[0] : null);
                UnitSelector.SelectedItem = preferredUnit;
                ShiftStatusText.Text = "Sem turno";
                ShiftButton.Content = "Iniciar turno";
                ShiftButton.IsEnabled = modes.Count > 0;
                ModeSelector.IsEnabled = modes.Count > 0;
                UnitSelector.IsEnabled = _units.Count > 1;
            }
        }
        catch (Exception ex)
        {
            ShiftStatusText.Text = "Turno indisponível";
            ShiftButton.IsEnabled = false;
            ConnectionText.Text = "Servidor conectado parcialmente";
            AutoRefreshText.Text = ex.Message;
        }
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

        if (Can("tables") && ShiftIs("operation")) anySuccess |= await TryLoadTablesAsync(false);
        else
        {
            _tables = new();
            TablesGrid.ItemsSource = _tables;
            TableSelector.ItemsSource = _tables;
            TablesCountText.Text = "0";
        }

        if (Can("cash")) anySuccess |= await TryLoadCashAsync(false);
        ConnectionText.Text = anySuccess ? "Servidor conectado" : "Acesso limitado";
    }

    private async Task<bool> TryLoadOrdersAsync(bool showError = true)
    {
        if (_api is null) return false;
        try
        {
            var response = HasShift ? await _api.OperationalOrdersAsync() : await _api.OrdersAsync();
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
            ApplyProductFilter();
            ProductsCountText.Text = _products.Count.ToString();
            return true;
        }
        catch (Exception ex)
        {
            if (showError) MessageBox.Show(ex.Message, "Produtos", MessageBoxButton.OK, MessageBoxImage.Warning);
            return false;
        }
    }

    private async Task<bool> TryLoadTablesAsync(bool showError = true)
    {
        if (_api is null || !Can("tables") || !ShiftIs("operation")) return false;
        try
        {
            var response = await _api.TablesAsync();
            _tables = response.Tables;
            TablesGrid.ItemsSource = _tables;
            TableSelector.ItemsSource = _tables.Where(t => t.TabId is > 0).ToList();
            TablesCountText.Text = _tables.Count.ToString();
            return true;
        }
        catch (Exception ex)
        {
            if (showError) MessageBox.Show(ex.Message, "Mesas e comandas", MessageBoxButton.OK, MessageBoxImage.Warning);
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
                CashDetailsText.Text = "Inicie um turno na unidade correta e abra o caixa para receber valores.";
                CashExpectedText.Text = "";
            }
            else
            {
                CashStatusText.Text = "Caixa aberto";
                var id = Value(response.Session, "id");
                var opened = Value(response.Session, "opened_at");
                var unit = Value(response.Session, "unit_name");
                CashDetailsText.Text = $"Sessão {id}{(string.IsNullOrWhiteSpace(unit) ? "" : $" • {unit}")}{(string.IsNullOrWhiteSpace(opened) ? "" : $" • aberta em {opened}")}";

                try
                {
                    var summary = await _api.CashSummaryAsync();
                    var expected = JsonInt(summary.Summary, "expected_cash_cents");
                    CashExpectedText.Text = $"Dinheiro esperado: {Money(expected)}";
                }
                catch { CashExpectedText.Text = ""; }
            }
            return true;
        }
        catch (Exception ex)
        {
            CashStatusText.Text = "Não foi possível carregar";
            CashDetailsText.Text = ex.Message;
            CashExpectedText.Text = "";
            if (showError) MessageBox.Show(ex.Message, "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return false;
        }
    }

    private async void ShiftButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null) return;
        ShiftButton.IsEnabled = false;
        try
        {
            if (HasShift)
            {
                if (MessageBox.Show("Encerrar o turno atual?", "Turno", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
                await _api.ShiftCloseAsync();
            }
            else
            {
                if (ModeSelector.SelectedItem is not ShiftModeOption mode)
                    throw new InvalidOperationException("Escolha o tipo de turno.");
                var unitId = (UnitSelector.SelectedItem as OperatingUnit)?.Id;
                if (_units.Count > 1 && unitId is null)
                    throw new InvalidOperationException("Escolha a unidade antes de iniciar o turno.");
                await _api.ShiftOpenAsync(mode.Value, unitId);
            }

            await LoadContextAsync();
            await LoadInitialDataAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Turno", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            ShiftButton.IsEnabled = true;
        }
    }

    private void ProductSearchBox_TextChanged(object sender, TextChangedEventArgs e) => ApplyProductFilter();

    private void ApplyProductFilter()
    {
        var search = ProductSearchBox?.Text.Trim() ?? "";
        IEnumerable<Product> items = _products;
        if (!string.IsNullOrWhiteSpace(search))
            items = items.Where(p => p.Name.Contains(search, StringComparison.OrdinalIgnoreCase) || p.Sku.Contains(search, StringComparison.OrdinalIgnoreCase));
        ProductsGrid.ItemsSource = items.ToList();
    }

    private void ProductsGrid_MouseDoubleClick(object sender, MouseButtonEventArgs e) => AddSelectedProductToCart();
    private void AddProductButton_Click(object sender, RoutedEventArgs e) => AddSelectedProductToCart();

    private void AddSelectedProductToCart()
    {
        if (ProductsGrid.SelectedItem is not Product product) return;
        var existing = _cart.FirstOrDefault(x => x.ProductId == product.Id);
        var nextQty = (existing?.Quantity ?? 0) + 1;
        if (product.TrackStock == 1 && product.StockQty.HasValue && nextQty > product.StockQty.Value)
        {
            MessageBox.Show($"Estoque insuficiente para {product.Name}.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (existing is not null)
            existing.Quantity = nextQty;
        else
        {
            var line = new CartLine { ProductId = product.Id, Name = product.Name, UnitPriceCents = product.PriceCents, Quantity = 1 };
            line.PropertyChanged += CartLine_PropertyChanged;
            _cart.Add(line);
        }
        UpdateCartTotal();
    }

    private void CartLine_PropertyChanged(object? sender, PropertyChangedEventArgs e) => UpdateCartTotal();

    private void IncreaseCartButton_Click(object sender, RoutedEventArgs e)
    {
        if (CartGrid.SelectedItem is not CartLine line) return;
        var product = _products.FirstOrDefault(x => x.Id == line.ProductId);
        if (product?.TrackStock == 1 && product.StockQty.HasValue && line.Quantity + 1 > product.StockQty.Value)
        {
            MessageBox.Show($"Estoque insuficiente para {line.Name}.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        line.Quantity += 1;
    }

    private void DecreaseCartButton_Click(object sender, RoutedEventArgs e)
    {
        if (CartGrid.SelectedItem is not CartLine line) return;
        if (line.Quantity <= 1) RemoveCartLine(line); else line.Quantity -= 1;
    }

    private void RemoveCartButton_Click(object sender, RoutedEventArgs e)
    {
        if (CartGrid.SelectedItem is CartLine line) RemoveCartLine(line);
    }

    private void RemoveCartLine(CartLine line)
    {
        line.PropertyChanged -= CartLine_PropertyChanged;
        _cart.Remove(line);
        UpdateCartTotal();
    }

    private void UpdateCartTotal() => CartTotalText.Text = Money(_cart.Sum(x => x.TotalCents));

    private void ChannelSelector_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var channel = (ChannelSelector.SelectedItem as ChannelOption)?.Value ?? "counter";
        DeliveryPanel.Visibility = channel == "delivery" ? Visibility.Visible : Visibility.Collapsed;
        TableOrderPanel.Visibility = channel == "table" ? Visibility.Visible : Visibility.Collapsed;
    }

    private async void CreateOrderButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || !Can("orders_create")) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de registrar vendas neste computador.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (_cart.Count == 0)
        {
            MessageBox.Show("Adicione pelo menos um produto ao pedido.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        var channel = (ChannelSelector.SelectedItem as ChannelOption)?.Value ?? "counter";
        int? tableId = null;
        if (channel == "table")
        {
            if (!ShiftIs("operation"))
            {
                MessageBox.Show("Pedidos de mesa exigem um turno de Operação.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }
            if (TableSelector.SelectedItem is not TableInfo table || table.TabId is null)
            {
                MessageBox.Show("Escolha uma mesa com comanda aberta.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }
            tableId = table.Id;
        }

        if (channel == "delivery" && (string.IsNullOrWhiteSpace(CustomerNameBox.Text) || string.IsNullOrWhiteSpace(CustomerPhoneBox.Text) || string.IsNullOrWhiteSpace(DeliveryAddressBox.Text)))
        {
            MessageBox.Show("Delivery exige nome, telefone e endereço.", "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        var request = new OrderCreateRequest
        {
            Channel = channel,
            TableId = tableId,
            CustomerName = CustomerNameBox.Text.Trim(),
            CustomerPhone = CustomerPhoneBox.Text.Trim(),
            DeliveryAddress = DeliveryAddressBox.Text.Trim(),
            Notes = OrderNotesBox.Text.Trim(),
            Items = _cart.Select(x => new OrderCreateItem { ProductId = x.ProductId, Quantity = x.Quantity }).ToList()
        };

        CreateOrderButton.IsEnabled = false;
        try
        {
            var result = await _api.CreateOrderAsync(request);
            if (result.Order is null) throw new ApiClientException("O servidor não retornou o pedido criado.");
            var orderId = result.Order.Id;
            ClearCartAndCustomer();
            await TryLoadProductsAsync(false);
            await TryLoadOrdersAsync(false);
            if (ShiftIs("operation") && Can("tables")) await TryLoadTablesAsync(false);

            if (MessageBox.Show($"Pedido #{orderId} criado com sucesso. Imprimir agora?", "PDV", MessageBoxButton.YesNo, MessageBoxImage.Information) == MessageBoxResult.Yes)
                await PrintOrderAsync(orderId);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "PDV", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            CreateOrderButton.IsEnabled = true;
        }
    }

    private void ClearCartAndCustomer()
    {
        foreach (var line in _cart) line.PropertyChanged -= CartLine_PropertyChanged;
        _cart.Clear();
        CustomerNameBox.Clear();
        CustomerPhoneBox.Clear();
        DeliveryAddressBox.Clear();
        OrderNotesBox.Clear();
        UpdateCartTotal();
    }

    private async void PrintSelectedOrderButton_Click(object sender, RoutedEventArgs e)
    {
        if (OrdersGrid.SelectedItem is not Order order)
        {
            MessageBox.Show("Selecione um pedido para imprimir.", "Impressão", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        await PrintOrderAsync(order.Id);
    }

    private async Task PrintOrderAsync(int orderId)
    {
        if (_api is null) return;
        try
        {
            var detail = await _api.OrderDetailsAsync(orderId);
            if (detail.Order is null) throw new ApiClientException("Pedido não encontrado para impressão.");
            ReceiptPrinter.PrintOrder(TenantNameText.Text, detail.Order, detail.Items);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Impressão", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async void PreparingButton_Click(object sender, RoutedEventArgs e) => await ChangeSelectedOrderStatusAsync("preparing");
    private async void ReadyButton_Click(object sender, RoutedEventArgs e) => await ChangeSelectedOrderStatusAsync("ready");
    private async void CompleteButton_Click(object sender, RoutedEventArgs e) => await ChangeSelectedOrderStatusAsync("completed");

    private async Task ChangeSelectedOrderStatusAsync(string status)
    {
        if (_api is null || OrdersGrid.SelectedItem is not Order order) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de alterar o fluxo operacional.", "Pedidos", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        try
        {
            await _api.ChangeOperationalOrderStatusAsync(order.Id, status);
            await TryLoadOrdersAsync(false);
            if (ShiftIs("operation") && Can("tables")) await TryLoadTablesAsync(false);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Pedidos", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async void OpenTabButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || TablesGrid.SelectedItem is not TableInfo table) return;
        if (!ShiftIs("operation"))
        {
            MessageBox.Show("Inicie um turno de Operação para usar mesas e comandas.", "Mesas", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (table.TabId is > 0)
        {
            MessageBox.Show("Esta mesa já possui uma comanda aberta.", "Mesas", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        try
        {
            await _api.TableOpenAsync(table.Id, OpenTabLabelBox.Text.Trim());
            OpenTabLabelBox.Clear();
            await TryLoadTablesAsync(false);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Mesas", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async void CloseTabButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || TablesGrid.SelectedItem is not TableInfo table || table.TabId is null) return;
        if (MessageBox.Show($"Fechar a comanda da {table.Name}?", "Mesas", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        try
        {
            await _api.TableCloseAsync(table.TabId.Value);
            await TryLoadTablesAsync(false);
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Mesas", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async void CashOpenButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || !Can("cash")) return;
        if (!HasShift)
        {
            MessageBox.Show("Inicie um turno na unidade correta antes de abrir o caixa.", "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (!TryMoney(CashOpenAmountBox.Text, out var cents, true))
        {
            MessageBox.Show("Informe um valor inicial válido.", "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        try
        {
            await _api.CashOpenAsync(cents, CashOpenNotesBox.Text.Trim());
            CashOpenAmountBox.Clear();
            CashOpenNotesBox.Clear();
            await TryLoadCashAsync(false);
        }
        catch (Exception ex) { MessageBox.Show(ex.Message, "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning); }
    }

    private async void CashMovementButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || !Can("cash")) return;
        var type = (CashMovementType.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "supply";
        if (!TryMoney(CashMovementAmountBox.Text, out var cents, false))
        {
            MessageBox.Show("Informe um valor maior que zero.", "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        var notes = CashMovementNotesBox.Text.Trim();
        if (type is "withdrawal" or "adjustment" && string.IsNullOrWhiteSpace(notes))
        {
            MessageBox.Show("Informe o motivo deste movimento.", "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        try
        {
            var direction = type == "withdrawal" ? "out" : "in";
            await _api.CashMovementAsync(type, cents, notes, direction);
            CashMovementAmountBox.Clear();
            CashMovementNotesBox.Clear();
            await TryLoadCashAsync(false);
        }
        catch (Exception ex) { MessageBox.Show(ex.Message, "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning); }
    }

    private async void CashCloseButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || !Can("cash")) return;
        if (!TryMoney(CashCountedBox.Text, out var cents, true))
        {
            MessageBox.Show("Informe o total em dinheiro contado no caixa.", "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (MessageBox.Show("Confirmar fechamento do caixa?", "Caixa", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        try
        {
            await _api.CashCloseAsync(cents, CashCloseNotesBox.Text.Trim());
            CashCountedBox.Clear();
            CashCloseNotesBox.Clear();
            await TryLoadCashAsync(false);
        }
        catch (Exception ex) { MessageBox.Show(ex.Message, "Caixa", MessageBoxButton.OK, MessageBoxImage.Warning); }
    }

    private static bool TryMoney(string text, out int cents, bool allowZero)
    {
        cents = 0;
        text = text.Trim().Replace("R$", "", StringComparison.OrdinalIgnoreCase).Trim();
        if (!decimal.TryParse(text, NumberStyles.Number | NumberStyles.AllowCurrencySymbol, PtBr, out var value) &&
            !decimal.TryParse(text, NumberStyles.Number, CultureInfo.InvariantCulture, out value)) return false;
        if (value < 0 || (!allowZero && value <= 0)) return false;
        if (value > 21_000_000m) return false;
        cents = (int)Math.Round(value * 100m, MidpointRounding.AwayFromZero);
        return true;
    }

    private async void AutoRefreshTimer_Tick(object? sender, EventArgs e)
    {
        if (_autoRefreshing || ShellPanel.Visibility != Visibility.Visible) return;
        _autoRefreshing = true;
        try
        {
            if (Can("orders_create") || Can("orders_manage") || Can("orders_kitchen") || Can("orders_delivery")) await TryLoadOrdersAsync(false);
            if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);
            AutoRefreshText.Text = $"Atualizado às {DateTime.Now:HH:mm:ss}";
            ConnectionText.Text = "Servidor conectado";
        }
        catch
        {
            ConnectionText.Text = "Reconectando...";
        }
        finally { _autoRefreshing = false; }
    }

    private static string Value(Dictionary<string, object?> data, string key)
    {
        if (!data.TryGetValue(key, out var value) || value is null) return "";
        if (value is JsonElement element) return element.ToString();
        return value.ToString() ?? "";
    }

    private string ShiftValue(string key)
    {
        if (_currentShift is null || !_currentShift.TryGetValue(key, out var value) || value.ValueKind is JsonValueKind.Null or JsonValueKind.Undefined) return "";
        return value.ToString();
    }

    private int ShiftInt(string key) => int.TryParse(ShiftValue(key), out var value) ? value : 0;

    private static int JsonInt(Dictionary<string, JsonElement>? data, string key)
    {
        if (data is null || !data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private bool HasShift => _currentShift is { Count: > 0 };
    private bool ShiftIs(string mode) => HasShift && string.Equals(ShiftValue("mode"), mode, StringComparison.OrdinalIgnoreCase);
    private bool Can(string key) => _permissions.TryGetValue(key, out var allowed) && allowed;

    private static ShiftModeOption ModeOption(string mode) => new() { Value = mode, Label = ModeLabel(mode) };
    private static string ModeLabel(string mode) => mode switch { "operation" => "Operação", "pay" => "Pay", "events" => "Eventos", "delivery" => "Delivery", _ => mode };

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

    private static string Money(int cents) => (cents / 100m).ToString("C2", PtBr);

    private void ShowView(Grid view)
    {
        DashboardView.Visibility = Visibility.Collapsed;
        PosView.Visibility = Visibility.Collapsed;
        OrdersView.Visibility = Visibility.Collapsed;
        TablesView.Visibility = Visibility.Collapsed;
        CashView.Visibility = Visibility.Collapsed;
        view.Visibility = Visibility.Visible;
    }

    private void DashboardButton_Click(object sender, RoutedEventArgs e) => ShowView(DashboardView);
    private async void PosButton_Click(object sender, RoutedEventArgs e)
    {
        ShowView(PosView);
        await TryLoadProductsAsync(false);
        if (Can("tables") && ShiftIs("operation")) await TryLoadTablesAsync(false);
    }
    private async void OrdersButton_Click(object sender, RoutedEventArgs e)
    {
        ShowView(OrdersView);
        await TryLoadOrdersAsync(false);
    }
    private async void TablesButton_Click(object sender, RoutedEventArgs e)
    {
        ShowView(TablesView);
        if (!ShiftIs("operation"))
            MessageBox.Show("Inicie um turno de Operação para acessar o salão.", "Mesas", MessageBoxButton.OK, MessageBoxImage.Information);
        else await TryLoadTablesAsync(false);
    }
    private async void CashButton_Click(object sender, RoutedEventArgs e)
    {
        ShowView(CashView);
        await TryLoadCashAsync(false);
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e)
    {
        await LoadContextAsync();
        await LoadInitialDataAsync();
    }
    private async void RefreshOrdersButton_Click(object sender, RoutedEventArgs e) => await TryLoadOrdersAsync();
    private async void RefreshProductsButton_Click(object sender, RoutedEventArgs e) => await TryLoadProductsAsync();
    private async void RefreshTablesButton_Click(object sender, RoutedEventArgs e) => await TryLoadTablesAsync();
    private async void RefreshCashButton_Click(object sender, RoutedEventArgs e) => await TryLoadCashAsync();

    private async void LogoutButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null) return;
        _refreshTimer.Stop();
        try { await _api.LogoutAsync(); } catch { }
        _currentUser = null;
        _currentShift = null;
        _permissions.Clear();
        _orders.Clear();
        _products.Clear();
        _tables.Clear();
        ClearCartAndCustomer();
        ShellPanel.Visibility = Visibility.Collapsed;
        LoginPanel.Visibility = Visibility.Visible;
        EmailBox.Clear();
        PasswordBox.Clear();
        LoginStatus.Foreground = Brushes.DimGray;
        LoginStatus.Text = "Sessão encerrada.";
        EmailBox.Focus();
    }

    protected override void OnClosed(EventArgs e)
    {
        _refreshTimer.Stop();
        _api?.Dispose();
        base.OnClosed(e);
    }
}
