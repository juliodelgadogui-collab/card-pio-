using System.Net;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class ProductionWindow : Window
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly EventMenuApiClient _compatApi;
    private readonly bool _canDispatch;
    private readonly bool _canManage;
    private readonly DispatcherTimer _timer;
    private ProductionBoard _board=new();
    private List<ExpeditionOrder> _expedition=new();
    private bool _loading;
    private bool _compatMode;
    private ComboBox? _expeditionFilter;
    private CheckBox? _expeditionReadyOnly;
    private TextBlock? _expeditionCount;

    public ProductionWindow(DesktopIntegrationApiClient api,bool canDispatch,bool canManage)
    {
        InitializeComponent();_api=api;_compatApi=new EventMenuApiClient(new SecureSessionStore());_canDispatch=canDispatch;_canManage=canManage;
        ExpediteItemButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        ExpediteOrderButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        PrintersTab.Visibility=_canManage?Visibility.Visible:Visibility.Collapsed;
        EnsureExpeditionFilters();
        ExpeditionGrid.LoadingRow+=ExpeditionGrid_LoadingRow;
        _timer=new DispatcherTimer{Interval=TimeSpan.FromSeconds(5)};_timer.Tick+=async(_,_)=>await LoadAsync(false);
        Loaded+=async(_,_)=>{await LoadAsync(true);_timer.Start();};
        Closed+=(_,_)=>{_timer.Stop();ExpeditionGrid.LoadingRow-=ExpeditionGrid_LoadingRow;_compatApi.Dispose();};
    }

    private void EnsureExpeditionFilters()
    {
        if(ExpediteOrderButton.Parent is not StackPanel actions)return;
        _expeditionFilter=new ComboBox{Width=155,Height=34,Margin=new Thickness(12,0,8,0)};
        _expeditionFilter.Items.Add(new ComboBoxItem{Content="Todos",Tag="all"});
        _expeditionFilter.Items.Add(new ComboBoxItem{Content="Somente Delivery",Tag="delivery"});
        _expeditionFilter.Items.Add(new ComboBoxItem{Content="Sem entregador",Tag="unassigned"});
        _expeditionFilter.Items.Add(new ComboBoxItem{Content="Aguardando retirada",Tag="pickup"});
        _expeditionFilter.Items.Add(new ComboBoxItem{Content="Em rota",Tag="route"});
        _expeditionFilter.SelectedIndex=0;
        _expeditionFilter.SelectionChanged+=(_,_)=>ApplyExpedition();
        _expeditionReadyOnly=new CheckBox{Content="Só tudo pronto",VerticalAlignment=VerticalAlignment.Center,Margin=new Thickness(0,0,12,0)};
        _expeditionReadyOnly.Checked+=(_,_)=>ApplyExpedition();
        _expeditionReadyOnly.Unchecked+=(_,_)=>ApplyExpedition();
        _expeditionCount=new TextBlock{VerticalAlignment=VerticalAlignment.Center,Foreground=new SolidColorBrush(Color.FromRgb(102,112,133)),Margin=new Thickness(0,0,12,0)};
        actions.Children.Insert(1,new TextBlock{Text="Exibir",VerticalAlignment=VerticalAlignment.Center,Margin=new Thickness(8,0,0,0),Foreground=new SolidColorBrush(Color.FromRgb(102,112,133))});
        actions.Children.Insert(2,_expeditionFilter);
        actions.Children.Insert(3,_expeditionReadyOnly);
        actions.Children.Insert(4,_expeditionCount);
    }

    private async Task LoadAsync(bool showError)
    {
        if(_loading)return;_loading=true;
        try
        {
            var selectedStation=(StationsList.SelectedItem as ProductionStation)?.Id;
            var selectedOrder=(ExpeditionGrid.SelectedItem as ExpeditionOrder)?.Id;
            try
            {
                var boardResponse=await _api.ProductionBoardAsync();
                var expeditionResponse=await _api.ExpeditionAsync();
                _board=boardResponse.Board;_expedition=expeditionResponse.Orders;_compatMode=false;
            }
            catch(ApiClientException ex) when(IsCompatibilityError(ex))
            {
                await LoadCompatibilityAsync();
                _compatMode=true;
            }

            PrintersTab.Visibility=_canManage&&!_compatMode?Visibility.Visible:Visibility.Collapsed;
            ExpediteItemButton.Visibility=_canDispatch&&!_compatMode?Visibility.Visible:Visibility.Collapsed;
            StationsList.ItemsSource=_board.Stations;PrinterStationCombo.ItemsSource=_board.Stations;
            ApplyExpedition(selectedOrder);
            if(selectedStation.HasValue)StationsList.SelectedItem=_board.Stations.FirstOrDefault(x=>x.Id==selectedStation.Value);
            if(StationsList.SelectedItem is null&&_board.Stations.Count>0)StationsList.SelectedIndex=0;
            ApplyJobs();
            var delayed=_board.Jobs.Count(x=>x.Delayed);var pendingPrints=_board.PrintQueue.Count(x=>!x.Status.Equals("printed",StringComparison.OrdinalIgnoreCase));
            SubtitleText.Text=$"{_board.Jobs.Count} pedido(s) em produção • {delayed} atrasado(s)";
            FooterText.Text=_compatMode?"Produção sincronizada com os pedidos atuais.":pendingPrints>0?$"{pendingPrints} impressão(ões) aguardando.":"Produção atualizada.";
            ConnectionText.Text=$"Atualizado {DateTime.Now:HH:mm:ss}";
        }
        catch(Exception ex)
        {
            ConnectionText.Text="Sem atualização";
            if(showError)MessageBox.Show(Friendly(ex.Message),"Produção",MessageBoxButton.OK,MessageBoxImage.Warning);
        }
        finally{_loading=false;}
    }

    private async Task LoadCompatibilityAsync()
    {
        var response=await _compatApi.OperationalOrdersAsync();
        var active=response.Orders.Where(x=>x.Status is "confirmed" or "preparing").Take(60).ToList();
        var station=new ProductionStation{Id=1,Code="kitchen",Name="Cozinha",StationType="kitchen",SlaMinutes=20,PrinterMode="manual"};
        var jobs=new List<ProductionJob>();
        foreach(var order in active)
        {
            var description=$"Pedido #{order.Id}";decimal quantity=1;
            try
            {
                var details=await _compatApi.OrderDetailsAsync(order.Id);
                if(details.Items.Count>0)
                {
                    description=string.Join(" • ",details.Items.Select(x=>$"{x.Quantity:0.###}x {x.Name}"));
                    quantity=details.Items.Sum(x=>x.Quantity);
                }
            }
            catch{}
            var elapsed=ElapsedMinutes(order.CreatedAt);
            jobs.Add(new ProductionJob{Id=order.Id,OrderId=order.Id,StationId=1,StationName="Cozinha",StationType="kitchen",Kind="order",Description=description,Quantity=quantity,Status=order.Status,ElapsedMinutes=elapsed,Delayed=elapsed>20,Channel=order.Channel,TableName=order.TableName,CustomerName=order.CustomerName});
        }
        _board=new ProductionBoard{Stations=new(){station},Jobs=jobs,Version="compat"};
        _expedition=response.Orders.Where(x=>x.Status is "ready" or "out_for_delivery").Select(order=>
        {
            var delivery=order.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase);
            var route=order.Status.Equals("out_for_delivery",StringComparison.OrdinalIgnoreCase);
            var stage=!delivery?null:route?"Em rota":order.AssignedDeliveryUserId.HasValue?"Aguardando retirada":"Sem entregador";
            return new ExpeditionOrder{Id=order.Id,Channel=order.Channel,Status=order.Status,TableName=order.TableName,CustomerName=order.CustomerName,JobsTotal=1,JobsReady=1,AllReady=true,DeliveryUserId=order.AssignedDeliveryUserId,DeliveryName=order.DeliveryName,DeliveryStage=stage,DeliveryPickedUp=route,DeliveryRouteStarted=route,DeliveryArrived=false};
        }).ToList();
    }

    private static bool IsCompatibilityError(ApiClientException ex)=>ex.StatusCode is HttpStatusCode.NotFound or HttpStatusCode.MethodNotAllowed || ex.Message.Contains("endpoint",StringComparison.OrdinalIgnoreCase);

    private static int ElapsedMinutes(string createdAt)
    {
        if(DateTimeOffset.TryParse(createdAt,out var dto))return Math.Max(0,(int)(DateTimeOffset.Now-dto.ToLocalTime()).TotalMinutes);
        if(DateTime.TryParse(createdAt,out var dt))return Math.Max(0,(int)(DateTime.Now-dt).TotalMinutes);
        return 0;
    }

    private void ApplyJobs()
    {
        IEnumerable<ProductionJob> query=_board.Jobs;
        if(StationsList.SelectedItem is ProductionStation station)query=query.Where(x=>x.StationId==station.Id);
        var search=SearchBox.Text.Trim();if(search.Length>0)query=query.Where(x=>x.OrderId.ToString().Contains(search,StringComparison.OrdinalIgnoreCase)||x.Description.Contains(search,StringComparison.OrdinalIgnoreCase)||(x.TableName??"").Contains(search,StringComparison.OrdinalIgnoreCase)||(x.CustomerName??"").Contains(search,StringComparison.OrdinalIgnoreCase));
        JobsGrid.ItemsSource=query.OrderByDescending(x=>x.Delayed).ThenByDescending(x=>x.ElapsedMinutes).ThenBy(x=>x.Id).ToList();
        if(StationsList.SelectedItem is ProductionStation selected){StationTitle.Text=selected.Name;StationInfo.Text=_compatMode?"Pedidos aguardando preparo.":$"Tempo alvo {selected.SlaMinutes} min • {selected.PrinterLabel}";}else{StationTitle.Text="Produção";StationInfo.Text="Selecione uma estação.";}
    }

    private void ApplyExpedition(int? preserveOrderId=null)
    {
        IEnumerable<ExpeditionOrder> query=_expedition;
        var filter=(_expeditionFilter?.SelectedItem as ComboBoxItem)?.Tag?.ToString()??"all";
        query=filter switch
        {
            "delivery"=>query.Where(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)),
            "unassigned"=>query.Where(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&!x.DeliveryUserId.HasValue),
            "pickup"=>query.Where(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&x.DeliveryUserId.HasValue&&!x.DeliveryPickedUp),
            "route"=>query.Where(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&x.DeliveryRouteStarted&&!x.DeliveryArrived),
            _=>query,
        };
        if(_expeditionReadyOnly?.IsChecked==true)query=query.Where(x=>x.AllReady);
        var rows=query.OrderByDescending(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&!x.DeliveryUserId.HasValue).ThenByDescending(x=>x.AllReady).ThenBy(x=>x.Id).ToList();
        ExpeditionGrid.ItemsSource=rows;if(_expeditionCount is not null)_expeditionCount.Text=$"{rows.Count}/{_expedition.Count}";
        if(preserveOrderId.HasValue)ExpeditionGrid.SelectedItem=rows.FirstOrDefault(x=>x.Id==preserveOrderId.Value);
    }

    private void ExpeditionGrid_LoadingRow(object? sender,DataGridRowEventArgs e)
    {
        if(e.Row.Item is not ExpeditionOrder order)return;
        if(order.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&!order.DeliveryUserId.HasValue){e.Row.Background=new SolidColorBrush(Color.FromRgb(254,243,242));e.Row.ToolTip="Delivery pronto sem entregador definido.";}
        else if(order.AllReady){e.Row.Background=new SolidColorBrush(Color.FromRgb(236,253,243));e.Row.ToolTip="Pedido pronto para a próxima etapa.";}
        else{e.Row.ClearValue(Control.BackgroundProperty);e.Row.ToolTip="Ainda existem itens em produção.";}
    }

    private async Task ChangeSelectedAsync(string target)
    {
        if(JobsGrid.SelectedItem is not ProductionJob job){JobStatusText.Text="Selecione um pedido.";return;}
        try
        {
            if(_compatMode)await _compatApi.ChangeOperationalOrderStatusAsync(job.OrderId,target);else await _api.ChangeProductionJobAsync(job.Id,target);
            JobStatusText.Text=target switch{"preparing"=>"Preparo iniciado.","ready"=>"Pedido marcado como pronto.","expedited"=>"Item liberado.",_=>"Status atualizado."};await LoadAsync(false);
        }
        catch(Exception ex){JobStatusText.Text=Friendly(ex.Message);}
    }

    private async void StartButton_Click(object sender,RoutedEventArgs e)=>await ChangeSelectedAsync("preparing");
    private async void ReadyButton_Click(object sender,RoutedEventArgs e)=>await ChangeSelectedAsync("ready");
    private async void ExpediteItemButton_Click(object sender,RoutedEventArgs e){if(_canDispatch&&!_compatMode)await ChangeSelectedAsync("expedited");}

    private async void ExpediteOrderButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canDispatch||ExpeditionGrid.SelectedItem is not ExpeditionOrder order)return;
        if(!order.AllReady){MessageBox.Show("Ainda existem itens em preparo neste pedido.","Expedição",MessageBoxButton.OK,MessageBoxImage.Information);return;}
        if(_compatMode&&order.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase))
        {
            MessageBox.Show("O pedido está pronto. Agora o entregador deve fazer a retirada para iniciar a entrega.","Delivery",MessageBoxButton.OK,MessageBoxImage.Information);return;
        }
        try
        {
            if(_compatMode)
            {
                var target=order.Channel.Equals("table",StringComparison.OrdinalIgnoreCase)?"served":"completed";
                await _compatApi.ChangeOperationalOrderStatusAsync(order.Id,target);
            }
            else await _api.ExpediteProductionOrderAsync(order.Id);
            await LoadAsync(false);
        }
        catch(Exception ex){MessageBox.Show(Friendly(ex.Message),"Expedição",MessageBoxButton.OK,MessageBoxImage.Warning);}
    }

    private void StationsList_SelectionChanged(object sender,SelectionChangedEventArgs e)=>ApplyJobs();
    private void SearchBox_TextChanged(object sender,TextChangedEventArgs e)=>ApplyJobs();
    private async void RefreshButton_Click(object sender,RoutedEventArgs e)=>await LoadAsync(true);

    private void PrinterStationCombo_SelectionChanged(object sender,SelectionChangedEventArgs e)
    {
        if(PrinterStationCombo.SelectedItem is not ProductionStation station)return;StationPrinterBox.Text=station.PrinterTarget??"";AutomaticPrintCheck.IsChecked=station.PrinterMode.Equals("auto",StringComparison.OrdinalIgnoreCase);BindThisPcCheck.IsChecked=string.IsNullOrWhiteSpace(station.DesktopDeviceId)||station.DesktopDeviceId.Equals(_api.DeviceId,StringComparison.Ordinal);
    }

    private async void SavePrinterButton_Click(object sender,RoutedEventArgs e)
    {
        if(_compatMode||!_canManage||PrinterStationCombo.SelectedItem is not ProductionStation station)return;
        try{await _api.BindStationPrinterAsync(station.Id,StationPrinterBox.Text.Trim(),AutomaticPrintCheck.IsChecked==true,BindThisPcCheck.IsChecked==true);PrinterStatusText.Text="Impressora salva.";await LoadAsync(false);}catch(Exception ex){PrinterStatusText.Text=Friendly(ex.Message);}
    }

    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();
    private static string Friendly(string message)
    {
        var lower=message.ToLowerInvariant();
        if(lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception"))return"Não foi possível atualizar a produção agora.";
        if(lower.Contains("método não permitido")||lower.Contains("endpoint"))return"Esta função ainda não está disponível nesta versão. A tela continuará usando os pedidos atuais.";
        return message;
    }
}
