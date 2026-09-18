using System.Net;
using System.Text.Json;
using System.Windows;

namespace EventMenu.Desktop.Services;

public static class DesktopErrorPresenter
{
    public static void Show(Exception exception, string title, Window? owner = null)
    {
        var message = FriendlyMessage(exception);
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

            if (apiException.StatusCode is HttpStatusCode.NotFound
                or HttpStatusCode.MethodNotAllowed
                or HttpStatusCode.NotImplemented)
                return "Este recurso ainda não está disponível neste servidor.";

            if (apiException.StatusCode == HttpStatusCode.ServiceUnavailable)
            {
                // Preserve mensagens operacionais seguras enviadas pelo backend, como
                // a orientação para atualizar o módulo Hub, sem expor detalhes técnicos.
                if (LooksLikeSafeOperationalMessage(apiMessage))
                    return Sanitize(apiMessage);
                return "Este recurso está temporariamente indisponível. Tente novamente em instantes.";
            }

            var lower = apiMessage.ToLowerInvariant();
            if (lower.Contains("sem conexão") || lower.Contains("não foi possível conectar"))
                return "Não foi possível conectar ao EventMenu. Confira a internet e tente novamente.";
            if (lower.Contains("demorou para responder") || lower.Contains("timeout"))
                return "A conexão demorou para responder. Tente novamente.";

            return Sanitize(apiMessage);
        }

        if (exception is HttpRequestException)
            return "Não foi possível conectar ao EventMenu. Confira a internet e tente novamente.";

        if (exception is TaskCanceledException)
            return "A conexão demorou para responder. Tente novamente.";

        if (exception is JsonException)
            return "Não foi possível carregar os dados desta tela. Atualize e tente novamente.";

        if (exception is InvalidOperationException)
            return Sanitize(exception.Message);

        return "Não foi possível concluir esta operação. Tente novamente.";
    }

    private static bool LooksLikeSafeOperationalMessage(string message)
    {
        if (string.IsNullOrWhiteSpace(message)) return false;
        var lower = message.ToLowerInvariant();
        return lower.Contains("atualiza")
            || lower.Contains("indisponível")
            || lower.Contains("indisponivel")
            || lower.Contains("tente novamente")
            || lower.Contains("conectar")
            || lower.Contains("servidor");
    }

    private static string Sanitize(string? message)
    {
        if (string.IsNullOrWhiteSpace(message))
            return "Não foi possível concluir esta operação. Tente novamente.";

        var lower = message.ToLowerInvariant();
        var technicalMarkers = new[]
        {
            "sqlstate",
            "pdoexception",
            "stack trace",
            "system.",
            "microsoft.",
            " at ",
            "innerexception",
            "constraint failed",
            "database is locked",
            "http://",
            "https://",
            "localhost",
            "127.0.0.1"
        };

        if (technicalMarkers.Any(lower.Contains))
            return "Não foi possível concluir esta operação. Tente novamente.";

        return message.Trim();
    }
}
