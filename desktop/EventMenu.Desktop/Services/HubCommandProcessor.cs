using System.Globalization;
using System.Media;
using System.Text;
using System.Text.Json;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class HubCommandProcessor
{
    private readonly DesktopIntegrationApiClient _hubApi;
    private readonly EventMenuApiClient _api;
    private readonly LocalHardwareProfileStore _hardwareStore;
    private readonly RawPrinterService _rawPrinter;
    private readonly CustomerDisplayStateStore _customerDisplay;
    private readonly PaymentTerminalCoordinator? _terminalCoordinator;
    private static readonly CultureInfo PtBr=new("pt-BR");

    public HubCommandProcessor(
        DesktopIntegrationApiClient hubApi,
        EventMenuApiClient api,
        LocalHardwareProfileStore hardwareStore,
        RawPrinterService rawPrinter,
        CustomerDisplayStateStore customerDisplay,
        PaymentTerminalCoordinator? terminalCoordinator=null)
    {
        _hubApi=hubApi;
        _api=api;
        _hardwareStore=hardwareStore;
        _rawPrinter=rawPrinter;
        _customerDisplay=customerDisplay;
        _terminalCoordinator=terminalCoordinator;
    }

    public async Task ProcessAsync(HubCommand command,CancellationToken ct=default)
    {
        if(command.Id<1)return;
        var claimed=await _hubApi.ClaimHubCommandAsync(command.Id,ct);
        var current=claimed.Command??command;
        if(current.Status is "completed" or "failed" or "cancelled" or "expired")return;

        try
        {
            var result=await ExecuteAsync(current,ct);
            await _hubApi.CompleteHubCommandAsync(current.Id,true,result,"",ct);
        }
        catch(OperationCanceledException){throw;}
        catch(Exception ex)
        {
            await _hubApi.CompleteHubCommandAsync(current.Id,false,new{ },Friendly(ex.Message),ct);
        }
    }

    private async Task<object> ExecuteAsync(HubCommand command,CancellationToken ct)
    {
        return command.CommandType switch
        {
            "print_order" => await PrintOrderAsync(command,false,ct),
            "print_receipt" => await PrintOrderAsync(command,true,ct),
            "open_drawer" => OpenDrawer(),
            "tef_charge" => await ChargeTefAsync(command,ct),
            "customer_display" => await CustomerDisplayAsync(command,ct),
            "kitchen_alert" => PlayAlert(command),
            "play_alert" => PlayAlert(command),
            "print_label" => PrintLabel(command),
            _ => throw new InvalidOperationException("Comando do Hub não suportado por esta versão do Desktop."),
        };
    }

    private async Task<object> PrintOrderAsync(HubCommand command,bool receipt,CancellationToken ct)
    {
        var orderId=Int(command.Payload,"order_id");if(orderId<1)throw new InvalidOperationException("Pedido inválido.");
        var details=await _api.OrderDetailsAsync(orderId,ct);var order=details.Order??throw new InvalidOperationException("Pedido não encontrado.");
        var profile=_hardwareStore.Load();var printer=profile.DefaultPrinter;
        if(string.IsNullOrWhiteSpace(printer))throw new InvalidOperationException("Configure a impressora padrão no EventMenu Desktop.");
        var text=BuildOrderText(order,details.Items,receipt);
        _rawPrinter.PrintText(printer,text,receipt?$"EventMenu - Recibo {orderId}":$"EventMenu - Pedido {orderId}");
        return new{printer,order_id=orderId,document=receipt?"receipt":"order"};
    }

    private object OpenDrawer()
    {
        var profile=_hardwareStore.Load();var printer=!string.IsNullOrWhiteSpace(profile.CashDrawer)?profile.CashDrawer:profile.DefaultPrinter;
        if(string.IsNullOrWhiteSpace(printer))throw new InvalidOperationException("Configure a impressora/gaveta neste computador.");
        _rawPrinter.OpenCashDrawer(printer);
        return new{opened=true,printer};
    }

    private async Task<object> ChargeTefAsync(HubCommand command,CancellationToken ct)
    {
        if(_terminalCoordinator is null)throw new InvalidOperationException("Nenhum driver TEF foi carregado neste computador.");
        var orderId=Int(command.Payload,"order_id");var terminalId=Int(command.Payload,"terminal_config_id");var amount=Int(command.Payload,"amount_cents");var installments=Math.Max(1,Int(command.Payload,"installments",1));var paymentType=Text(command.Payload,"payment_type");
        if(orderId<1||terminalId<1||amount<=0)throw new InvalidOperationException("Cobrança TEF incompleta.");
        var terminals=await _hubApi.TerminalListAsync(command.UnitId,ct);var terminal=terminals.Terminals.FirstOrDefault(t=>t.Id==terminalId)??throw new InvalidOperationException("PINPad/TEF não está disponível nesta unidade.");
        var request=new TerminalPaymentRequest(orderId,amount,paymentType,installments,$"hub-tef:{command.Id}:{orderId}");
        var flow=await _terminalCoordinator.ChargeAsync(request,terminal,ct);
        // O retorno local aprovado não significa liquidação. VerifiedByServer continua sendo a autoridade.
        return new{approved_local=flow.ApprovedLocally,verified=flow.VerifiedByServer,status=flow.ServerStatus,message=flow.Message,transaction_code=flow.TransactionCode,authorization_code=flow.AuthorizationCode};
    }

    private async Task<object> CustomerDisplayAsync(HubCommand command,CancellationToken ct)
    {
        var orderId=Int(command.Payload,"order_id");if(orderId<1)throw new InvalidOperationException("Pedido inválido.");
        var details=await _api.OrderDetailsAsync(orderId,ct);var order=details.Order??throw new InvalidOperationException("Pedido não encontrado.");
        _customerDisplay.ShowOrder(order.Id,order.CustomerName??"",order.TotalCents,order.Status);
        return new{shown=true,order_id=order.Id};
    }

    private object PlayAlert(HubCommand command)
    {
        SystemSounds.Exclamation.Play();
        var message=Text(command.Payload,"message");if(!string.IsNullOrWhiteSpace(message))_customerDisplay.ShowMessage(message);
        return new{played=true};
    }

    private object PrintLabel(HubCommand command)
    {
        var profile=_hardwareStore.Load();var printer=profile.DefaultPrinter;if(string.IsNullOrWhiteSpace(printer))throw new InvalidOperationException("Configure a impressora de etiquetas.");
        var text=Text(command.Payload,"text");if(string.IsNullOrWhiteSpace(text))throw new InvalidOperationException("Etiqueta sem conteúdo.");
        _rawPrinter.PrintText(printer,text,$"EventMenu - Etiqueta {command.Id}");return new{printed=true,printer};
    }

    private static string BuildOrderText(Order order,IReadOnlyCollection<OrderItem> items,bool receipt)
    {
        var b=new StringBuilder();b.AppendLine("EVENTMENU");b.AppendLine(receipt?$"RECIBO / PEDIDO #{order.Id}":$"PEDIDO #{order.Id}");b.AppendLine(new string('-',32));
        if(!string.IsNullOrWhiteSpace(order.CustomerName))b.AppendLine($"Cliente: {order.CustomerName}");
        b.AppendLine($"Canal: {order.Channel}");b.AppendLine($"Status: {order.Status}");b.AppendLine(new string('-',32));
        foreach(var item in items)b.AppendLine($"{item.Quantity:0.##}x {item.Name}\n   {Money(item.TotalCents)}");
        b.AppendLine(new string('-',32));b.AppendLine($"TOTAL: {Money(order.TotalCents)}");b.AppendLine();b.AppendLine("EventMenu");return b.ToString();
    }

    private static string Money(int cents)=>(cents/100m).ToString("C2",PtBr);
    private static int Int(Dictionary<string,JsonElement> payload,string key,int fallback=0)=>payload.TryGetValue(key,out var v)&&v.ValueKind==JsonValueKind.Number&&v.TryGetInt32(out var n)?n:payload.TryGetValue(key,out v)&&int.TryParse(v.ToString(),out n)?n:fallback;
    private static string Text(Dictionary<string,JsonElement> payload,string key)=>payload.TryGetValue(key,out var v)?v.ToString():"";
    private static string Friendly(string message){var lower=message.ToLowerInvariant();return lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception")?"Não foi possível concluir a operação no computador.":message;}
}
