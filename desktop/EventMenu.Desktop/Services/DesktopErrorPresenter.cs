using System.Net;
using System.Text.Json;
using System.Windows;

namespace EventMenu.Desktop.Services;

public static class DesktopErrorPresenter
{
    public static void Show(Exception exception, string title, Window? owner = null)
    {
        var reference = DesktopDiagnostics.Capture(exception, title);
        var message = FriendlyMessage(exception);
        if (ShouldShowReference(exception))
            message += $"\n\nSe precisar de suporte, informe a referência {reference}.";

        if (owner is not null)
            MessageBox.Show(owner, message, title, MessageBoxButton.OK, MessageBoxImage.Warning);
        else
            MessageBox.Show(message, title, MessageBoxButton.OK, MessageBoxImage.Warning);
    }

    public static string FriendlyMessage(Exception exception)
    {
        if (exception is ApiClientException apiException)
        {
            var apiMessage = apiException.Message?.Trim() ?? "";

            if (apiException.StatusCode == HttpStatusCode.Unauthorized)
                return "Sua sessão terminou. Entre novamente.";
            if (apiException.StatusCode == HttpStatusCode.Forbidden)
                return "Você não tem permissão para realizar esta ação.";

            if (apiException.StatusCode is HttpStatusCode.NotFound
                or HttpStatusCode.MethodNotAllowed
                or HttpStatusCode.NotImplemented)
                return "Este recurso ainda não está disponível neste servidor.";

            if (apiException.StatusCode == HttpStatusCode.ServiceUnavailable)
            {
                if (LooksLikeSafeOperationalMessage(apiMessage))
                    return Sanitize(apiMessage);
                return "Este recurso está temporariamente indisponível. Tente novamente em instantes.";
            }

            var lower = apiMessage.ToLowerInvariant();
            if (lower.Contains("sem conexão") || lower.Contains("não foi possível conectar") || lower.Contains("connection refused"))
                return "Não foi possível conectar ao EventMenu. Confira a internet e tente novamente.";
            if (lower.Contains("demorou para responder") || lower.Contains("timeout") || lower.Contains("timed out"))
                return "O servidor demorou para responder. Tente novamente.";

            return Sanitize(apiMessage);
        }

        if (exception is HttpRequestException)
            return "Não foi possível conectar ao EventMenu. Confira a internet e tente novamente.";

        if (exception is TaskCanceledException)
            return "O servidor demorou para responder. Tente novamente.";

        if (exception is JsonException)
            return "Não foi possível carregar os dados desta tela. Atualize e tente novamente.";

        if (exception is InvalidOperationException)
            return Sanitize(exception.Message);

        return "Não foi possível concluir esta operação. Tente novamente.";
    }

    private static bool ShouldShowReference(Exception exception)
    {
        if (exception is HttpRequestException or TaskCanceledException) return false;
        if (exception is ApiClientException api && api.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden) return false;
        return true;
    }

    private static bool LooksLikeSafeOperationalMessage(string message)
    {
        if (string.IsNullOrWhiteSpace(message)) return false;
        var lower = message.ToLowerInvariant();
        if (ContainsTechnicalMarker(lower)) return false;
        return lower.Contains("atualiza")
            || lower.Contains("indisponível")
            || lower.Contains("indisponivel")
            || lower.Contains("tente novamente")
            || lower.Contains("conectar")
            || lower.Contains("servidor")
            || lower.Contains("pedido")
            || lower.Contains("pagamento")
            || lower.Contains("entrega");
    }

    private static string Sanitize(string? message)
    {
        if (string.IsNullOrWhiteSpace(message))
            return "Não foi possível concluir esta operação. Tente novamente.";

        var clean = message.Trim();
        var lower = clean.ToLowerInvariant();
        if (ContainsTechnicalMarker(lower))
            return "Não foi possível concluir esta operação. Tente novamente.";

        return clean.Length <= 500 ? clean : clean[..500];
    }

    private static bool ContainsTechnicalMarker(string lower)
    {
        var technicalMarkers = new[]
        {
            "sqlstate", "pdoexception", "stack trace", "system.", "microsoft.", " at ", "innerexception",
            "constraint failed", "database is locked", "sqlite", "mysql", "namespace", "logcat", "debug",
            "http://", "https://", "localhost", "127.0.0.1", "endpoint", "payload", "tenant", "tenant_id",
            "unit_id", "customer_id", "order_id", "payment_id", "provider", "webhook", "bearer", "token",
            "binding", "heartbeat", "polling", "backoff", "idempotency", "nullpointer", "null pointer",
            "undefined", "nan", "sdk", "driver", "http 400", "http 401", "http 403", "http 404",
            "http 422", "http 500"
        };
        return technicalMarkers.Any(lower.Contains);
    }
}
