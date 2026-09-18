using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class FiscalCertificateEnrollmentService
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly LocalFiscalCertificateVault _localVault;

    public FiscalCertificateEnrollmentService(DesktopIntegrationApiClient api,LocalFiscalCertificateVault localVault)
    {
        _api=api;
        _localVault=localVault;
    }

    public async Task<FiscalCertificateEnrollmentResult> ImportA1Async(
        int tenantId,
        int profileId,
        byte[] pfx,
        string password,
        string certificateMode,
        CancellationToken ct=default)
    {
        if(!new[]{"server","desktop","hybrid"}.Contains(certificateMode,StringComparer.OrdinalIgnoreCase))
            throw new InvalidOperationException("Modo de certificado A1 inválido.");

        LocalCertificateMetadata? localMetadata=null;
        var needsLocal=certificateMode is "desktop" or "hybrid";
        var needsServer=certificateMode is "server" or "hybrid";

        if(needsLocal)localMetadata=_localVault.Save(tenantId,profileId,pfx,password);
        FiscalCertificateMetadata? serverMetadata=null;
        try
        {
            if(needsServer)
            {
                var response=await _api.SaveA1Async(profileId,pfx,password,certificateMode=="hybrid"?"hybrid":"server",ct);
                serverMetadata=response.Certificate??throw new InvalidOperationException("O servidor não confirmou o certificado fiscal.");
            }
        }
        catch
        {
            if(needsLocal)_localVault.Remove(tenantId,profileId);
            throw;
        }

        return new FiscalCertificateEnrollmentResult(certificateMode,localMetadata,serverMetadata);
    }

    public LocalCertificateSecret RequireLocal(int tenantId,int profileId)
    {
        var local=_localVault.Load(tenantId,profileId);
        if(local is null)throw new InvalidOperationException("Certificado fiscal local não está disponível neste usuário do Windows.");
        if(local.Metadata.ValidUntil<=DateTime.UtcNow)throw new InvalidOperationException("Certificado fiscal local expirado.");
        return local;
    }
}

public sealed record FiscalCertificateEnrollmentResult(
    string Mode,
    LocalCertificateMetadata? Local,
    FiscalCertificateMetadata? Server);
