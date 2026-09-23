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
            var fresh = response.Locations.Count(x => x.IsLive);
            SummaryText.Text = response.Locations.Count == 0
                ? "Nenhuma entrega em rota agora."
                : $"{response.Locations.Count} entrega(s) em rota • {fresh} ao vivo";
            UpdateSelected();
        }
        catch (Exception ex)
        {
            SummaryText.Text = "Não foi possível atualizar as entregas agora.";
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
            DetailText.Text = "Selecione uma entrega para acompanhar.";
            return;
        }
        MapButton.IsEnabled = row.HasPosition;
        CopyButton.IsEnabled = row.HasPosition;
        DetailText.Text = $"{row.DeliveryName} • Pedido #{row.OrderId} • {row.OnlineLabel}" + (row.HasPosition ? $" • {row.Coordinates}" : "");
    }

    private void MapButton_Click(object sender, RoutedEventArgs e)
    {
        if (DeliveriesGrid.SelectedItem is not DeliveryLiveLocation row || !row.HasPosition) return;
        var coordinates = row.Latitude!.Value.ToString(System.Globalization.CultureInfo.InvariantCulture) + "," + row.Longitude!.Value.ToString(System.Globalization.CultureInfo.InvariantCulture);
        var url = $"https://www.google.com/maps/search/?api=1&query={Uri.EscapeDataString(coordinates)}";
        try { Process.Start(new ProcessStartInfo(url) { UseShellExecute = true }); }
        catch { MessageBox.Show("Não foi possível abrir o mapa.", "Entregas ao vivo", MessageBoxButton.OK, MessageBoxImage.Information); }
    }

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (DeliveriesGrid.SelectedItem is DeliveryLiveLocation row && row.HasPosition) Clipboard.SetText(row.Coordinates);
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadAsync(true);
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        if (lower.Contains("método não permitido") || lower.Contains("endpoint")) return "O acompanhamento ao vivo ainda não está disponível nesta instalação.";
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível consultar as entregas agora."
            : message;
    }
}
