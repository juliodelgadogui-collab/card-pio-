using System.Globalization;
using System.Text;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class ProductionPrintProcessor
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly RawPrinterService _printer;
    private static readonly CultureInfo PtBr=new("pt-BR");

    public ProductionPrintProcessor(DesktopIntegrationApiClient api,RawPrinterService printer)
    {
        _api=api;_printer=printer;
    }

    public async Task<bool> ProcessOneAsync(CancellationToken ct=default)
    {
        var claimed=await _api.ClaimProductionPrintAsync(ct);
        var payload=claimed.Print;if(payload is null)return false;
        var queue=payload.Queue;
        try
        {
            if(string.IsNullOrWhiteSpace(payload.PrinterTarget))throw new InvalidOperationException($"A estação {queue.StationName} não possui impressora configurada neste computador.");
            var text=BuildTicket(payload);
            var title=$"EventMenu #{queue.OrderId} • {(queue.PrintScope.Equals("cashier",StringComparison.OrdinalIgnoreCase)?"Caixa":queue.StationName)}";
            _printer.PrintText(payload.PrinterTarget,text,title);
            await _api.CompleteProductionPrintAsync(queue.Id,true,"",ct);
            return true;
        }
        catch(Exception ex)
        {
            try{await _api.CompleteProductionPrintAsync(queue.Id,false,Friendly(ex.Message),ct);}catch{ }
            return false;
        }
    }

    private static string BuildTicket(ProductionPrintPayload payload)
    {
        var q=payload.Queue;var cashier=q.PrintScope.Equals("cashier",StringComparison.OrdinalIgnoreCase);var reprint=q.QueueType.Equals("reprint",StringComparison.OrdinalIgnoreCase);var sb=new StringBuilder();
        sb.AppendLine("================================");
        if(reprint)sb.AppendLine("******** REIMPRESSAO ********");
        sb.AppendLine(cashier?"EVENTMENU • CAIXA":$"EVENTMENU • {Safe(q.StationName).ToUpperInvariant()}");
        sb.AppendLine($"PEDIDO #{q.OrderId}");
        if(!string.IsNullOrWhiteSpace(q.TableName))sb.AppendLine($"MESA: {Safe(q.TableName)}");
        if(!string.IsNullOrWhiteSpace(q.CustomerName))sb.AppendLine($"CLIENTE: {Safe(q.CustomerName)}");
        sb.AppendLine($"CANAL: {Channel(q.Channel)}");
        if(DateTimeOffset.TryParse(q.OrderCreatedAt,out var created))sb.AppendLine($"PEDIDO: {created.ToLocalTime():dd/MM HH:mm}");
        if(cashier&&!string.IsNullOrWhiteSpace(q.DeliveryAddress))sb.AppendLine($"ENTREGA: {Safe(q.DeliveryAddress)}");
        sb.AppendLine("--------------------------------");
        foreach(var item in payload.Items)
        {
            var quantity=item.Quantity.ToString("0.###",PtBr);var modifier=item.Kind.Equals("modifier",StringComparison.OrdinalIgnoreCase);var prefix=modifier?"  + ":"";
            var value=cashier&&item.TotalCents!=0?$"  {Money(item.TotalCents)}":"";
            sb.AppendLine($"{prefix}{quantity}x {Safe(item.Description)}{value}");
            if(!modifier&&!string.IsNullOrWhiteSpace(item.Notes))sb.AppendLine($"   OBS: {Safe(item.Notes)}");
        }
        if(cashier)
        {
            sb.AppendLine("--------------------------------");
            sb.AppendLine($"SUBTOTAL: {Money(q.SubtotalCents)}");
            if(q.DiscountCents>0)sb.AppendLine($"DESCONTO: -{Money(q.DiscountCents)}");
            if(q.DeliveryFeeCents>0)sb.AppendLine($"ENTREGA: {Money(q.DeliveryFeeCents)}");
            sb.AppendLine($"TOTAL: {Money(q.TotalCents)}");
        }
        if(!string.IsNullOrWhiteSpace(q.OrderNotes))
        {
            sb.AppendLine("--------------------------------");sb.AppendLine("OBSERVACOES:");sb.AppendLine(Safe(q.OrderNotes));
        }
        if(reprint&&!string.IsNullOrWhiteSpace(q.Reason)){sb.AppendLine("--------------------------------");sb.AppendLine($"MOTIVO REIMPRESSAO: {Safe(q.Reason)}");}
        sb.AppendLine("================================");
        sb.AppendLine($"Fila {q.Id} • tentativa {q.Attempts}");
        return sb.ToString();
    }

    private static string Money(int cents)=>$"R$ {(cents/100m).ToString("N2",PtBr)}";
    private static string Channel(string value)=>value switch
    {
        "counter"=>"Balcao","pickup"=>"Retirada","delivery"=>"Delivery","table"=>"Mesa","event_bar"=>"Evento",_=>value
    };
    private static string Safe(string? value)=>string.Join(' ',(value??"").Replace("\r"," ").Replace("\n"," ").Split(' ',StringSplitOptions.RemoveEmptyEntries)).Trim();
    private static string Friendly(string message)
    {
        var lower=message.ToLowerInvariant();return lower.Contains("win32")||lower.Contains("stack")||lower.Contains("exception")?"Falha ao acessar a impressora configurada.":message;
    }
}
