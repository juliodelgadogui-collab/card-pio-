using System.Globalization;
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
            if (IsExpired(state.ExpiresAt))
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
            // Falha ao limpar a cópia local não altera a revogação feita na conta.
        }
    }

    private static bool IsExpired(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return false;

        if (DateTimeOffset.TryParse(
                value,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces | DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var offset))
            return offset <= DateTimeOffset.UtcNow;

        if (DateTime.TryParse(
                value,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces | DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var utc))
            return utc <= DateTime.UtcNow;

        // Se a validade vier em formato desconhecido, o servidor continuará sendo a autoridade.
        return false;
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
