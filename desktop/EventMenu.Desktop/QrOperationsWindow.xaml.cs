using System.Net;
using System.Text.Json;
using System.Windows;
using System.Windows.Input;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class QrOperationsWindow : Window
{
    private readonly SecureSessionStore _store;
    private readonly OperationalActionsApiClient _api;
    private readonly UniversalQrApiClient _universalQrApi;
    private readonly bool _canTables;
    private readonly bool _canTickets;
    private readonly bool _canGuests;
    private readonly bool _canOrders;
    private readonly bool _canAssignDelivery;
    private readonly bool _canDiscountRequest;
    private readonly bool _canCancellationRequest;
    private readonly bool _canCash;
    private QrResolveResponse? _current;
    private UniversalQrResult? _currentUniversal;
    private OperationalOrderDetail? _currentOrder;
    private DeliveryCashHandoff? _currentHandoff;
    private string _handoffToken = "";
    private string _rawValue = "";
    private bool _busy;

    public bool OperationChanged { get; private set; }

    public QrOperationsWindow(
        SecureSessionStore store,
        bool canTables,
        bool canTickets,
        bool canGuests,
        bool canOrders,
        bool canAssignDelivery,
        bool canDiscountRequest,
        bool canCancellationRequest,
        bool canCash = false)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _universalQrApi = new UniversalQrApiClient(store);
        _canTables = canTables;
        _canTickets = canTickets;
        _canGuests = canGuests;
        _canOrders = canOrders;
        _canAssignDelivery = canAssignDelivery;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        _canCash = canCash;
        InitializeComponent();
        Loaded += (_, _) => CodeBox.Focus();
        Closed += (_, _) =>
        {
            _api.Dispose();
            _universalQrApi.Dispose();
        };
    }

    private async Task ResolveAsync()
    {
        if (_busy) return;
        var value = CodeBox.Text.Trim();
        if (value.Length == 0)
        {
            OperationStatusText.Text = "Leia ou informe um código.";
            CodeBox.Focus();
            return;
        }

        _busy = true;
        _rawValue = value;
        _current = null;
        _currentUniversal = null;
        _currentOrder = null;
        _currentHandoff = null;
        _handoffToken = "";
        ActionButton.IsEnabled = false;
        OperationStatusText.Text = "Consultando...";
        try
        {
            var handoffToken = ExtractHandoffToken(value);
            if (!string.IsNullOrWhiteSpace(handoffToken))
            {
                if (!_canCash)
                    throw new InvalidOperationException("Este QR é um repasse de dinheiro e precisa ser confirmado por um operador de caixa autorizado.");

                _handoffToken = handoffToken;
                _currentHandoff = (await _api.ResolveDeliveryHandoffAsync(handoffToken)).Handoff
                    ?? throw new InvalidOperationException("Repasse não encontrado.");
                RenderHandoffResult(_currentHandoff);
            }
            else if (LooksLikeUniversalQr(value))
            {
                await ResolveUniversalAsync(value);
            }
            else
            {
                await ResolveOperationalOrFallbackAsync(value);
            }
            OperationStatusText.Text = "Código reconhecido.";
        }
        catch (Exception ex)
        {
            _current = null;
            _currentUniversal = null;
            _currentOrder = null;
            _currentHandoff = null;
            _handoffToken = "";
            TypeText.Text = "CÓDIGO NÃO RECONHECIDO";
            ResultTitleText.Text = "Não encontramos uma ação disponível";
            PrimaryInfoText.Text = "Confira o código e a permissão do operador.";
            SecondaryInfoText.Text = "";
            TertiaryInfoText.Text = "";
            StateText.Text = "Atenção";
            TableLabelPanel.Visibility = Visibility.Collapsed;
            ActionButton.Visibility = Visibility.Collapsed;
            OperationStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            UpdateActionAvailability();
        }
    }

    private async Task ResolveOperationalOrFallbackAsync(string value)
    {
        try
        {
            _current = await _api.ResolveQrAsync(value);
            RenderResult(_current);
            return;
        }
        catch (ApiClientException ex) when (ex.StatusCode == HttpStatusCode.NotFound)
        {
            // Continua para os outros formatos de QR suportados pelo EventMenu.
        }

        if (_canOrders)
        {
            try
            {
                var orderResponse = await _api.ResolveOrderQrAsync(NormalizeScannedToken(value));
                _currentOrder = orderResponse.Order ?? throw new InvalidOperationException("Pedido não encontrado para este código.");
                RenderOrderResult(_currentOrder);
                return;
            }
            catch (ApiClientException ex) when (ex.StatusCode == HttpStatusCode.NotFound)
            {
                // Pode ser um QR universal sem o prefixo EVENTMENU:QR:.
            }
        }

        await ResolveUniversalAsync(value);
    }

    private async Task ResolveUniversalAsync(string value)
    {
        var response = await _universalQrApi.ResolveAsync(value);
        _currentUniversal = response.Qr ?? throw new InvalidOperationException("QR EventMenu não encontrado.");
        RenderUniversalResult(_currentUniversal);
    }

    private void RenderResult(QrResolveResponse response)
    {
        var data = response.Data;
        TableLabelPanel.Visibility = Visibility.Collapsed;
        ActionButton.Visibility = Visibility.Collapsed;

        switch (response.Type)
        {
            case "table":
            {
                var name = Text(data, "name", "Mesa");
                var status = Text(data, "status", "");
                var tabId = Number(data, "tab_id");
                var tabLabel = Text(data, "tab_label", "");
                TypeText.Text = "MESA / COMANDA";
                ResultTitleText.Text = name;
                PrimaryInfoText.Text = tabId > 0 ? "Existe uma comanda aberta nesta mesa." : "Mesa pronta para atendimento.";
                SecondaryInfoText.Text = tabId > 0
                    ? $"Comanda: {(string.IsNullOrWhiteSpace(tabLabel) ? $"#{tabId}" : tabLabel)}"
                    : "Você pode abrir uma nova comanda diretamente daqui.";
                TertiaryInfoText.Text = string.IsNullOrWhiteSpace(status) ? "" : $"Situação: {FriendlyTableStatus(status)}";
                StateText.Text = tabId > 0 ? "Em atendimento" : "Livre";
                if (tabId <= 0 && _canTables)
                {
                    TableLabelPanel.Visibility = Visibility.Visible;
                    ActionButton.Content = "Abrir comanda";
                    ActionButton.Visibility = Visibility.Visible;
                }
                break;
            }
            case "ticket":
            {
                var eventName = Text(data, "event_name", "Evento");
                var customer = Text(data, "customer_name", "Participante não informado");
                var status = Text(data, "status", "");
                TypeText.Text = "INGRESSO";
                ResultTitleText.Text = eventName;
                PrimaryInfoText.Text = customer;
                SecondaryInfoText.Text = $"Ingresso: {Text(data, "code", "")}";
                TertiaryInfoText.Text = string.IsNullOrWhiteSpace(status) ? "" : $"Situação: {FriendlyEntryStatus(status)}";
                StateText.Text = FriendlyEntryStatus(status);
                if (_canTickets && !EntryAlreadyUsed(status))
                {
                    ActionButton.Content = "Confirmar entrada";
                    ActionButton.Visibility = Visibility.Visible;
                }
                break;
            }
            case "guest":
            {
                var eventName = Text(data, "event_name", "Evento");
                var guestName = Text(data, "name", "Convidado");
                var status = Text(data, "status", "");
                var plusOnes = Number(data, "plus_ones");
                TypeText.Text = "CONVIDADO";
                ResultTitleText.Text = eventName;
                PrimaryInfoText.Text = guestName;
                SecondaryInfoText.Text = plusOnes > 0 ? $"Acompanhantes permitidos: {plusOnes}" : "Sem acompanhantes cadastrados.";
                TertiaryInfoText.Text = string.IsNullOrWhiteSpace(status) ? "" : $"Situação: {FriendlyEntryStatus(status)}";
                StateText.Text = FriendlyEntryStatus(status);
                if (_canGuests && !EntryAlreadyUsed(status))
                {
                    ActionButton.Content = "Confirmar entrada";
                    ActionButton.Visibility = Visibility.Visible;
                }
                break;
            }
            default:
                TypeText.Text = "CÓDIGO";
                ResultTitleText.Text = "Código reconhecido";
                PrimaryInfoText.Text = "Este código foi reconhecido, mas não possui uma ação disponível para este operador.";
                SecondaryInfoText.Text = "";
                TertiaryInfoText.Text = "";
                StateText.Text = "Reconhecido";
                break;
        }
    }

    private void RenderUniversalResult(UniversalQrResult qr)
    {
        var data = qr.Data;
        TableLabelPanel.Visibility = Visibility.Collapsed;
        ActionButton.Visibility = Visibility.Collapsed;

        switch (qr.Type)
        {
            case "employee":
            case "delivery_user":
            {
                var name = Text(data, "name", qr.Type == "delivery_user" ? "Entregador" : "Funcionário");
                var role = RoleLabel(Text(data, "role", ""));
                var email = Text(data, "email", "");
                var shift = Object(data, "shift");
                var shiftMode = shift is null ? "" : Text(shift, "mode", "");
                var started = shift is null ? "" : Text(shift, "started_at", "");

                TypeText.Text = qr.Type == "delivery_user" ? "ENTREGADOR" : "FUNCIONÁRIO";
                ResultTitleText.Text = name;
                PrimaryInfoText.Text = string.IsNullOrWhiteSpace(role) ? "Funcionário EventMenu" : role;
                SecondaryInfoText.Text = string.IsNullOrWhiteSpace(email) ? "" : email;
                TertiaryInfoText.Text = shift is null
                    ? "Sem turno aberto."
                    : $"Turno {ModeLabel(shiftMode)}{(string.IsNullOrWhiteSpace(started) ? "" : $" • desde {ServerTimeDisplay.Local(started)}")}";
                StateText.Text = shift is null ? "Sem turno" : "Em atividade";
                break;
            }
            case "customer":
            {
                var name = Text(data, "name", "Cliente");
                var phone = Text(data, "phone", "");
                var email = Text(data, "email", "");
                var points = Number(data, "points");
                TypeText.Text = "CLIENTE";
                ResultTitleText.Text = name;
                PrimaryInfoText.Text = string.IsNullOrWhiteSpace(phone) ? "Cliente EventMenu" : phone;
                SecondaryInfoText.Text = email;
                TertiaryInfoText.Text = $"Pontos de fidelidade: {points}";
                StateText.Text = "Identificado";
                break;
            }
            case "event":
            {
                var name = Text(data, "name", "Evento");
                var status = Text(data, "status", "");
                var venue = Text(data, "venue", "");
                var address = Text(data, "address", "");
                var startsAt = Text(data, "starts_at", "");
                TypeText.Text = "EVENTO";
                ResultTitleText.Text = name;
                PrimaryInfoText.Text = string.IsNullOrWhiteSpace(venue) ? "Evento EventMenu" : venue;
                SecondaryInfoText.Text = address;
                TertiaryInfoText.Text = string.IsNullOrWhiteSpace(startsAt) ? "" : $"Início: {ServerTimeDisplay.Local(startsAt)}";
                StateText.Text = FriendlyEventStatus(status);
                break;
            }
            case "tab":
            {
                var table = Text(data, "table_name", "Comanda");
                var label = Text(data, "label", "");
                var status = Text(data, "status", "");
                var openedAt = Text(data, "opened_at", "");
                var seats = Number(data, "seats");
                TypeText.Text = "COMANDA";
                ResultTitleText.Text = table;
                PrimaryInfoText.Text = string.IsNullOrWhiteSpace(label) ? $"Comanda #{qr.EntityId}" : label;
                SecondaryInfoText.Text = seats > 0 ? $"Lugares: {seats}" : "";
                TertiaryInfoText.Text = string.IsNullOrWhiteSpace(openedAt) ? "" : $"Aberta em {ServerTimeDisplay.Local(openedAt)}";
                StateText.Text = status.Equals("open", StringComparison.OrdinalIgnoreCase) ? "Aberta" : FriendlyTableStatus(status);
                break;
            }
            case "device":
            {
                var name = Text(data, "name", $"Dispositivo #{qr.EntityId}");
                var provider = Text(data, "provider", "");
                var status = Text(data, "status", "");
                var userName = Text(data, "user_name", "");
                TypeText.Text = "DISPOSITIVO";
                ResultTitleText.Text = name;
                PrimaryInfoText.Text = string.IsNullOrWhiteSpace(userName) ? "Dispositivo da operação" : $"Vinculado a {userName}";
                SecondaryInfoText.Text = string.IsNullOrWhiteSpace(provider) ? "" : $"Integração: {provider}";
                TertiaryInfoText.Text = "";
                StateText.Text = string.IsNullOrWhiteSpace(status) ? "Reconhecido" : status;
                break;
            }
            default:
                TypeText.Text = "QR EVENTMENU";
                ResultTitleText.Text = string.IsNullOrWhiteSpace(qr.Label) ? "Código reconhecido" : qr.Label;
                PrimaryInfoText.Text = $"Identificação #{qr.EntityId}";
                SecondaryInfoText.Text = "";
                TertiaryInfoText.Text = "";
                StateText.Text = "Reconhecido";
                break;
        }
    }

    private void RenderOrderResult(OperationalOrderDetail order)
    {
        TableLabelPanel.Visibility = Visibility.Collapsed;
        TypeText.Text = "PEDIDO";
        ResultTitleText.Text = $"Pedido #{order.Id}";
        PrimaryInfoText.Text = string.IsNullOrWhiteSpace(order.CustomerName) ? order.ChannelDisplay : $"{order.CustomerName} • {order.ChannelDisplay}";
        SecondaryInfoText.Text = $"{order.TotalDisplay} • {order.PaymentDisplay}";
        TertiaryInfoText.Text = order.Channel == "delivery" && !string.IsNullOrWhiteSpace(order.DeliveryName)
            ? $"{order.StatusDisplay} • Entregador: {order.DeliveryName}"
            : order.StatusDisplay;
        StateText.Text = order.StatusDisplay;
        ActionButton.Content = "Abrir pedido";
        ActionButton.Visibility = Visibility.Visible;
    }

    private void RenderHandoffResult(DeliveryCashHandoff handoff)
    {
        TableLabelPanel.Visibility = Visibility.Collapsed;
        TypeText.Text = "REPASSE DE ENTREGA";
        ResultTitleText.Text = string.IsNullOrWhiteSpace(handoff.DeliveryName)
            ? "Repasse do entregador"
            : $"Repasse de {handoff.DeliveryName}";
        PrimaryInfoText.Text = $"Valor a receber: {handoff.AmountDisplay}";
        SecondaryInfoText.Text = string.IsNullOrWhiteSpace(handoff.UnitName)
            ? "Confira o dinheiro antes de confirmar."
            : $"Unidade: {handoff.UnitName}";
        TertiaryInfoText.Text = "A confirmação registra a entrada no caixa e a baixa no turno do entregador.";
        StateText.Text = handoff.StatusDisplay;
        ActionButton.Content = "Confirmar recebimento";
        ActionButton.Visibility = _canCash && handoff.Status == "pending" ? Visibility.Visible : Visibility.Collapsed;
    }

    private async Task ExecuteActionAsync()
    {
        if (_busy) return;

        if (_currentHandoff is not null)
        {
            if (!_canCash || string.IsNullOrWhiteSpace(_handoffToken) || _currentHandoff.Status != "pending") return;
            if (MessageBox.Show(
                    $"Você recebeu {_currentHandoff.AmountDisplay} em dinheiro de {(string.IsNullOrWhiteSpace(_currentHandoff.DeliveryName) ? "este entregador" : _currentHandoff.DeliveryName)}?",
                    "Confirmar repasse",
                    MessageBoxButton.YesNo,
                    MessageBoxImage.Question) != MessageBoxResult.Yes) return;

            _busy = true;
            ActionButton.IsEnabled = false;
            OperationStatusText.Text = "Confirmando recebimento...";
            try
            {
                _currentHandoff = (await _api.ConfirmDeliveryHandoffAsync(_handoffToken)).Handoff
                    ?? throw new InvalidOperationException("Não foi possível confirmar o repasse.");
                OperationChanged = true;
                RenderHandoffResult(_currentHandoff);
                OperationStatusText.Text = "Repasse confirmado e registrado no caixa.";
            }
            catch (Exception ex)
            {
                OperationStatusText.Text = Friendly(ex.Message);
            }
            finally
            {
                _busy = false;
                UpdateActionAvailability();
            }
            return;
        }

        if (_currentOrder is not null)
        {
            var window = new OrderDetailsWindow(
                _store,
                _currentOrder.Id,
                _canAssignDelivery,
                _canDiscountRequest,
                _canCancellationRequest) { Owner = this };
            window.ShowDialog();
            OperationChanged |= window.OrderChanged;
            return;
        }
        if (_current is null || _currentUniversal is not null) return;

        _busy = true;
        ActionButton.IsEnabled = false;
        OperationStatusText.Text = "Confirmando...";
        try
        {
            switch (_current.Type)
            {
                case "table":
                {
                    if (!_canTables) return;
                    var tableId = Number(_current.Data, "id");
                    if (tableId < 1) throw new InvalidOperationException("Mesa inválida.");
                    await _api.OpenTableAsync(tableId, TableLabelBox.Text.Trim());
                    OperationChanged = true;
                    OperationStatusText.Text = "Comanda aberta com sucesso.";
                    break;
                }
                case "ticket":
                    if (!_canTickets) return;
                    await _api.TicketCheckInAsync(NormalizeScannedToken(_rawValue));
                    OperationChanged = true;
                    OperationStatusText.Text = "Entrada confirmada.";
                    break;
                case "guest":
                    if (!_canGuests) return;
                    await _api.GuestCheckInAsync(NormalizeScannedToken(_rawValue));
                    OperationChanged = true;
                    OperationStatusText.Text = "Entrada do convidado confirmada.";
                    break;
                default:
                    return;
            }

            await ResolveAsyncAfterAction();
        }
        catch (Exception ex)
        {
            OperationStatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            UpdateActionAvailability();
        }
    }

    private async Task ResolveAsyncAfterAction()
    {
        try
        {
            _current = await _api.ResolveQrAsync(_rawValue);
            RenderResult(_current);
        }
        catch
        {
            ActionButton.Visibility = Visibility.Collapsed;
        }
    }

    private void UpdateActionAvailability()
    {
        ActionButton.IsEnabled = !_busy && ActionButton.Visibility == Visibility.Visible;
    }

    private static bool LooksLikeUniversalQr(string raw)
    {
        var value = raw.Trim();
        if (value.StartsWith("EVENTMENU:QR:", StringComparison.OrdinalIgnoreCase)) return true;
        if (value.Length == 64 && value.All(Uri.IsHexDigit)) return false;
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)) return false;
        var query = uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries);
        foreach (var part in query)
        {
            var pieces = part.Split('=', 2);
            if (pieces.Length != 2) continue;
            var key = Uri.UnescapeDataString(pieces[0]);
            var token = Uri.UnescapeDataString(pieces[1].Replace('+', ' ')).Trim();
            if (key is "qr" or "token" or "t" && token.Length == 64 && token.All(Uri.IsHexDigit)) return true;
        }
        return false;
    }

    private static string? ExtractHandoffToken(string raw)
    {
        var value = raw.Trim();
        const string prefix = "EVENTMENU:HANDOFF:";
        if (value.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
        {
            var direct = value[prefix.Length..].Trim();
            return direct.Length >= 32 ? direct : null;
        }

        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)) return null;
        if (!uri.AbsolutePath.EndsWith("api-go.php", StringComparison.OrdinalIgnoreCase)) return null;

        var query = uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries);
        string? action = null;
        string? token = null;
        foreach (var part in query)
        {
            var pieces = part.Split('=', 2);
            if (pieces.Length != 2) continue;
            var key = Uri.UnescapeDataString(pieces[0]);
            var valuePart = Uri.UnescapeDataString(pieces[1].Replace('+', ' ')).Trim();
            if (key.Equals("action", StringComparison.OrdinalIgnoreCase)) action = valuePart;
            else if (key.Equals("t", StringComparison.OrdinalIgnoreCase)) token = valuePart;
        }
        return action?.Equals("handoff-view", StringComparison.OrdinalIgnoreCase) == true && token?.Length >= 32
            ? token
            : null;
    }

    private static string NormalizeScannedToken(string raw)
    {
        var value = raw.Trim();
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)) return value;
        var query = uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries);
        foreach (var part in query)
        {
            var pieces = part.Split('=', 2);
            if (pieces.Length != 2) continue;
            var key = Uri.UnescapeDataString(pieces[0]);
            if (key is not ("t" or "token" or "code")) continue;
            var token = Uri.UnescapeDataString(pieces[1].Replace('+', ' ')).Trim();
            if (token.Length > 0) return token;
        }
        return value;
    }

    private static string Text(Dictionary<string, JsonElement> data, string key, string fallback)
    {
        if (!data.TryGetValue(key, out var value) || value.ValueKind is JsonValueKind.Null or JsonValueKind.Undefined) return fallback;
        var text = value.ValueKind == JsonValueKind.String ? value.GetString() : value.ToString();
        return string.IsNullOrWhiteSpace(text) ? fallback : text!;
    }

    private static Dictionary<string, JsonElement>? Object(Dictionary<string, JsonElement> data, string key)
    {
        if (!data.TryGetValue(key, out var value) || value.ValueKind != JsonValueKind.Object) return null;
        try { return JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(value.GetRawText()); }
        catch { return null; }
    }

    private static int Number(Dictionary<string, JsonElement> data, string key)
    {
        if (!data.TryGetValue(key, out var value)) return 0;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number)) return number;
        return int.TryParse(value.ToString(), out number) ? number : 0;
    }

    private static bool EntryAlreadyUsed(string status) => status.Equals("used", StringComparison.OrdinalIgnoreCase)
        || status.Equals("checked_in", StringComparison.OrdinalIgnoreCase)
        || status.Equals("checked-in", StringComparison.OrdinalIgnoreCase)
        || status.Equals("cancelled", StringComparison.OrdinalIgnoreCase);

    private static string FriendlyEntryStatus(string status) => status.ToLowerInvariant() switch
    {
        "valid" => "Liberado",
        "active" => "Liberado",
        "pending" => "Aguardando",
        "used" => "Entrada realizada",
        "checked_in" => "Entrada realizada",
        "checked-in" => "Entrada realizada",
        "cancelled" => "Cancelado",
        _ when string.IsNullOrWhiteSpace(status) => "Reconhecido",
        _ => status
    };

    private static string FriendlyTableStatus(string status) => status.ToLowerInvariant() switch
    {
        "available" => "Livre",
        "occupied" => "Ocupada",
        "open" => "Aberta",
        "closed" => "Fechada",
        "inactive" => "Inativa",
        _ => status
    };

    private static string FriendlyEventStatus(string status) => status.ToLowerInvariant() switch
    {
        "published" => "Publicado",
        "draft" => "Rascunho",
        "closed" => "Encerrado",
        "cancelled" => "Cancelado",
        _ when string.IsNullOrWhiteSpace(status) => "Reconhecido",
        _ => status
    };

    private static string RoleLabel(string role) => role.ToLowerInvariant() switch
    {
        "delivery" => "Entregador",
        "cashier" => "Caixa",
        "attendant" => "Balconista",
        "kitchen" => "Cozinha",
        "waiter" => "Garçom",
        "manager" => "Gerente",
        "admin" => "Administrador",
        _ => role
    };

    private static string ModeLabel(string mode) => mode.ToLowerInvariant() switch
    {
        "operation" => "Operação",
        "delivery" => "Delivery",
        "events" => "Eventos",
        "pay" => "Pagamentos",
        _ => mode
    };

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir esta operação. Tente novamente."
            : message;
    }

    private async void ResolveButton_Click(object sender, RoutedEventArgs e) => await ResolveAsync();
    private async void ActionButton_Click(object sender, RoutedEventArgs e) => await ExecuteActionAsync();
    private async void CodeBox_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key != Key.Enter) return;
        e.Handled = true;
        await ResolveAsync();
    }
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
