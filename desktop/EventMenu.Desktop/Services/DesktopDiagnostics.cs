using System.Text.Json;
using System.Text.RegularExpressions;

namespace EventMenu.Desktop.Services;

public static class DesktopDiagnostics
{
    private static readonly object Gate = new();
    private static readonly Regex BearerRegex = new(@"Bearer\s+[A-Za-z0-9._~+\-/]+", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex SecretRegex = new(@"((?:access[_-]?token|refresh[_-]?token|secret|password|client[_-]?secret|api[_-]?key)\s*[:=]\s*)[^\s,;]+", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex CardLikeRegex = new(@"\b\d{13,19}\b", RegexOptions.Compiled);

    public static string Capture(Exception exception, string operation, IReadOnlyDictionary<string, object?>? context = null)
    {
        var reference = Convert.ToHexString(Guid.NewGuid().ToByteArray())[..8];
        try
        {
            var directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "EventMenu", "Logs");
            Directory.CreateDirectory(directory);
            var path = Path.Combine(directory, $"eventmenu-{DateTime.UtcNow:yyyy-MM-dd}.log");
            var safeContext = new Dictionary<string, object?>();
            if (context is not null)
            {
                foreach (var pair in context)
                {
                    var key = pair.Key ?? "context";
                    if (Regex.IsMatch(key, "pass|secret|token|authorization|card|cvv|credential|access[_-]?key", RegexOptions.IgnoreCase))
                    {
                        safeContext[key] = "[redacted]";
                        continue;
                    }
                    safeContext[key] = pair.Value is null ? null : Redact(pair.Value.ToString() ?? string.Empty, 500);
                }
            }

            var record = new
            {
                time = DateTimeOffset.UtcNow,
                reference,
                operation = Limit(operation, 100),
                exception = exception.GetType().FullName,
                message = Redact(exception.Message, 1800),
                stack = Redact(exception.StackTrace ?? string.Empty, 6000),
                context = safeContext,
            };
            var line = JsonSerializer.Serialize(record) + Environment.NewLine;
            lock (Gate) File.AppendAllText(path, line);
        }
        catch
        {
            // Diagnóstico nunca pode derrubar a operação principal.
        }
        return reference;
    }

    private static string Redact(string value, int max)
    {
        var clean = Limit(value, max);
        clean = BearerRegex.Replace(clean, "Bearer [redacted]");
        clean = SecretRegex.Replace(clean, "$1[redacted]");
        clean = CardLikeRegex.Replace(clean, "[redacted-number]");
        return clean;
    }

    private static string Limit(string value, int max) => (value ?? string.Empty).Trim()[..Math.Min((value ?? string.Empty).Trim().Length, max)];
}
