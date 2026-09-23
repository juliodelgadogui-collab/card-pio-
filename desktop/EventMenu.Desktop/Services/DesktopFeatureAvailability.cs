using System.Collections.Concurrent;
using System.Net;

namespace EventMenu.Desktop.Services;

public static class DesktopFeatureAvailability
{
    private sealed record FeatureState(bool? Available, DateTimeOffset LastProbeAt);

    private static readonly ConcurrentDictionary<string, FeatureState> States = new();
    private static readonly TimeSpan ProbeCooldown = TimeSpan.FromMinutes(5);

    public static bool IsAvailable(string feature) =>
        !States.TryGetValue(feature, out var state) || state.Available != false;

    public static bool IsKnownAvailable(string feature) =>
        States.TryGetValue(feature, out var state) && state.Available == true;

    public static bool ShouldProbe(string feature)
    {
        if (!States.TryGetValue(feature, out var state)) return true;

        // Recursos confirmados continuam disponíveis enquanto a sessão estiver aberta.
        // Recursos desconhecidos ou que estavam ausentes são verificados novamente após
        // o cooldown. Assim uma atualização do servidor passa a ser percebida sem
        // exigir que o operador reinicie o EventMenu Desktop.
        if (state.Available == true) return false;
        return DateTimeOffset.UtcNow - state.LastProbeAt >= ProbeCooldown;
    }

    public static void MarkProbeAttempt(string feature) =>
        States.AddOrUpdate(
            feature,
            _ => new FeatureState(null, DateTimeOffset.UtcNow),
            (_, current) => new FeatureState(current.Available, DateTimeOffset.UtcNow));

    public static void MarkAvailable(string feature) =>
        States[feature] = new FeatureState(true, DateTimeOffset.UtcNow);

    public static void MarkUnavailable(string feature) =>
        States[feature] = new FeatureState(false, DateTimeOffset.UtcNow);

    public static bool MarkUnavailableIfUnsupported(string feature, ApiClientException exception)
    {
        if (!IsUnsupported(exception)) return false;
        MarkUnavailable(feature);
        return true;
    }

    public static bool IsUnsupported(ApiClientException exception) =>
        exception.StatusCode is HttpStatusCode.NotFound
            or HttpStatusCode.MethodNotAllowed
            or HttpStatusCode.NotImplemented;
}
