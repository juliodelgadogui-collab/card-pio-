using System.Windows;
using System.Windows.Controls;
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

    public ProductionWindow(DesktopIntegrationApiClient api,bool canDispatch,bool canManage)
    {
        InitializeComponent();_api=api;_canDispatch=canDispatch;_canManage=canManage;
        ExpediteItemButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        ExpediteOrderButton.Visibility=_canDispatch?Visibility.Visible:Visibility.Collapsed;
        PrintersTab.Visibility=_canManage?Visibility.Visible:Visibility.Collapsed;
        _timer=new DispatcherTimer{Interval=TimeSpan.FromSeconds(5)};_timer.Tick+=async(_,_)=>await LoadAsync(false);
        Loaded+=async(_,_)=>{await LoadAsync(true);_timer.Start();};
        Closed+=(_,_)=>_timer.Stop();
    }

    private async Task LoadAsync(bool showError)
    {
        if(_loading)return;_loading=true;
        try
        {
            var selectedStation=(StationsList.SelectedItem as ProductionStation)?.Id;
            var boardResponse=await _api.ProductionBoardAsync();_board=boardResponse.Board;
            var expeditionResponse=await _api.ExpeditionAsync();_expedition=expeditionResponse.Orders;
            StationsList.ItemsSource=_board.Stations;PrinterStationCombo.ItemsSource=_board.Stations;ExpeditionGrid.ItemsSource=_expedition;
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
