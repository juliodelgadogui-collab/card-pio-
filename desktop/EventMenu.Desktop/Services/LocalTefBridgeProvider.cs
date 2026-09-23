using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

/// <summary>
/// Ponte padronizada entre o EventMenu Desktop e um conector TEF instalado localmente.
/// O conector pode encapsular o SDK homologado de PagBank, Stone, SiTef ou outro adquirente.
/// Por segurança, a URL precisa apontar para loopback; credenciais do adquirente não trafegam
/// pelo EventMenu Desktop e devem permanecer no serviço/SDK homologado ou no servidor.
/// </summary>
public sealed class LocalTefBridgeProvider : IPaymentTerminalProvider, IDisposable
{
    private readonly HttpClient _http=new(){Timeout=TimeSpan.FromSeconds(120)};
    public string ProviderKey { get; }

    public LocalTefBridgeProvider(string providerKey)
    {
        ProviderKey=providerKey;
    }

    public Task<TerminalPaymentResult> SaleAsync(TerminalIntent intent,PaymentTerminalConfig terminal,CancellationToken ct=default)=>
        ExecuteAsync("sale",intent,terminal,ct);

    public Task<TerminalPaymentResult> CancelAsync(TerminalIntent intent,PaymentTerminalConfig terminal,CancellationToken ct=default)=>
        ExecuteAsync("cancel",intent,terminal,ct);

    private async Task<TerminalPaymentResult> ExecuteAsync(string action,TerminalIntent intent,PaymentTerminalConfig terminal,CancellationToken ct)
    {
        var baseUri=BridgeUri(terminal);
        var endpoint=new Uri(baseUri,action);
        var body=new
        {
            version=1,
            provider=terminal.Provider,
            terminal_id=terminal.Id,
            pinpad=terminal.PinpadIdentifier??"",
            intent_token=intent.IntentToken,
            order_id=intent.OrderId,
            amount_cents=intent.AmountCents,
            payment_type=intent.PaymentType,
            installments=intent.Installments,
            reference=$"eventmenu-{intent.OrderId}-{intent.Id}"
        };

        using var response=await _http.PostAsJsonAsync(endpoint,body,ct);
        var text=await response.Content.ReadAsStringAsync(ct);
        if(!response.IsSuccessStatusCode)
            throw new InvalidOperationException("O conector TEF local recusou a operação.");

        BridgeResponse? parsed;
        try{parsed=JsonSerializer.Deserialize<BridgeResponse>(text,new JsonSerializerOptions{PropertyNameCaseInsensitive=true});}
        catch{throw new InvalidOperationException("O conector TEF local retornou uma resposta inválida.");}
        if(parsed is null)throw new InvalidOperationException("O conector TEF local não respondeu corretamente.");
        if(parsed.AmountCents>0&&parsed.AmountCents!=intent.AmountCents)
            throw new InvalidOperationException("O valor retornado pelo conector TEF não confere com a cobrança.");

        return new TerminalPaymentResult(
            parsed.Approved,
            terminal.Provider,
            parsed.TransactionCode??"",
            parsed.AuthorizationCode??"",
            intent.AmountCents,
            string.IsNullOrWhiteSpace(parsed.Message)?(parsed.Approved?"Transação aprovada no terminal.":"Transação não aprovada no terminal."):parsed.Message!,
            parsed.Receipt);
    }

    private static Uri BridgeUri(PaymentTerminalConfig terminal)
    {
        var raw=RuntimeString(terminal.RuntimeConfig,"bridge_url");
        if(string.IsNullOrWhiteSpace(raw))
            throw new InvalidOperationException($"O conector local de {terminal.Provider} ainda não foi configurado neste computador.");
        if(!raw.EndsWith('/'))raw+="/";
        if(!Uri.TryCreate(raw,UriKind.Absolute,out var uri))
            throw new InvalidOperationException("Endereço do conector TEF inválido.");
        if(uri.Scheme is not ("http" or "https"))
            throw new InvalidOperationException("O conector TEF deve usar HTTP ou HTTPS local.");
        var host=uri.Host.Trim('[',']');
        if(!host.Equals("localhost",StringComparison.OrdinalIgnoreCase)&&host!="127.0.0.1"&&host!="::1")
            throw new InvalidOperationException("Por segurança, o conector TEF precisa estar instalado neste próprio computador.");
        return uri;
    }

    private static string RuntimeString(Dictionary<string,object?> values,string key)
    {
        if(!values.TryGetValue(key,out var raw)||raw is null)return "";
        if(raw is JsonElement json)return json.ValueKind==JsonValueKind.String?json.GetString()??"":json.ToString();
        return Convert.ToString(raw)??"";
    }

    public void Dispose()=>_http.Dispose();

    private sealed class BridgeResponse
    {
        public bool Approved { get; set; }
        public int AmountCents { get; set; }
        public string? TransactionCode { get; set; }
        public string? AuthorizationCode { get; set; }
        public string? Message { get; set; }
        public string? Receipt { get; set; }
    }
}
