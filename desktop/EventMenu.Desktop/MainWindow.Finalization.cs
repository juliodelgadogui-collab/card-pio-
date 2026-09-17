using System.Windows;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    protected override void OnClosed(EventArgs e)
    {
        // Finalização determinística: timers não devem manter trabalho pendente
        // e o HttpClient/sincronização da sessão devem ser liberados ao fechar.
        _refreshTimer.Stop();
        _refreshTimer.Tick -= AutoRefreshTimer_Tick;

        if (_hubTimer is not null)
            _hubTimer.Stop();

        try
        {
            _api?.Dispose();
        }
        catch
        {
            // Fechamento nunca deve ser bloqueado por falha de recurso externo.
        }
        finally
        {
            _api = null;
            _store = null;
        }

        base.OnClosed(e);
    }
}
