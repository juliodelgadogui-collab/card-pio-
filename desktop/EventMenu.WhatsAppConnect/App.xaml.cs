using System.Threading;
using System.Windows;

namespace EventMenu.WhatsAppConnect;

public partial class App : System.Windows.Application
{
    private Mutex? _mutex;

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        _mutex=new Mutex(true,"EventMenu.WhatsAppConnect.SingleInstance",out var created);
        if(!created)
        {
            System.Windows.MessageBox.Show("O EventMenu WhatsApp Connect já está em execução. Verifique o ícone próximo ao relógio do Windows.","EventMenu WhatsApp Connect",MessageBoxButton.OK,MessageBoxImage.Information);
            Shutdown();
            return;
        }

        var background=e.Args.Any(x=>string.Equals(x,"--background",StringComparison.OrdinalIgnoreCase));
        var window=new MainWindow(background);
        MainWindow=window;
        window.Show();
    }

    protected override void OnExit(ExitEventArgs e)
    {
        try{_mutex?.ReleaseMutex();}catch{}
        _mutex?.Dispose();
        base.OnExit(e);
    }
}
