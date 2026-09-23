using System.IO;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.Json;

namespace EventMenu.Desktop.Services;

public sealed class LocalFiscalCertificateVault
{
    private readonly string _directory;

    public LocalFiscalCertificateVault()
    {
        _directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "EventMenu",
            "Desktop",
            "Fiscal");
        Directory.CreateDirectory(_directory);
    }

    public LocalCertificateMetadata Save(int tenantId,int profileId,byte[] pfx,string password)
    {
        if(tenantId<1||profileId<1)throw new InvalidOperationException("Empresa ou perfil fiscal inválido.");
        if(pfx.Length==0||pfx.Length>2*1024*1024)throw new InvalidOperationException("Certificado PFX/P12 inválido.");
        if(password.Length>300)throw new InvalidOperationException("Senha do certificado inválida.");

        using var certificate = new X509Certificate2(
            pfx,
            password,
            X509KeyStorageFlags.EphemeralKeySet | X509KeyStorageFlags.Exportable);
        if(!certificate.HasPrivateKey)throw new InvalidOperationException("O certificado precisa conter a chave privada.");
        if(certificate.NotAfter.ToUniversalTime()<=DateTime.UtcNow)throw new InvalidOperationException("O certificado está expirado.");

        var envelope = new LocalCertificateEnvelope
        {
            TenantId=tenantId,
            ProfileId=profileId,
            PfxBase64=Convert.ToBase64String(pfx),
            Password=password,
            Subject=certificate.Subject,
            Issuer=certificate.Issuer,
            SerialNumber=certificate.SerialNumber,
            Thumbprint=certificate.Thumbprint,
            ValidFrom=certificate.NotBefore.ToUniversalTime(),
            ValidUntil=certificate.NotAfter.ToUniversalTime(),
            SavedAt=DateTime.UtcNow
        };

        var clear=Encoding.UTF8.GetBytes(JsonSerializer.Serialize(envelope));
        var protectedBytes=ProtectedData.Protect(clear,null,DataProtectionScope.CurrentUser);
        File.WriteAllBytes(PathFor(tenantId,profileId),protectedBytes);
        CryptographicOperations.ZeroMemory(clear);

        return Metadata(envelope);
    }

    public LocalCertificateSecret? Load(int tenantId,int profileId)
    {
        var path=PathFor(tenantId,profileId);
        if(!File.Exists(path))return null;
        byte[]? clear=null;
        try
        {
            var protectedBytes=File.ReadAllBytes(path);
            clear=ProtectedData.Unprotect(protectedBytes,null,DataProtectionScope.CurrentUser);
            var envelope=JsonSerializer.Deserialize<LocalCertificateEnvelope>(Encoding.UTF8.GetString(clear));
            if(envelope is null||envelope.TenantId!=tenantId||envelope.ProfileId!=profileId)throw new InvalidOperationException("Certificado local inválido.");
            return new LocalCertificateSecret(Convert.FromBase64String(envelope.PfxBase64),envelope.Password,Metadata(envelope));
        }
        catch
        {
            return null;
        }
        finally
        {
            if(clear is not null)CryptographicOperations.ZeroMemory(clear);
        }
    }

    public LocalCertificateMetadata? GetMetadata(int tenantId,int profileId) => Load(tenantId,profileId)?.Metadata;

    public void Remove(int tenantId,int profileId)
    {
        var path=PathFor(tenantId,profileId);
        if(File.Exists(path))File.Delete(path);
    }

    private string PathFor(int tenantId,int profileId)=>Path.Combine(_directory,$"certificate-{tenantId}-{profileId}.dat");

    private static LocalCertificateMetadata Metadata(LocalCertificateEnvelope e)=>new(
        e.Subject,e.Issuer,e.SerialNumber,e.Thumbprint,e.ValidFrom,e.ValidUntil,e.SavedAt);

    private sealed class LocalCertificateEnvelope
    {
        public int TenantId{get;set;}
        public int ProfileId{get;set;}
        public string PfxBase64{get;set;}="";
        public string Password{get;set;}="";
        public string Subject{get;set;}="";
        public string Issuer{get;set;}="";
        public string SerialNumber{get;set;}="";
        public string Thumbprint{get;set;}="";
        public DateTime ValidFrom{get;set;}
        public DateTime ValidUntil{get;set;}
        public DateTime SavedAt{get;set;}
    }
}

public sealed record LocalCertificateMetadata(
    string Subject,string Issuer,string SerialNumber,string Thumbprint,
    DateTime ValidFrom,DateTime ValidUntil,DateTime SavedAt);

public sealed record LocalCertificateSecret(byte[] Pfx,string Password,LocalCertificateMetadata Metadata);
