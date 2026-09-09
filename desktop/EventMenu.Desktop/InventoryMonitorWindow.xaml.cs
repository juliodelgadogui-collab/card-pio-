using System.Windows;
using System.Windows.Threading;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class InventoryMonitorWindow : Window
{
    private readonly InventoryMonitorApiClient _api;
    private readonly DispatcherTimer _timer;
    private bool _busy;

    public InventoryMonitorWindow(InventoryMonitorApiClient api)
    {
        InitializeComponent();
        _api = api;
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(30) };
        _timer.Tick += async (_, _) => await RefreshAsync(false);
        Loaded += async (_, _) => { await RefreshAsync(true); _timer.Start(); };
        Closed += (_, _) => _timer.Stop();
    }

    private async Task RefreshAsync(bool showError)
    {
        if (_busy) return;
        _busy = true;
        try
        {
            StatusText.Text = "Atualizando estoque...";
            var data = await _api.SnapshotAsync(LowOnlyBox.IsChecked == true);
            UnitText.Text = string.IsNullOrWhiteSpace(data.Unit.Name) ? "Unidade atual" : data.Unit.Name;
            ControlledText.Text = data.Summary.Controlled.ToString();
            LowText.Text = data.Summary.Low.ToString();
            ZeroText.Text = data.Summary.Zero.ToString();
            ReservedText.Text = data.Summary.ReservedQty.ToString("0.###");
            StockGrid.ItemsSource = data.Products;
            StatusText.Text = data.Summary.Low > 0
                ? $"Atenção: {data.Summary.Low} item(ns) exigem reposição. Atualizado às {DateTime.Now:HH:mm}."
                : $"Estoque dentro do mínimo configurado. Atualizado às {DateTime.Now:HH:mm}.";
        }
        catch (Exception ex)
        {
            StatusText.Text = "Não foi possível atualizar o estoque.";
            if (showError) MessageBox.Show(ex.Message, "Estoque", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally { _busy = false; }
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await RefreshAsync(true);
    private async void LowOnlyBox_Changed(object sender, RoutedEventArgs e)
    {
        if (!IsLoaded) return;
        await RefreshAsync(false);
    }
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
