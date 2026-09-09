using System.Diagnostics;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class DeliveryMonitorWindow : Window
{
    private readonly DeliveryMonitorApiClient _api;
    private readonly DispatcherTimer _timer;
    private bool _loading;

    public DeliveryMonitorWindow(SecureSessionStore store)
    {
        InitializeComponent();
        _api = new DeliveryMonitorApiClient(store);
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(8) };
        _timer.Tick += async (_, _) => await LoadAsync(false);
        Loaded += async (_, _) => { _timer.Start(); await LoadAsync(true); };
        Closed += (_, _) => { _timer.Stop(); _api.Dispose(); };
    }

    private async Task LoadAsync(bool showError)
    {
        if (_loading) return;
        _loading = true;
        try
        {
            var selectedId = (DeliveriesGrid.SelectedItem as DeliveryLiveLocation)?.DeliveryUserId;
            var response = await _api.LiveAsync();
            DeliveriesGrid.ItemsSource = response.Locations;
            if (selectedId.HasValue) DeliveriesGrid.SelectedItem = response.Locations.FirstOrDefault(x => x.DeliveryUserId == selectedId.Value);
            if (DeliveriesGrid.SelectedItem is null && response.Locations.Count > 0) DeliveriesGrid.SelectedIndex = 0;
            var fresh = response.Locations.Count(x => x.Fresh);
            SummaryText.Text = response.Locations.Count == 0
                ? "Nenhuma rota com GPS ativo agora."
                : $"{response.Locations.Count} rota(s) • {fresh} com sinal recente";
            UpdateSelected();
        }
        catch (Exception ex)
        {
            SummaryText.Text = "Não foi possível atualizar o rastreamento.";
            if (showError) MessageBox.Show(Friendly(ex.Message), "Entregas ao vivo", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally { _loading = false; }
    }

    private void DeliveriesGrid_SelectionChanged(object sender, SelectionChangedEventArgs e) => UpdateSelected();

    private void UpdateSelected()
    {
        if (DeliveriesGrid.SelectedItem is not DeliveryLiveLocation row)
        {
            MapButton.IsEnabled = false;
            CopyButton.IsEnabled = false;
            DetailText.Text = "Selecione uma entrega para ver a posição.";
            return;
        }
        MapButton.IsEnabled = true;
        CopyButton.IsEnabled = true;
        DetailText.Text = $"{row.DeliveryName} • Pedido #{row.OrderId} • {row.Coordinates} • {row.OnlineLabel}";
    }

    private void MapButton_Click(object sender, RoutedEventArgs e)
    {
        if (DeliveriesGrid.SelectedItem is not DeliveryLiveLocation row) return;
        var url = $"https://www.google.com/maps/search/?api=1&query={Uri.EscapeDataString(row.Latitude.ToString(System.Globalization.CultureInfo.InvariantCulture) + "," + row.Longitude.ToString(System.Globalization.CultureInfo.InvariantCulture))}";
        try { Process.Start(new ProcessStartInfo(url) { UseShellExecute = true }); }
        catch { MessageBox.Show("Não foi possível abrir o mapa padrão do Windows.", "Entregas ao vivo", MessageBoxButton.OK, MessageBoxImage.Information); }
    }

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (DeliveriesGrid.SelectedItem is DeliveryLiveLocation row) Clipboard.SetText(row.Coordinates);
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync(true);
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível consultar o rastreamento das entregas."
            : message;
    }
}
