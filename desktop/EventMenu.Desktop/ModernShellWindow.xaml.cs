using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;
using Microsoft.Web.WebView2.Core;

namespace EventMenu.Desktop;

public partial class ModernShellWindow : Window
{
    private readonly SecureSessionStore _store = new();
    private EventMenuApiClient? _api;
    private DesktopIntegrationApiClient? _integrationApi;
    private readonly JsonSerializerOptions _json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString,
    };
    private Dictionary<string, bool> _permissions = new(StringComparer.OrdinalIgnoreCase);
    private Dictionary<string, JsonElement>? _shift;
    private UserInfo? _user;
    private bool _openingLegacy;

    public ModernShellWindow()
    {
        InitializeComponent();
        Loaded += ModernShellWindow_Loaded;
        Closed += (_, _) => DisposeClients();
    }

    private async void ModernShellWindow_Loaded(object sender, RoutedEventArgs e)
    {
        Loaded -= ModernShellWindow_Loaded;
        await InitializeWebUiAsync();
    }

    private async Task InitializeWebUiAsync()
    {
        LoadingPanel.Visibility = Visibility.Visible;
        FallbackPanel.Visibility = Visibility.Collapsed;
        LoadingText.Text = "Preparando sua operação...";

        try
        {
            var webRoot = Path.Combine(AppContext.BaseDirectory, "WebUi");
            var indexPath = Path.Combine(webRoot, "index.html");
            if (!File.Exists(indexPath))
                throw new FileNotFoundException("Os arquivos da interface não foram encontrados.");

            await WebView.EnsureCoreWebView2Async();
            var core = WebView.CoreWebView2 ?? throw new InvalidOperationException("O componente de interface não iniciou.");

            core.Settings.AreDevToolsEnabled = false;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.IsStatusBarEnabled = false;
            core.Settings.IsZoomControlEnabled = false;
            core.SetVirtualHostNameToFolderMapping(
                "app.eventmenu.local",
                webRoot,
                CoreWebView2HostResourceAccessKind.DenyCors);

            core.WebMessageReceived -= Core_WebMessageReceived;
            core.WebMessageReceived += Core_WebMessageReceived;
            core.NavigationStarting -= Core_NavigationStarting;
            core.NavigationStarting += Core_NavigationStarting;
            core.NewWindowRequested -= Core_NewWindowRequested;
            core.NewWindowRequested += Core_NewWindowRequested;

            WebView.Source = new Uri("https://app.eventmenu.local/index.html");
            LoadingPanel.Visibility = Visibility.Collapsed;
        }
        catch (Exception ex)
        {
            ShowFallback(ex.Message);
        }
    }

    private void Core_NavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri)
            && uri.Scheme == Uri.UriSchemeHttps
            && uri.Host.Equals("app.eventmenu.local", StringComparison.OrdinalIgnoreCase))
            return;
        e.Cancel = true;
    }

    private void Core_NewWindowRequested(object? sender, CoreWebView2NewWindowRequestedEventArgs e)
    {
        e.Handled = true;
        if (!Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri)) return;
        if (uri.Scheme is not ("https" or "http")) return;
        try { Process.Start(new ProcessStartInfo(uri.ToString()) { UseShellExecute = true }); } catch { }
    }

    private async void Core_WebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        BridgeRequest? request = null;
        try
        {
            request = JsonSerializer.Deserialize<BridgeRequest>(e.WebMessageAsJson, _json);
            if (request is null || string.IsNullOrWhiteSpace(request.Id) || string.IsNullOrWhiteSpace(request.Action))
                return;
            var data = await DispatchAsync(request.Action.Trim(), request.Payload);
            Respond(request.Id, true, data, null);
        }
        catch (Exception ex)
        {
            if (request is not null) Respond(request.Id, false, null, Friendly(ex));
        }
    }

    private async Task<object?> DispatchAsync(string action, JsonElement payload)
    {
        return action switch
        {
            "app.bootstrap" => await BootstrapAsync(),
            "auth.login" => await LoginAsync(payload),
            "auth.logout" => await LogoutAsync(),
            "dashboard.refresh" => await DashboardAsync(),
            "products.list" => await Api().ProductsAsync(),
            "orders.list" => await Api().OperationalOrdersAsync(),
            "tables.list" => await Api().TablesAsync(),
            "cash.current" => await CashAsync(),
            "shift.open" => await OpenShiftAsync(payload),
            "shift.close" => await CloseShiftAsync(),
            "order.create" => await CreateOrderAsync(payload),
            "order.status" => await ChangeOrderStatusAsync(payload),
            "table.open" => await OpenTableAsync(payload),
            "table.close" => await CloseTableAsync(payload),
            "cash.open" => await CashOpenAsync(payload),
            "cash.movement" => await CashMovementAsync(payload),
            "cash.close" => await CashCloseAsync(payload),
            "payment.pix" => await OpenPixAsync(payload),
            "window.production" => await OpenProductionAsync(),
            "window.inventory" => await OpenInventoryAsync(),
            "window.deliveries" => await OpenDeliveriesAsync(),
            "window.connect-mobile" => await OpenConnectMobileAsync(),
            "window.settings" => await OpenSettingsAsync(),
            "window.legacy" => OpenLegacy(),
            _ => throw new InvalidOperationException("Ação não disponível nesta versão."),
        };
    }

    private EventMenuApiClient Api() => _api ??= new EventMenuApiClient(_store);
    private DesktopIntegrationApiClient IntegrationApi() => _integrationApi ??= new DesktopIntegrationApiClient(_store);

    private async Task<object> BootstrapAsync()
    {
        if (!Api().HasSavedSession)
            return new { authenticated = false };

        try
        {
            var me = await Api().MeAsync();
            _user = me.User;
            await RefreshContextAsync();
            return new
            {
                authenticated = true,
                user = _user,
                permissions = _permissions,
                shift = _shift,
                units = (await Api().UnitsAsync()).Units,
                dashboard = await DashboardAsync(),
            };
        }
        catch (ApiClientException ex) when (ex.StatusCode == HttpStatusCode.Unauthorized)
        {
            return new { authenticated = false };
        }
    }

    private async Task<object> LoginAsync(JsonElement payload)
    {
        var email = RequiredString(payload, "email");
        var password = RequiredString(payload, "password");
        var login = await Api().LoginAsync(email, password);
        _user = login.User;
        await RefreshContextAsync();
        return new
        {
            authenticated = true,
            user = _user,
            permissions = _permissions,
            shift = _shift,
            units = (await Api().UnitsAsync()).Units,
            dashboard = await DashboardAsync(),
        };
    }

    private async Task<object> LogoutAsync()
    {
        await Api().LogoutAsync();
        _permissions.Clear();
        _shift = null;
        _user = null;
        DisposeClients();
        return new { authenticated = false };
    }

    private async Task RefreshContextAsync()
    {
        var context = await Api().GoContextAsync();
        _permissions = new Dictionary<string, bool>(context.Permissions, StringComparer.OrdinalIgnoreCase);
        _shift = context.Shift;
    }

    private async Task<object> DashboardAsync()
    {
        var ordersTask = Api().OperationalOrdersAsync();
        var productsTask = Api().ProductsAsync();
        var tablesTask = Api().TablesAsync();
        var cashTask = Api().CashCurrentAsync();
        await Task.WhenAll(ordersTask, productsTask, tablesTask, cashTask);

        var orders = ordersTask.Result.Orders;
        var products = productsTask.Result.Products;
        var tables = tablesTask.Result.Tables;
        return new
        {
            orders,
            products,
            tables,
            cash = cashTask.Result.Session,
            metrics = new
            {
                activeOrders = orders.Count(x => x.Status is not ("completed" or "cancelled")),
                readyOrders = orders.Count(x => x.Status == "ready"),
                pendingPayments = orders.Count(x => x.PaymentStatus is "unpaid" or "pending" or "partially_paid"),
                occupiedTables = tables.Count(x => x.TabId is > 0 || x.Status == "occupied"),
                products = products.Count,
            },
            updatedAt = DateTimeOffset.Now,
        };
    }

    private async Task<object> CashAsync()
    {
        var current = await Api().CashCurrentAsync();
        return new { session = current.Session };
    }

    private async Task<object> OpenShiftAsync(JsonElement payload)
    {
        var mode = OptionalString(payload, "mode") ?? "operation";
        var unitId = OptionalInt(payload, "unitId");
        var response = await Api().ShiftOpenAsync(mode, unitId);
        _shift = response.Shift;
        await RefreshContextAsync();
        return new { shift = _shift, permissions = _permissions };
    }

    private async Task<object> CloseShiftAsync()
    {
        await Api().ShiftCloseAsync();
        _shift = null;
        await RefreshContextAsync();
        return new { shift = _shift, permissions = _permissions };
    }

    private async Task<object> CreateOrderAsync(JsonElement payload)
    {
        var request = payload.Deserialize<OrderCreateRequest>(_json)
            ?? throw new InvalidOperationException("Pedido inválido.");
        if (request.Items.Count == 0) throw new InvalidOperationException("Adicione pelo menos um produto.");
        var response = await Api().CreateOrderAsync(request);
        return new { order = response.Order };
    }

    private async Task<object> ChangeOrderStatusAsync(JsonElement payload)
    {
        var orderId = RequiredInt(payload, "orderId");
        var status = RequiredString(payload, "status");
        var response = await Api().ChangeOperationalOrderStatusAsync(orderId, status);
        return new { order = response.Order };
    }

    private async Task<object> OpenTableAsync(JsonElement payload)
    {
        var tableId = RequiredInt(payload, "tableId");
        var label = OptionalString(payload, "label") ?? "";
        var response = await Api().TableOpenAsync(tableId, label);
        return new { tab = response.Tab };
    }

    private async Task<object> CloseTableAsync(JsonElement payload)
    {
        var tabId = RequiredInt(payload, "tabId");
        var response = await Api().TableCloseAsync(tabId);
        return new { tab = response.Tab };
    }

    private async Task<object> CashOpenAsync(JsonElement payload)
    {
        var amountCents = RequiredInt(payload, "amountCents");
        var notes = OptionalString(payload, "notes") ?? "";
        var response = await Api().CashOpenAsync(amountCents, notes);
        return new { session = response.Session };
    }

    private async Task<object> CashMovementAsync(JsonElement payload)
    {
        var type = RequiredString(payload, "type");
        var amountCents = RequiredInt(payload, "amountCents");
        var notes = OptionalString(payload, "notes") ?? "";
        var direction = type.Equals("withdrawal", StringComparison.OrdinalIgnoreCase) ? "out" : "in";
        var response = await Api().CashMovementAsync(type, amountCents, notes, direction);
        return new { session = response.Session, movementId = response.MovementId };
    }

    private async Task<object> CashCloseAsync(JsonElement payload)
    {
        var amountCents = RequiredInt(payload, "amountCents");
        var notes = OptionalString(payload, "notes") ?? "";
        var response = await Api().CashCloseAsync(amountCents, notes);
        return new { session = response.Session };
    }

    private async Task<object> OpenPixAsync(JsonElement payload)
    {
        var orderId = RequiredInt(payload, "orderId");
        var amountCents = RequiredInt(payload, "amountCents");
        var window = new PixPaymentWindow(Api(), orderId, amountCents) { Owner = this };
        window.ShowDialog();
        return new { confirmed = window.PaymentConfirmed };
    }

    private async Task<object> OpenProductionAsync()
    {
        RequireShift();
        var window = new ProductionWindow(IntegrationApi(), Can("orders_dispatch"), Can("production_manage")) { Owner = this };
        window.ShowDialog();
        return await CompletedWindowAsync();
    }

    private async Task<object> OpenInventoryAsync()
    {
        RequireShift();
        using var client = new InventoryMonitorApiClient(_store);
        var window = new InventoryMonitorWindow(client) { Owner = this };
        window.ShowDialog();
        return await CompletedWindowAsync();
    }

    private async Task<object> OpenDeliveriesAsync()
    {
        RequireShift();
        var window = new DeliveryMonitorWindow(_store) { Owner = this };
        window.ShowDialog();
        return await CompletedWindowAsync();
    }

    private async Task<object> OpenConnectMobileAsync()
    {
        var unitId = RequireShiftUnit();
        if (!Can("hardware_manage")) throw new InvalidOperationException("Seu acesso não permite conectar celulares.");
        var hardwareStore = new LocalHardwareProfileStore();
        await IntegrationApi().HardwareHeartbeatAsync(unitId, hardwareStore, hardwareStore.Load());
        var pairing = await IntegrationApi().CreateHubPairingAsync(unitId);
        var value = pairing.Pairing ?? throw new InvalidOperationException("Não foi possível gerar o código de conexão.");
        var window = new HubPairingWindow(value) { Owner = this };
        window.ShowDialog();
        return await CompletedWindowAsync();
    }

    private async Task<object> OpenSettingsAsync()
    {
        var unitId = RequireShiftUnit();
        _user ??= _store.Load()?.User;
        if (_user is null) throw new InvalidOperationException("Faça login novamente.");
        if (!Can("hardware_manage") && !Can("fiscal_manage")) throw new InvalidOperationException("Seu acesso não permite alterar configurações.");
        var hardwareStore = new LocalHardwareProfileStore();
        var unitName = ShiftString("unit_name") ?? "Unidade";
        var window = new HardwareFiscalSettingsWindow(
            IntegrationApi(),
            hardwareStore,
            _user.TenantId,
            unitId,
            unitName,
            Can("hardware_manage"),
            Can("fiscal_manage")) { Owner = this };
        window.ShowDialog();
        return await CompletedWindowAsync();
    }

    private static Task<object> CompletedWindowAsync() => Task.FromResult<object>(new { closed = true });

    private bool Can(string permission) => _permissions.TryGetValue(permission, out var allowed) && allowed;

    private void RequireShift()
    {
        if (_shift is null) throw new InvalidOperationException("Inicie o turno para usar esta área.");
    }

    private int RequireShiftUnit()
    {
        RequireShift();
        var unitId = ShiftInt("unit_id");
        if (unitId < 1) throw new InvalidOperationException("Selecione uma unidade no turno atual.");
        return unitId;
    }

    private int ShiftInt(string key)
    {
        if (_shift is null || !_shift.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private string? ShiftString(string key)
    {
        if (_shift is null || !_shift.TryGetValue(key, out var value)) return null;
        return value.ValueKind == JsonValueKind.String ? value.GetString() : value.ToString();
    }

    private object OpenLegacy()
    {
        _openingLegacy = true;
        var legacy = new MainWindow();
        legacy.Show();
        Close();
        return new { opened = true };
    }

    private void OpenLegacyButton_Click(object sender, RoutedEventArgs e) => OpenLegacy();
    private async void RetryButton_Click(object sender, RoutedEventArgs e) => await InitializeWebUiAsync();

    private void ShowFallback(string? detail)
    {
        LoadingPanel.Visibility = Visibility.Collapsed;
        FallbackPanel.Visibility = Visibility.Visible;
        FallbackText.Text = string.IsNullOrWhiteSpace(detail)
            ? "Você pode continuar usando o modo clássico."
            : "A nova interface não iniciou. Você pode continuar usando o modo clássico enquanto isso.";
    }

    private void Respond(string id, bool ok, object? data, string? error)
    {
        if (WebView.CoreWebView2 is null) return;
        var response = JsonSerializer.Serialize(new { id, ok, data, error }, _json);
        WebView.CoreWebView2.PostWebMessageAsJson(response);
    }

    private void DisposeClients()
    {
        _integrationApi?.Dispose();
        _integrationApi = null;
        _api?.Dispose();
        _api = null;
    }

    private static string Friendly(Exception ex)
    {
        var message = ex.Message;
        var lower = message.ToLowerInvariant();
        if (lower.Contains("sqlstate") || lower.Contains("pdoexception") || lower.Contains("stack trace") || lower.Contains("constraint failed"))
            return "Não foi possível concluir esta ação. Tente novamente.";
        return string.IsNullOrWhiteSpace(message) ? "Não foi possível concluir esta ação." : message;
    }

    private static string RequiredString(JsonElement payload, string name)
    {
        var value = OptionalString(payload, name);
        return string.IsNullOrWhiteSpace(value) ? throw new InvalidOperationException($"Campo {name} é obrigatório.") : value;
    }

    private static string? OptionalString(JsonElement payload, string name)
    {
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(name, out var value)) return null;
        return value.ValueKind == JsonValueKind.String ? value.GetString()?.Trim() : value.ToString().Trim();
    }

    private static int RequiredInt(JsonElement payload, string name)
    {
        var value = OptionalInt(payload, name);
        return value ?? throw new InvalidOperationException($"Campo {name} é obrigatório.");
    }

    private static int? OptionalInt(JsonElement payload, string name)
    {
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(name, out var value)) return null;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : null;
    }

    private sealed class BridgeRequest
    {
        public string Id { get; set; } = "";
        public string Action { get; set; } = "";
        public JsonElement Payload { get; set; }
    }
}
