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
            _printer.PrintText(payload.PrinterTarget,text,$"EventMenu #{queue.OrderId} • {queue.StationName}");
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
        var q=payload.Queue;var sb=new StringBuilder();
        sb.AppendLine("================================");
        sb.AppendLine($"EVENTMENU • {Safe(q.StationName).ToUpperInvariant()}");
        sb.AppendLine($"PEDIDO #{q.OrderId}");
        if(!string.IsNullOrWhiteSpace(q.TableName))sb.AppendLine($"MESA: {Safe(q.TableName)}");
        if(!string.IsNullOrWhiteSpace(q.CustomerName))sb.AppendLine($"CLIENTE: {Safe(q.CustomerName)}");
        sb.AppendLine($"CANAL: {Channel(q.Channel)}");
        if(DateTimeOffset.TryParse(q.OrderCreatedAt,out var created))sb.AppendLine($"PEDIDO: {created.ToLocalTime():dd/MM HH:mm}");
        sb.AppendLine("--------------------------------");
        foreach(var item in payload.Items)
        {
            var quantity=item.Quantity.ToString("0.###",PtBr);
            var prefix=item.Kind.Equals("modifier",StringComparison.OrdinalIgnoreCase)?"  + ":"";
            sb.AppendLine($"{prefix}{quantity}x {Safe(item.Description)}");
        }
        if(!string.IsNullOrWhiteSpace(q.OrderNotes))
        {
            sb.AppendLine("--------------------------------");
            sb.AppendLine("OBSERVAÇÕES:");
            sb.AppendLine(Safe(q.OrderNotes));
        }
        sb.AppendLine("================================");
        sb.AppendLine("Produção EventMenu");
        return sb.ToString();
    }

    private static string Channel(string value)=>value switch
    {
        "counter"=>"Balcão","pickup"=>"Retirada","delivery"=>"Entrega","table"=>"Mesa","event_bar"=>"Evento",_=>"Atendimento"
    };
    private static string Safe(string? value)=>string.Join(' ',(value??"").Replace("\r"," ").Replace("\n"," ").Split(' ',StringSplitOptions.RemoveEmptyEntries)).Trim();
    private static string Friendly(string message)
    {
        var lower=message.ToLowerInvariant();
        return lower.Contains("win32")||lower.Contains("stack")||lower.Contains("exception")?"Falha ao acessar a impressora configurada.":message;
    }
}
