using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class SecureSessionStore
{
    private readonly string _sessionFile;

    public SecureSessionStore()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "EventMenu",
            "Desktop");
        Directory.CreateDirectory(directory);
        _sessionFile = Path.Combine(directory, "session.dat");
    }

    public void Save(LoginResponse login)
    {
        var envelope = new SessionEnvelope
        {
            Token = login.Token,
            RefreshToken = login.RefreshToken,
            ExpiresAt = login.ExpiresAt,
            RefreshExpiresAt = login.RefreshExpiresAt,
            User = login.User
        };

        var json = JsonSerializer.Serialize(envelope);
        var clear = Encoding.UTF8.GetBytes(json);
        var protectedBytes = ProtectedData.Protect(clear, null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_sessionFile, protectedBytes);
    }

    public SessionEnvelope? Load()
    {
        if (!File.Exists(_sessionFile)) return null;
        try
        {
            var protectedBytes = File.ReadAllBytes(_sessionFile);
            var clear = ProtectedData.Unprotect(protectedBytes, null, DataProtectionScope.CurrentUser);
            return JsonSerializer.Deserialize<SessionEnvelope>(Encoding.UTF8.GetString(clear));
        }
        catch
        {
            Clear();
            return null;
        }
    }

    public void Save(SessionEnvelope session)
    {
        var json = JsonSerializer.Serialize(session);
        var protectedBytes = ProtectedData.Protect(Encoding.UTF8.GetBytes(json), null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_sessionFile, protectedBytes);
    }

    public void Clear()
    {
        try
        {
            if (File.Exists(_sessionFile)) File.Delete(_sessionFile);
        }
        catch
        {
            // A API ainda revoga o token no servidor; falha local não deve travar o logout.
        }
    }
}

public sealed class SessionEnvelope
{
    public string Token { get; set; } = "";
    public string RefreshToken { get; set; } = "";
    public string ExpiresAt { get; set; } = "";
    public string RefreshExpiresAt { get; set; } = "";
    public UserInfo? User { get; set; }
}
