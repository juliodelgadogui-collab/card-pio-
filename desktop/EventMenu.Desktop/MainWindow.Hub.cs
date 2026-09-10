using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Threading;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private DispatcherTimer? _hubTimer;
    private DesktopIntegrationApiClient? _hubIntegrationApi;
    private LocalHardwareProfileStore? _hubHardwareStore;
    private HubCommandProcessor? _hubProcessor;
    private ProductionPrintProcessor? _productionPrintProcessor;
    private readonly List<IDisposable> _hubProviderDisposables=new();
    private bool _hubBusy;
    private bool _navigationUxReady;
    private DateTimeOffset _lastHubHeartbeat=DateTimeOffset.MinValue;
    private Button? _productionNavButton;
    private Button? _inventoryNavButton;
    private Button? _deliveryMonitorButton;
    private Button? _hubNavButton;
    private Button? _hardwareSettingsButton;

    protected override void OnSourceInitialized(EventArgs e)
    {
        base.OnSourceInitialized(e);
        Loaded+=(_,_)=>
        {
            EnsureHubControls();
            EnsureNativeNavigation();
        };
        Closed+=(_,_)=>DisposeHubRuntime();
        PreviewKeyDown+=MainWindow_HubPreviewKeyDown;
        ShellPanel.IsVisibleChanged+=ShellPanel_BrandVisibilityChanged;
        _hubTimer=new DispatcherTimer{Interval=TimeSpan.FromSeconds(3)};
        _hubTimer.Tick+=HubTimer_Tick;
        _hubTimer.Start();
    }

    private void EnsureHubControls()
    {
        EnsurePosStockUx();
        _=RefreshOperationalPermissionsAsync(true);
        if(_hubNavButton is not null)return;
        if(PosNavButton.Parent is not StackPanel sidebar)return;

        _productionNavButton=new Button
        {
            Content="Produção",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Acompanhar preparo, expedição e impressão dos pedidos",
            Visibility=(Can("orders_kitchen")||Can("orders_dispatch")||Can("production_print")||Can("production_manage"))?Visibility.Visible:Visibility.Collapsed
        };
        _productionNavButton.Click+=async(_,_)=>await OpenProductionAsync();

        _inventoryNavButton=new Button
        {
            Content="Estoque",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Acompanhar saldo e itens que precisam de reposição",
            Visibility=Can("inventory")?Visibility.Visible:Visibility.Collapsed
        };
        _inventoryNavButton.Click+=async(_,_)=>await OpenInventoryMonitorAsync();

        _deliveryMonitorButton=new Button
        {
            Content="Entregas",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Acompanhar entregas que estão em rota",
            Visibility=(Can("delivery_assign")||Can("reports"))?Visibility.Visible:Visibility.Collapsed
        };
        _deliveryMonitorButton.Click+=async(_,_)=>await OpenDeliveryMonitorAsync();

        _hubNavButton=new Button
        {
            Content="Conectar celular",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Vincular um celular autorizado a este computador",
            Visibility=Can("hardware_manage")?Visibility.Visible:Visibility.Collapsed
        };
        _hubNavButton.Click+=async(_,_)=>await OpenHubPairingAsync();

        _hardwareSettingsButton=new Button
        {
            Content="Configurações",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Impressoras, equipamentos e configurações fiscais",
            Visibility=(Can("hardware_manage")||Can("fiscal_manage"))?Visibility.Visible:Visibility.Collapsed
        };
        _hardwareSettingsButton.Click+=async(_,_)=>await OpenHardwareSettingsAsync();

        var cashIndex=sidebar.Children.IndexOf(CashNavButton);
        var insert=Math.Max(0,cashIndex+1);
        sidebar.Children.Insert(insert,_productionNavButton);
        sidebar.Children.Insert(insert+1,_inventoryNavButton);
        sidebar.Children.Insert(insert+2,_deliveryMonitorButton);
        sidebar.Children.Insert(insert+3,_hubNavButton);
        sidebar.Children.Insert(insert+4,_hardwareSettingsButton);
        EnsureNativeNavigation();
    }

    private void EnsureNativeNavigation()
    {
        if(_navigationUxReady||PosNavButton.Parent is not StackPanel sidebar)return;
        _navigationUxReady=true;
        foreach(var button in sidebar.Children.OfType<Button>())
        {
            if(IsPrimaryNavigation(button.Content?.ToString()))
                button.Click+=PrimaryNavigationButton_Click;
        }
        SetActiveNavigationByLabel("Visão geral");
    }

    private static bool IsPrimaryNavigation(string? label)=>label is "Visão geral" or "Nova venda" or "Pedidos" or "Mesas e comandas" or "Caixa";

    private void PrimaryNavigationButton_Click(object sender,RoutedEventArgs e)
    {
        if(sender is Button button)SetActiveNavigation(button);
    }

    private void SetActiveNavigationByLabel(string label)
    {
        if(PosNavButton.Parent is not StackPanel sidebar)return;
        var target=sidebar.Children.OfType<Button>().FirstOrDefault(x=>string.Equals(x.Content?.ToString(),label,StringComparison.Ordinal));
        if(target is not null)SetActiveNavigation(target);
    }

    private void SetActiveNavigation(Button active)
    {
        if(PosNavButton.Parent is not StackPanel sidebar)return;
        foreach(var button in sidebar.Children.OfType<Button>())
        {
            if(IsPrimaryNavigation(button.Content?.ToString()))button.Tag=null;
        }
        active.Tag="active";
    }

    private async void MainWindow_HubPreviewKeyDown(object sender,KeyEventArgs e)
    {
        if(e.Key==Key.H&&Keyboard.Modifiers.HasFlag(ModifierKeys.Control)&&Can("hardware_manage"))
        {
            e.Handled=true;
            await OpenHubPairingAsync();
        }
    }

    private async Task OpenProductionAsync()
    {
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null)return;
        if(!Can("orders_kitchen")&&!Can("orders_dispatch")&&!Can("production_print")&&!Can("production_manage"))return;
        if(!HasShift||!ShiftIs("operation"))
        {
            MessageBox.Show("Inicie um turno de Operação para abrir a produção.","Produção",MessageBoxButton.OK,MessageBoxImage.Information);return;
        }
        try
        {
            EnsureHubRuntime();if(_hubIntegrationApi is null)return;
            var window=new ProductionWindow(_hubIntegrationApi,Can("orders_dispatch"),Can("production_manage")){Owner=this};window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Produção",MessageBoxButton.OK,MessageBoxImage.Warning);}
        await Task.CompletedTask;
    }

    private async Task OpenInventoryMonitorAsync()
    {
        if(_store is null||ShellPanel.Visibility!=Visibility.Visible||!Can("inventory"))return;
        if(!HasShift||!ShiftIs("operation")||ShiftInt("unit_id")<1)
        {
            MessageBox.Show("Inicie um turno de Operação na unidade que deseja consultar.","Estoque",MessageBoxButton.OK,MessageBoxImage.Information);return;
        }
        try
        {
            using var inventoryApi=new InventoryMonitorApiClient(_store);
            var window=new InventoryMonitorWindow(inventoryApi){Owner=this};window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Estoque",MessageBoxButton.OK,MessageBoxImage.Warning);}
        await Task.CompletedTask;
    }

    private async Task OpenDeliveryMonitorAsync()
    {
        if(_store is null||ShellPanel.Visibility!=Visibility.Visible)return;
        if(!Can("delivery_assign")&&!Can("reports"))return;
        if(!HasShift||ShiftInt("unit_id")<1)
        {
            MessageBox.Show("Inicie um turno na unidade que deseja acompanhar.","Entregas",MessageBoxButton.OK,MessageBoxImage.Information);return;
        }
        try
        {
            var window=new DeliveryMonitorWindow(_store){Owner=this};window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Entregas",MessageBoxButton.OK,MessageBoxImage.Warning);}
        await Task.CompletedTask;
    }

    private async Task OpenHubPairingAsync()
    {
        if(!Can("hardware_manage"))return;
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null)return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno para conectar o celular a esta unidade.","Conectar celular",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }
        var unitId=ShiftInt("unit_id");if(unitId<1){MessageBox.Show("Selecione uma unidade antes de conectar o celular.","Conectar celular",MessageBoxButton.OK,MessageBoxImage.Warning);return;}
        try
        {
            EnsureHubRuntime();
            if(_hubIntegrationApi is null||_hubHardwareStore is null)return;
            var profile=_hubHardwareStore.Load();
            await _hubIntegrationApi.HardwareHeartbeatAsync(unitId,_hubHardwareStore,profile);
            _lastHubHeartbeat=DateTimeOffset.UtcNow;
            var pairing=await _hubIntegrationApi.CreateHubPairingAsync(unitId);
            var value=pairing.Pairing??throw new InvalidOperationException("Não foi possível gerar o código de conexão.");
            var window=new HubPairingWindow(value){Owner=this};window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Conectar celular",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async Task OpenHardwareSettingsAsync()
    {
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null||_currentUser is null)return;
        if(!Can("hardware_manage")&&!Can("fiscal_manage"))return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno para escolher a unidade que será configurada.","Configurações",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }
        var unitId=ShiftInt("unit_id");if(unitId<1)return;
        try
        {
            EnsureHubRuntime();
            if(_hubIntegrationApi is null||_hubHardwareStore is null)return;
            var unitName=ShiftValue("unit_name");
            var window=new HardwareFiscalSettingsWindow(_hubIntegrationApi,_hubHardwareStore,_currentUser.TenantId,unitId,unitName,Can("hardware_manage"),Can("fiscal_manage")){Owner=this};
            window.ShowDialog();
            await _hubIntegrationApi.HardwareHeartbeatAsync(unitId,_hubHardwareStore,_hubHardwareStore.Load());
            _lastHubHeartbeat=DateTimeOffset.UtcNow;
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Configurações",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async void HubTimer_Tick(object? sender,EventArgs e)
    {
        if(_hubBusy||ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null||!HasShift)return;
        var unitId=ShiftInt("unit_id");if(unitId<1)return;
        _hubBusy=true;
        try
        {
            await RefreshOperationalPermissionsAsync();
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
            if(ShiftIs("operation")&&(Can("production_print")||Can("orders_kitchen"))&&_productionPrintProcessor is not null)
            {
                for(var i=0;i<2;i++)if(!await _productionPrintProcessor.ProcessOneAsync())break;
            }
        }
        catch(ApiClientException)
        {
            // A operação principal continua mesmo se um recurso secundário ficar temporariamente indisponível.
        }
        catch
        {
            // Falhas transitórias não devem interromper o caixa.
        }
        finally{_hubBusy=false;}
    }

    private void EnsureHubRuntime()
    {
        if(_store is null||_api is null)return;
        if(_hubIntegrationApi is not null)return;
        _hubHardwareStore=new LocalHardwareProfileStore();
        _hubIntegrationApi=new DesktopIntegrationApiClient(_store);

        var providers=new IPaymentTerminalProvider[]
        {
            new LocalTefBridgeProvider("generic_tef"),
            new LocalTefBridgeProvider("pagbank_tef"),
            new LocalTefBridgeProvider("stone_tef"),
            new LocalTefBridgeProvider("sitef")
        };
        _hubProviderDisposables.AddRange(providers.OfType<IDisposable>());
        var terminal=new PaymentTerminalCoordinator(_hubIntegrationApi,providers);
        var rawPrinter=new RawPrinterService();
        _hubProcessor=new HubCommandProcessor(
            _hubIntegrationApi,
            _api,
            _hubHardwareStore,
            rawPrinter,
            new CustomerDisplayStateStore(),
            terminal);
        _productionPrintProcessor=new ProductionPrintProcessor(_hubIntegrationApi,rawPrinter);
    }

    private void DisposeHubRuntime()
    {
        if(_hubTimer is not null){_hubTimer.Stop();_hubTimer.Tick-=HubTimer_Tick;_hubTimer=null;}
        ShellPanel.IsVisibleChanged-=ShellPanel_BrandVisibilityChanged;
        DisposePosStockUx();
        _hubIntegrationApi?.Dispose();_hubIntegrationApi=null;_hubProcessor=null;_hubHardwareStore=null;_productionPrintProcessor=null;
        foreach(var disposable in _hubProviderDisposables)disposable.Dispose();
        _hubProviderDisposables.Clear();
    }
}
