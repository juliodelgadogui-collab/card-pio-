using System.Windows;
using System.Windows.Threading;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        DispatcherUnhandledException += App_DispatcherUnhandledException;
        base.OnStartup(e);
    }

    protected override void OnExit(ExitEventArgs e)
    {
        DispatcherUnhandledException -= App_DispatcherUnhandledException;
        base.OnExit(e);
    }

    private static void App_DispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        // Uma falha isolada de tela/periférico não deve encerrar o PDV inteiro.
        // A mensagem exibida é sanitizada para não mostrar SQL, stack trace ou URLs internas.
        DesktopErrorPresenter.Show(e.Exception, "EventMenu Desktop", Current?.MainWindow);
        e.Handled = true;
    }
}
