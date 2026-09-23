using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using EventMenu.WhatsAppConnect.Models;

namespace EventMenu.WhatsAppConnect.Services;

public sealed class SharedEventMenuSession
{
    private readonly string _directory;
    private readonly string _sessionFile;
    private readonly string _deviceFile;

    public SharedEventMenuSession()
    {
        _directory=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"EventMenu","Desktop");
        _sessionFile=Path.Combine(_directory,"session.dat");
        _deviceFile=Path.Combine(_directory,"device.id");
    }

    public SessionEnvelope? Load()
    {
        if(!File.Exists(_sessionFile))return null;
        try
        {
            var protectedBytes=File.ReadAllBytes(_sessionFile);
            var clear=ProtectedData.Unprotect(protectedBytes,null,DataProtectionScope.CurrentUser);
            return JsonSerializer.Deserialize<SessionEnvelope>(Encoding.UTF8.GetString(clear));
        }
        catch{return null;}
    }

    public void Save(SessionEnvelope session)
    {
        Directory.CreateDirectory(_directory);
        var json=JsonSerializer.Serialize(session);
        var protectedBytes=ProtectedData.Protect(Encoding.UTF8.GetBytes(json),null,DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_sessionFile,protectedBytes);
    }

    public string GetOrCreateDeviceId()
    {
        Directory.CreateDirectory(_directory);
        if(File.Exists(_deviceFile))
        {
            var saved=File.ReadAllText(_deviceFile).Trim();
            if(saved.Length>=8)return saved;
        }
        var id=$"desktop-{Guid.NewGuid():N}";
        File.WriteAllText(_deviceFile,id);
        return id;
    }
}

public sealed class LocalWhatsAppSettingsStore
{
    private readonly string _tenantDirectory;
    private readonly string _settingsFile;

    public LocalWhatsAppSettingsStore(int tenantId)
    {
        _tenantDirectory=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"EventMenu","WhatsApp",tenantId.ToString());
        _settingsFile=Path.Combine(_tenantDirectory,"settings.dat");
    }

    public string TenantDirectory=>_tenantDirectory;
    public string SessionRoot=>Path.Combine(_tenantDirectory,"sessions");

    public LocalWhatsAppSettings LoadOrCreate()
    {
        Directory.CreateDirectory(_tenantDirectory);
        if(File.Exists(_settingsFile))
        {
            try
            {
                var encrypted=File.ReadAllBytes(_settingsFile);
                var clear=ProtectedData.Unprotect(encrypted,null,DataProtectionScope.CurrentUser);
                var existing=JsonSerializer.Deserialize<LocalWhatsAppSettings>(Encoding.UTF8.GetString(clear));
                if(existing is not null&&existing.Secret.Length>=32&&existing.SessionKey.Length==64&&existing.Port is >0 and <=65535)return existing;
            }
            catch{}
        }
        var settings=new LocalWhatsAppSettings
        {
            Secret=Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant(),
            SessionKey=Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant(),
            Port=21467
        };
        Save(settings);
        return settings;
    }

    private void Save(LocalWhatsAppSettings settings)
    {
        var json=JsonSerializer.Serialize(settings);
        var encrypted=ProtectedData.Protect(Encoding.UTF8.GetBytes(json),null,DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_settingsFile,encrypted);
    }
}
