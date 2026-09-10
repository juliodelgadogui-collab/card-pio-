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
    private Button? _approvalsNavButton;
    private Button? _notificationsNavButton;
    private Button? _qrNavButton;
    private Button? _fiscalNavButton;
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
            ToolTip="Acompanhar preparo, expedição e impressão dos pedidos"
        };
        _productionNavButton.Click+=async(_,_)=>await OpenProductionAsync();

        _inventoryNavButton=new Button
        {
            Content="Estoque",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Acompanhar saldo e itens que precisam de reposição"
        };
        _inventoryNavButton.Click+=async(_,_)=>await OpenInventoryMonitorAsync();

        _deliveryMonitorButton=new Button
        {
            Content="Entregas",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Acompanhar entregas que estão em rota"
        };
        _deliveryMonitorButton.Click+=async(_,_)=>await OpenDeliveryMonitorAsync();

        _approvalsNavButton=new Button
        {
            Content="Aprovações",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Decidir solicitações de desconto e cancelamento"
        };
        _approvalsNavButton.Click+=async(_,_)=>await OpenApprovalsAsync();

        _notificationsNavButton=new Button
        {
            Content="Notificações",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Avisos e pendências da operação"
        };
        _notificationsNavButton.Click+=async(_,_)=>await OpenNotificationsAsync();

        _qrNavButton=new Button
        {
            Content="Ler QR / código",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Ler pedidos, mesas, comandas, ingressos, convidados, funcionários, eventos e repasses"
        };
        _qrNavButton.Click+=async(_,_)=>await OpenQrOperationsAsync();

        _fiscalNavButton=new Button
        {
            Content="Nota fiscal",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Configurar emissão e acompanhar documentos fiscais"
        };
        _fiscalNavButton.Click+=async(_,_)=>await OpenFiscalAreaAsync();

        _hubNavButton=new Button
        {
            Content="Conectar celular",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Vincular um celular autorizado a este computador"
        };
        _hubNavButton.Click+=async(_,_)=>await OpenHubPairingAsync();

        _hardwareSettingsButton=new Button
        {
            Content="Configurações",
            HorizontalContentAlignment=HorizontalAlignment.Left,
            ToolTip="Impressoras, maquininha e equipamentos deste computador"
        };
        _hardwareSettingsButton.Click+=async(_,_)=>await OpenHardwareSettingsAsync();

        var cashIndex=sidebar.Children.IndexOf(CashNavButton);
        var insert=Math.Max(0,cashIndex+1);
        sidebar.Children.Insert(insert,_productionNavButton);
        sidebar.Children.Insert(insert+1,_inventoryNavButton);
        sidebar.Children.Insert(insert+2,_deliveryMonitorButton);
        sidebar.Children.Insert(insert+3,_approvalsNavButton);
        sidebar.Children.Insert(insert+4,_notificationsNavButton);
        sidebar.Children.Insert(insert+5,_qrNavButton);
        sidebar.Children.Insert(insert+6,_fiscalNavButton);
        sidebar.Children.Insert(insert+7,_hubNavButton);
        sidebar.Children.Insert(insert+8,_hardwareSettingsButton);

        ApplySecondaryNavigationVisibility();
        EnsureNativeNavigation();
        _=RefreshSecondaryNavigationPermissionsAsync();
    }

    private async Task RefreshSecondaryNavigationPermissionsAsync()
    {
        if(_api is null||ShellPanel.Visibility!=Visibility.Visible)return;
        try
        {
            var context=await _api.GoContextAsync();
            foreach(var permission in context.Permissions)_permissions[permission.Key]=permission.Value;
            ApplySecondaryNavigationVisibility();
        }
        catch
        {
            ApplySecondaryNavigationVisibility();
        }
    }

    private void ApplySecondaryNavigationVisibility()
    {
        if(_productionNavButton is not null)
            _productionNavButton.Visibility=(Can("orders_kitchen")||Can("orders_dispatch")||Can("production_print")||Can("production_manage"))?Visibility.Visible:Visibility.Collapsed;
        if(_inventoryNavButton is not null)
            _inventoryNavButton.Visibility=Can("inventory")?Visibility.Visible:Visibility.Collapsed;
        if(_deliveryMonitorButton is not null)
            _deliveryMonitorButton.Visibility=(Can("delivery_assign")||Can("reports"))?Visibility.Visible:Visibility.Collapsed;
        if(_approvalsNavButton is not null)
            _approvalsNavButton.Visibility=(Can("discount_approve")||Can("cancellation_approve"))?Visibility.Visible:Visibility.Collapsed;
        if(_notificationsNavButton is not null)
            _notificationsNavButton.Visibility=ShellPanel.Visibility==Visibility.Visible?Visibility.Visible:Visibility.Collapsed;
        if(_qrNavButton is not null)
            _qrNavButton.Visibility=ShellPanel.Visibility==Visibility.Visible?Visibility.Visible:Visibility.Collapsed;
        if(_fiscalNavButton is not null)
            _fiscalNavButton.Visibility=(Can("fiscal_manage")||Can("fiscal_issue"))?Visibility.Visible:Visibility.Collapsed;
        if(_hubNavButton is not null)
            _hubNavButton.Visibility=Can("hardware_manage")?Visibility.Visible:Visibility.Collapsed;
        if(_hardwareSettingsButton is not null)
            _hardwareSettingsButton.Visibility=Can("hardware_manage")?Visibility.Visible:Visibility.Collapsed;
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
        else if(e.Key==Key.Q&&Keyboard.Modifiers.HasFlag(ModifierKeys.Control))
        {
            e.Handled=true;
            await OpenQrOperationsAsync();
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

    private async Task OpenApprovalsAsync()
    {
        if(_store is null||ShellPanel.Visibility!=Visibility.Visible)return;
        var canDiscount=Can("discount_approve");
        var canCancellation=Can("cancellation_approve");
        if(!canDiscount&&!canCancellation)return;
        if(!HasShift||(!ShiftIs("operation")&&!ShiftIs("pay")))
        {
            MessageBox.Show("Use um turno de Operação ou Pay para decidir solicitações.","Aprovações",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }

        try
        {
            var window=new ApprovalCenterWindow(_store,canDiscount,canCancellation){Owner=this};
            window.ShowDialog();
            if(window.ApprovalChanged)await RefreshAfterSensitiveOrderChangeAsync();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Aprovações",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async Task OpenNotificationsAsync()
    {
        if(_store is null||ShellPanel.Visibility!=Visibility.Visible)return;
        try
        {
            var window=new NotificationCenterWindow(
                _store,
                Can("delivery_assign"),
                Can("discount_request"),
                Can("cancellation_request"),
                Can("discount_approve"),
                Can("cancellation_approve")){Owner=this};
            window.ShowDialog();
            if(window.OperationChanged)await RefreshAfterSensitiveOrderChangeAsync();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Notificações",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async Task RefreshAfterSensitiveOrderChangeAsync()
    {
        await TryLoadOrdersAsync(false);
        if(Can("orders_create"))await TryLoadProductsAsync(false);
        if(Can("tables")&&ShiftIs("operation"))await TryLoadTablesAsync(false);
        RefreshDashboardUx();
    }

    private async Task OpenQrOperationsAsync()
    {
        if(_store is null||ShellPanel.Visibility!=Visibility.Visible)return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno antes de usar a leitura de códigos.","Ler QR / código",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }

        var canTables=Can("tables")||Can("orders_create");
        var canTickets=Can("tickets");
        var canGuests=Can("guests");
        var canOrders=Can("orders_view")||Can("orders_manage")||Can("orders_dispatch")||Can("delivery_assign")||Can("orders_delivery");
        var canCash=Can("cash");
        try
        {
            var window=new QrOperationsWindow(
                _store,
                canTables,
                canTickets,
                canGuests,
                canOrders,
                Can("delivery_assign"),
                Can("discount_request"),
                Can("cancellation_request"),
                Can("orders_dispatch")||Can("orders_manage"),
                Can("loyalty_redeem"),
                canCash){Owner=this};
            window.ShowDialog();
            if(window.OperationChanged)
            {
                if(canOrders)await TryLoadOrdersAsync(false);
                if(canTables&&ShiftIs("operation"))await TryLoadTablesAsync(false);
                if(canCash)await TryLoadCashAsync(false);
            }
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Ler QR / código",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async Task OpenFiscalAreaAsync()
    {
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null||_currentUser is null)return;
        if(!Can("fiscal_manage")&&!Can("fiscal_issue"))return;
        if(!HasShift||ShiftInt("unit_id")<1)
        {
            MessageBox.Show("Inicie um turno na unidade que deseja usar.","Nota fiscal",MessageBoxButton.OK,MessageBoxImage.Information);
            return;
        }

        try
        {
            EnsureHubRuntime();
            if(_hubIntegrationApi is null)return;
            var unitId=ShiftInt("unit_id");
            var unitName=ShiftValue("unit_name");
            var store=_hubHardwareStore??new LocalHardwareProfileStore();
            var window=new FiscalCenterWindow(
                _hubIntegrationApi,
                store,
                _currentUser.TenantId,
                unitId,
                unitName,
                Can("fiscal_manage"),
                Can("fiscal_issue")){Owner=this};
            window.ShowDialog();
        }
        catch(Exception ex){MessageBox.Show(ex.Message,"Nota fiscal",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private async Task OpenHubPairingAsync()
    {
        if(!Can("hardware_manage"))return;
        if(ShellPanel.Visibility!=Visibility.Visible||_store is null||_api is null)return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno para conectar o celular a esta unidade.","Conectar celular",MessageBoxButton.OK,MessageBoxImage.Information);return;
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
        if(!Can("hardware_manage"))return;
        if(!HasShift)
        {
            MessageBox.Show("Inicie um turno para escolher a unidade que será configurada.","Configurações",MessageBoxButton.OK,MessageBoxImage.Information);return;
        }
        var unitId=ShiftInt("unit_id");if(unitId<1)return;
        try
        {
            EnsureHubRuntime();
            if(_hubIntegrationApi is null||_hubHardwareStore is null)return;
            var unitName=ShiftValue("unit_name");
            var window=new HardwareFiscalSettingsWindow(_hubIntegrationApi,_hubHardwareStore,_currentUser.TenantId,unitId,unitName,true,false){Owner=this};
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
            ApplySecondaryNavigationVisibility();
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
