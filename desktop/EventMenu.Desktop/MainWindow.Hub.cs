using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Threading;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private DispatcherTimer? _hubTimer;
    private DesktopIntegrationApiClient? _hubIntegrationApi;
    private LocalHardwareProfileStore? _hubHardwareStore;
    private HubCommandProcessor? _hubProcessor;
    private bool _hubBusy;
    private DateTimeOffset _lastHubHeartbeat=DateTimeOffset.MinValue;
    private Button? _hubNavButton;

    protected override void OnSourceInitialized(EventArgs e)
    {
        base.OnSourceInitialized(e);
        Loaded+=(_,_)=>EnsureHubControls();
        Closed+=(_,_)=>DisposeHubRuntime();
        PreviewKeyDown+=MainWindow_HubPreviewKeyDown;
        _hubTimer=new DispatcherTimer{Interval=TimeSpan.FromSeconds(3)};
        _hubTimer.Tick+=HubTimer_Tick;
        _hubTimer.Start();
    }

    private void EnsureHubControls()
    {
        if(_hubNavButton is not null)return;
        if(PosNavButton.Parent is not StackPanel sidebar)return;
        _hubNavButton=new Button
        {
            Content="Celular / Hub",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Vincular celulares e acompanhar equipamentos desta unidade"
        };
        _hubNavButton.Click+=async(_,_)=>await OpenHubPairingAsync();
        var cashIndex=sidebar.Children.IndexOf(CashNavButton);
        sidebar.Children.Insert(Math.Max(0,cashIndex+1),_hubNavButton);
    }

    private async void MainWindow_HubPreviewKeyDown(object sender,KeyEventArgs e)
    {
        if(e.Key==Key.H&&Keyboard.Modifiers.HasFlag(ModifierKeys.Control))
        {
            e.Handled=true;
            await OpenHubPairingAsync();
        }
    }

    private async Task OpenHubPairingAsync()
    {
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null)return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno para vincular o celular à unidade deste computador.","EventMenu Hub",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }
        var unitId=ShiftInt("unit_id");if(unitId<1){MessageBox.Show("O turno não possui uma unidade definida.","EventMenu Hub",MessageBoxButton.OK,MessageBoxImage.Warning);return;}
        try
        {
            EnsureHubRuntime();
            if(_hubIntegrationApi is null||_hubHardwareStore is null)return;
            var profile=_hubHardwareStore.Load();
            await _hubIntegrationApi.HardwareHeartbeatAsync(unitId,_hubHardwareStore,profile);
            _lastHubHeartbeat=DateTimeOffset.UtcNow;
            var pairing=await _hubIntegrationApi.CreateHubPairingAsync(unitId);
            var value=pairing.Pairing??throw new InvalidOperationException("O servidor não gerou o pareamento.");
            var window=new HubPairingWindow(value){Owner=this};window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"EventMenu Hub",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async void HubTimer_Tick(object? sender,EventArgs e)
    {
        if(_hubBusy||ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null||!HasShift)return;
        var unitId=ShiftInt("unit_id");if(unitId<1)return;
        _hubBusy=true;
        try
        {
            EnsureHubRuntime();if(_hubIntegrationApi is null||_hubHardwareStore is null||_hubProcessor is null)return;
            if(DateTimeOffset.UtcNow-_lastHubHeartbeat>TimeSpan.FromSeconds(30))
            {
                await _hubIntegrationApi.HardwareHeartbeatAsync(unitId,_hubHardwareStore,_hubHardwareStore.Load());
                _lastHubHeartbeat=DateTimeOffset.UtcNow;
            }
            var response=await _hubIntegrationApi.PollHubAsync(10);
            foreach(var command in response.Commands)
            {
                if(command.UnitId!=unitId)continue;
                await _hubProcessor.ProcessAsync(command);
            }
        }
        catch(ApiClientException)
        {
            // A sessão principal cuida da renovação/reauth. O Hub não deve interromper a operação do caixa.
        }
        catch
        {
            // Falhas transitórias de hardware/rede serão tentadas no próximo ciclo; comandos reclamados
            // recebem falha pelo processor quando o erro ocorrer durante a execução.
        }
        finally{_hubBusy=false;}
    }

    private void EnsureHubRuntime()
    {
        if(_store is null||_api is null)return;
        if(_hubIntegrationApi is not null)return;
        _hubHardwareStore=new LocalHardwareProfileStore();
        _hubIntegrationApi=new DesktopIntegrationApiClient(_store);
        var terminal=new PaymentTerminalCoordinator(_hubIntegrationApi,Array.Empty<IPaymentTerminalProvider>());
        _hubProcessor=new HubCommandProcessor(
            _hubIntegrationApi,
            _api,
            _hubHardwareStore,
            new RawPrinterService(),
            new CustomerDisplayStateStore(),
            terminal);
    }

    private void DisposeHubRuntime()
    {
        if(_hubTimer is not null){_hubTimer.Stop();_hubTimer.Tick-=HubTimer_Tick;_hubTimer=null;}
        _hubIntegrationApi?.Dispose();_hubIntegrationApi=null;_hubProcessor=null;_hubHardwareStore=null;
    }
}
