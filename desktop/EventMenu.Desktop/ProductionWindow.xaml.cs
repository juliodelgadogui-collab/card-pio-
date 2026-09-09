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
    private readonly bool _canDispatch;
    private readonly bool _canManage;
    private readonly DispatcherTimer _timer;
    private ProductionBoard _board=new();
    private List<ExpeditionOrder> _expedition=new();
    private bool _loading;
    private ComboBox? _expeditionFilter;
    private CheckBox? _expeditionReadyOnly;
    private TextBlock? _expeditionCount;

    public ProductionWindow(DesktopIntegrationApiClient api,bool canDispatch,bool canManage)
    {
        InitializeComponent();_api=api;_canDispatch=canDispatch;_canManage=canManage;
        ExpediteItemButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        ExpediteOrderButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        PrintersTab.Visibility=_canManage?Visibility.Visible:Visibility.Collapsed;
        EnsureExpeditionFilters();
        ExpeditionGrid.LoadingRow+=ExpeditionGrid_LoadingRow;
        _timer=new DispatcherTimer{Interval=TimeSpan.FromSeconds(5)};_timer.Tick+=async(_,_)=>await LoadAsync(false);
        Loaded+=async(_,_)=>{await LoadAsync(true);_timer.Start();};
        Closed+=(_,_)=>{_timer.Stop();ExpeditionGrid.LoadingRow-=ExpeditionGrid_LoadingRow;};
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
            var boardResponse=await _api.ProductionBoardAsync();_board=boardResponse.Board;
            var expeditionResponse=await _api.ExpeditionAsync();_expedition=expeditionResponse.Orders;
            StationsList.ItemsSource=_board.Stations;PrinterStationCombo.ItemsSource=_board.Stations;
            ApplyExpedition(selectedOrder);
            if(selectedStation.HasValue)StationsList.SelectedItem=_board.Stations.FirstOrDefault(x=>x.Id==selectedStation.Value);
            if(StationsList.SelectedItem is null&&_board.Stations.Count>0)StationsList.SelectedIndex=0;
            ApplyJobs();
            var delayed=_board.Jobs.Count(x=>x.Delayed);var pendingPrints=_board.PrintQueue.Count(x=>!x.Status.Equals("printed",StringComparison.OrdinalIgnoreCase));
            SubtitleText.Text=$"{_board.Unit?.Name??"Unidade"} • {_board.Jobs.Count} item(ns) ativos • {delayed} atrasado(s)";
            FooterText.Text=pendingPrints>0?$"{pendingPrints} impressão(ões) aguardando o Desktop.":"Fila de impressão normal.";
            ConnectionText.Text=$"Atualizado {DateTime.Now:HH:mm:ss}";
        }
        catch(Exception ex)
        {
            ConnectionText.Text="Reconectando...";if(showError)MessageBox.Show(Friendly(ex.Message),"Produção",MessageBoxButton.OK,MessageBoxImage.Warning);
        }
        finally{_loading=false;}
    }

    private void ApplyJobs()
    {
        IEnumerable<ProductionJob> query=_board.Jobs;
        if(StationsList.SelectedItem is ProductionStation station)query=query.Where(x=>x.StationId==station.Id);
        var search=SearchBox.Text.Trim();if(search.Length>0)query=query.Where(x=>x.OrderId.ToString().Contains(search,StringComparison.OrdinalIgnoreCase)||x.Description.Contains(search,StringComparison.OrdinalIgnoreCase)||(x.TableName??"").Contains(search,StringComparison.OrdinalIgnoreCase)||(x.CustomerName??"").Contains(search,StringComparison.OrdinalIgnoreCase));
        JobsGrid.ItemsSource=query.OrderByDescending(x=>x.Delayed).ThenByDescending(x=>x.ElapsedMinutes).ThenBy(x=>x.Id).ToList();
        if(StationsList.SelectedItem is ProductionStation selected){StationTitle.Text=selected.Name;StationInfo.Text=$"SLA {selected.SlaMinutes} min • {selected.PrinterLabel}";}else{StationTitle.Text="Produção";StationInfo.Text="Selecione uma estação.";}
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
        var rows=query
            .OrderByDescending(x=>x.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&!x.DeliveryUserId.HasValue)
            .ThenByDescending(x=>x.AllReady)
            .ThenBy(x=>x.Id)
            .ToList();
        ExpeditionGrid.ItemsSource=rows;
        _expeditionCount!.Text=$"{rows.Count}/{_expedition.Count}";
        if(preserveOrderId.HasValue)ExpeditionGrid.SelectedItem=rows.FirstOrDefault(x=>x.Id==preserveOrderId.Value);
    }

    private void ExpeditionGrid_LoadingRow(object? sender,DataGridRowEventArgs e)
    {
        if(e.Row.Item is not ExpeditionOrder order)return;
        if(order.Channel.Equals("delivery",StringComparison.OrdinalIgnoreCase)&&!order.DeliveryUserId.HasValue)
        {
            e.Row.Background=new SolidColorBrush(Color.FromRgb(254,243,242));
            e.Row.ToolTip="Delivery pronto sem entregador definido.";
        }
        else if(order.AllReady)
        {
            e.Row.Background=new SolidColorBrush(Color.FromRgb(236,253,243));
            e.Row.ToolTip="Todos os itens estão prontos para expedição.";
        }
        else
        {
            e.Row.ClearValue(Control.BackgroundProperty);
            e.Row.ToolTip="Ainda existem itens em produção.";
        }
    }

    private async Task ChangeSelectedAsync(string target)
    {
        if(JobsGrid.SelectedItem is not ProductionJob job){JobStatusText.Text="Selecione um item.";return;}
        try{await _api.ChangeProductionJobAsync(job.Id,target);JobStatusText.Text=target switch{"preparing"=>"Preparo iniciado.","ready"=>"Item pronto.","expedited"=>"Item liberado pela expedição.",_=>"Status atualizado."};await LoadAsync(false);}catch(Exception ex){JobStatusText.Text=Friendly(ex.Message);}
    }

    private async void StartButton_Click(object sender,RoutedEventArgs e)=>await ChangeSelectedAsync("preparing");
    private async void ReadyButton_Click(object sender,RoutedEventArgs e)=>await ChangeSelectedAsync("ready");
    private async void ExpediteItemButton_Click(object sender,RoutedEventArgs e){if(_canDispatch)await ChangeSelectedAsync("expedited");}

    private async void ExpediteOrderButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canDispatch||ExpeditionGrid.SelectedItem is not ExpeditionOrder order)return;if(!order.AllReady){MessageBox.Show("Ainda existem itens em preparo neste pedido.","Expedição",MessageBoxButton.OK,MessageBoxImage.Information);return;}
        try{await _api.ExpediteProductionOrderAsync(order.Id);await LoadAsync(false);}catch(Exception ex){MessageBox.Show(Friendly(ex.Message),"Expedição",MessageBoxButton.OK,MessageBoxImage.Warning);}
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
        if(!_canManage||PrinterStationCombo.SelectedItem is not ProductionStation station)return;
        try{await _api.BindStationPrinterAsync(station.Id,StationPrinterBox.Text.Trim(),AutomaticPrintCheck.IsChecked==true,BindThisPcCheck.IsChecked==true);PrinterStatusText.Text="Impressora da estação salva.";await LoadAsync(false);}catch(Exception ex){PrinterStatusText.Text=Friendly(ex.Message);}
    }

    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();
    private static string Friendly(string message){var lower=message.ToLowerInvariant();return lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception")?"Não foi possível concluir a operação da produção.":message;}
}
