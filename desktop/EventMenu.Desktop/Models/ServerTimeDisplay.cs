using System.Globalization;

namespace EventMenu.Desktop.Models;

internal static class ServerTimeDisplay
{
    private static readonly string[] UtcWithoutOffsetFormats =
    {
        "yyyy-MM-dd HH:mm:ss",
        "yyyy-MM-dd HH:mm:ss.FFFFFFF",
        "yyyy-MM-dd'T'HH:mm:ss",
        "yyyy-MM-dd'T'HH:mm:ss.FFFFFFF"
    };

    public static string Local(string value, string format = "dd/MM/yyyy HH:mm")
    {
        if (string.IsNullOrWhiteSpace(value)) return "";
        value = value.Trim();

        // Respostas com Z ou offset explícito já carregam a zona e podem ser convertidas diretamente.
        if (HasExplicitOffset(value)
            && DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.AllowWhiteSpaces, out var offset))
            return offset.ToLocalTime().ToString(format, CultureInfo.GetCultureInfo("pt-BR"));

        // O banco do EventMenu grava CURRENT_TIMESTAMP/NOW em UTC. Strings SQL sem sufixo de zona
        // precisam ser interpretadas como UTC antes de ir para o horário local do Windows.
        if (DateTime.TryParseExact(
                value,
                UtcWithoutOffsetFormats,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var utc))
            return utc.ToLocalTime().ToString(format, CultureInfo.GetCultureInfo("pt-BR"));

        if (DateTimeOffset.TryParse(
                value,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var fallback))
            return fallback.ToLocalTime().ToString(format, CultureInfo.GetCultureInfo("pt-BR"));

        return value;
    }

    private static bool HasExplicitOffset(string value)
    {
        if (value.EndsWith('Z') || value.EndsWith('z')) return true;
        var timeSeparator = value.IndexOf('T');
        if (timeSeparator < 0) timeSeparator = value.IndexOf(' ');
        if (timeSeparator < 0) return false;

        var tail = value[(timeSeparator + 1)..];
        return tail.LastIndexOf('+') >= 0 || tail.LastIndexOf('-') >= 0;
    }
}
