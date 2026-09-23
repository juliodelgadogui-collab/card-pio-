using System.Globalization;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace EventMenu.Desktop.Services;

public sealed class LocalUserQrStore
{
    private readonly string _directory;

    public LocalUserQrStore()
    {
        _directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "EventMenu",
            "Desktop",
            "Qr");
        Directory.CreateDirectory(_directory);
    }

    public LocalUserQrState? Load(int tenantId, int userId, string expectedType)
    {
        var file = FileFor(tenantId, userId, expectedType);
        if (!File.Exists(file)) return null;
        try
        {
            var encrypted = File.ReadAllBytes(file);
            var clear = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.CurrentUser);
            var state = JsonSerializer.Deserialize<LocalUserQrState>(Encoding.UTF8.GetString(clear));
            if (state is null ||
                state.TenantId != tenantId ||
                state.EntityId != userId ||
                !string.Equals(state.Type, expectedType, StringComparison.OrdinalIgnoreCase) ||
                string.IsNullOrWhiteSpace(state.Payload))
            {
                Clear(tenantId, userId, expectedType);
                return null;
            }
            if (IsExpired(state.ExpiresAt))
            {
                Clear(tenantId, userId, expectedType);
                return null;
            }
            return state;
        }
        catch
        {
            Clear(tenantId, userId, expectedType);
            return null;
        }
    }

    public void Save(LocalUserQrState state)
    {
        var file = FileFor(state.TenantId, state.EntityId, state.Type);
        var json = JsonSerializer.Serialize(state);
        var clear = Encoding.UTF8.GetBytes(json);
        var encrypted = ProtectedData.Protect(clear, null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(file, encrypted);
    }

    public void Clear(int tenantId, int userId, string type)
    {
        try
        {
            var file = FileFor(tenantId, userId, type);
            if (File.Exists(file)) File.Delete(file);
        }
        catch
        {
            // Falha ao limpar a cópia local não altera a revogação feita na conta.
        }
    }

    private string FileFor(int tenantId, int userId, string type)
    {
        var safeType = string.Equals(type, "delivery_user", StringComparison.OrdinalIgnoreCase)
            ? "delivery"
            : "employee";
        return Path.Combine(_directory, $"my-qr-{Math.Max(0, tenantId)}-{Math.Max(0, userId)}-{safeType}.dat");
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

        // Se a validade vier em formato desconhecido, a validação online continuará sendo a autoridade.
        return false;
    }
}

public sealed class LocalUserQrState
{
    public int TenantId { get; set; }
    public string Type { get; set; } = "employee";
    public int EntityId { get; set; }
    public string Label { get; set; } = "";
    public string Payload { get; set; } = "";
    public string? ExpiresAt { get; set; }
}
