using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public interface IPaymentTerminalProvider
{
    string ProviderKey { get; }
    Task<TerminalPaymentResult> SaleAsync(TerminalIntent intent,PaymentTerminalConfig terminal,CancellationToken ct=default);
    Task<TerminalPaymentResult> CancelAsync(TerminalIntent intent,PaymentTerminalConfig terminal,CancellationToken ct=default);
}

public sealed class PaymentTerminalCoordinator
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly Dictionary<string,IPaymentTerminalProvider> _providers;

    public PaymentTerminalCoordinator(DesktopIntegrationApiClient api,IEnumerable<IPaymentTerminalProvider> providers)
    {
        _api=api;
        _providers=providers.ToDictionary(p=>p.ProviderKey,StringComparer.OrdinalIgnoreCase);
    }

    public async Task<TerminalFlowResult> ChargeAsync(TerminalPaymentRequest request,PaymentTerminalConfig terminal,CancellationToken ct=default)
    {
        if(request.AmountCents<=0)throw new InvalidOperationException("Valor TEF inválido.");
        if(terminal.Enabled!=1)throw new InvalidOperationException("Terminal TEF desativado.");
        if(!_providers.TryGetValue(terminal.Provider,out var provider))
            throw new InvalidOperationException($"O driver {terminal.Provider} ainda não está instalado neste computador.");

        var created=await _api.CreateTerminalIntentAsync(request,terminal.Id,ct);
        var intent=created.Intent??throw new InvalidOperationException("O servidor não criou a intenção TEF.");
        if(string.IsNullOrWhiteSpace(intent.IntentToken))throw new InvalidOperationException("Intenção TEF sem token.");

        await _api.MarkTerminalProcessingAsync(intent.IntentToken,ct);
        TerminalPaymentResult local;
        try
        {
            local=await provider.SaleAsync(intent,terminal,ct);
        }
        catch(OperationCanceledException)
        {
            throw;
        }
        catch(Exception ex)
        {
            local=new TerminalPaymentResult(false,terminal.Provider,"","",request.AmountCents,$"Falha no terminal: {ex.Message}");
        }

        var raw=new Dictionary<string,object?>
        {
            ["provider"]=local.Provider,
            ["message"]=local.Message,
            ["amount_cents"]=local.AmountCents,
            ["receipt_available"]=!string.IsNullOrWhiteSpace(local.ReceiptText),
        };
        var recorded=await _api.RecordTerminalResultAsync(intent.IntentToken,local,raw,ct);
        var serverIntent=recorded.Intent??intent;

        return new TerminalFlowResult(
            local.Approved,
            serverIntent.Status=="verified",
            serverIntent.Status,
            local.Message,
            intent.IntentToken,
            local.TransactionCode,
            local.AuthorizationCode,
            local.ReceiptText);
    }
}

public sealed record TerminalFlowResult(
    bool ApprovedLocally,
    bool VerifiedByServer,
    string ServerStatus,
    string Message,
    string IntentToken,
    string TransactionCode,
    string AuthorizationCode,
    string? ReceiptText);
