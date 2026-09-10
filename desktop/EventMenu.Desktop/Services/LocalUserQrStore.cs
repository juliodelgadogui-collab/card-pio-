using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace EventMenu.Desktop.Services;

public sealed class LocalUserQrStore
{
    private readonly string _file;

    public LocalUserQrStore()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "EventMenu",
            "Desktop");
        Directory.CreateDirectory(directory);
        _file = Path.Combine(directory, "my-qr.dat");
    }

    public LocalUserQrState? Load(int userId)
    {
        if (!File.Exists(_file)) return null;
        try
        {
            var encrypted = File.ReadAllBytes(_file);
            var clear = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.CurrentUser);
            var state = JsonSerializer.Deserialize<LocalUserQrState>(Encoding.UTF8.GetString(clear));
            if (state is null || state.EntityId != userId || string.IsNullOrWhiteSpace(state.Payload)) return null;
            if (!string.IsNullOrWhiteSpace(state.ExpiresAt) && DateTimeOffset.TryParse(state.ExpiresAt, out var expires) && expires <= DateTimeOffset.UtcNow)
            {
                Clear();
                return null;
            }
            return state;
        }
        catch
        {
            Clear();
            return null;
        }
    }

    public void Save(LocalUserQrState state)
    {
        var json = JsonSerializer.Serialize(state);
        var clear = Encoding.UTF8.GetBytes(json);
        var encrypted = ProtectedData.Protect(clear, null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_file, encrypted);
    }

    public void Clear()
    {
        try
        {
            if (File.Exists(_file)) File.Delete(_file);
        }
        catch
        {
            // Falha ao limpar o cache local não altera a revogação feita na conta.
        }
    }
}

public sealed class LocalUserQrState
{
    public string Type { get; set; } = "employee";
    public int EntityId { get; set; }
    public string Label { get; set; } = "";
    public string Payload { get; set; } = "";
    public string? ExpiresAt { get; set; }
}
