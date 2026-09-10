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
    private readonly bool _canTables;
    private readonly bool _canTickets;
    private readonly bool _canGuests;
    private readonly bool _canOrders;
    private readonly bool _canAssignDelivery;
    private readonly bool _canDiscountRequest;
    private readonly bool _canCancellationRequest;
    private QrResolveResponse? _current;
    private OperationalOrderDetail? _currentOrder;
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
        bool canCancellationRequest)
    {
        _store = store;
        _api = new OperationalActionsApiClient(store);
        _canTables = canTables;
        _canTickets = canTickets;
        _canGuests = canGuests;
        _canOrders = canOrders;
        _canAssignDelivery = canAssignDelivery;
        _canDiscountRequest = canDiscountRequest;
        _canCancellationRequest = canCancellationRequest;
        InitializeComponent();
        Loaded += (_, _) => CodeBox.Focus();
        Closed += (_, _) => _api.Dispose();
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
        _currentOrder = null;
        ActionButton.IsEnabled = false;
        OperationStatusText.Text = "Consultando...";
        try
        {
            try
            {
                _current = await _api.ResolveQrAsync(value);
                RenderResult(_current);
            }
            catch (ApiClientException ex) when (ex.StatusCode == HttpStatusCode.NotFound && _canOrders)
            {
                var orderResponse = await _api.ResolveOrderQrAsync(NormalizeScannedToken(value));
                _currentOrder = orderResponse.Order ?? throw new InvalidOperationException("Pedido não encontrado para este código.");
                RenderOrderResult(_currentOrder);
            }
            OperationStatusText.Text = "Código reconhecido.";
        }
        catch (Exception ex)
        {
            _current = null;
            _currentOrder = null;
            TypeText.Text = "CÓDIGO NÃO RECONHECIDO";
            ResultTitleText.Text = "Não encontramos este código";
            PrimaryInfoText.Text = "Confira a leitura e tente novamente.";
            SecondaryInfoText.Text = "";
            TertiaryInfoText.Text = "";
            StateText.Text = "Atenção";
            TableLabelPanel.Visibility = Visibility.Collapsed;
            ActionButton.Visibility = Visibility.Collapsed;
            OperationStatusText.Text = ex.Message;
        }
        finally
        {
            _busy = false;
            UpdateActionAvailability();
        }
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
                PrimaryInfoText.Text = "Este código foi reconhecido, mas não possui uma ação disponível nesta versão.";
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

    private async Task ExecuteActionAsync()
    {
        if (_busy) return;
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
        if (_current is null) return;

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
            OperationStatusText.Text = ex.Message;
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
        "inactive" => "Inativa",
        _ => status
    };

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
