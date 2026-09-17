using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private static readonly TimeSpan HubNormalInterval = TimeSpan.FromSeconds(8);
    private static readonly TimeSpan HubMaximumBackoff = TimeSpan.FromSeconds(60);

    private int _hubFailureCount;
    private string _lastNavigationSignature = "";
    private Button? _dashboardNavButton;
    private Button? _ordersNavButton;
    private bool _hardeningRuntimeReady;

    protected override void OnInitialized(EventArgs e)
    {
        base.OnInitialized(e);
        Loaded += MainWindow_HardeningLoaded;
        ContentRendered += MainWindow_HardeningContentRendered;
        Closed += MainWindow_HardeningClosed;
    }

    private void MainWindow_HardeningLoaded(object sender, RoutedEventArgs e)
    {
        if (_hardeningRuntimeReady) return;
        _hardeningRuntimeReady = true;

        // O Hub é importante, mas não pode martelar o servidor a cada poucos segundos
        // quando um endpoint opcional ainda não estiver implantado.
        if (_hubTimer is not null)
        {
            _hubTimer.Tick -= HubTimer_Tick;
            _hubTimer.Tick -= HardenedHubTimer_Tick;
            _hubTimer.Interval = HubNormalInterval;
            _hubTimer.Tick += HardenedHubTimer_Tick;
        }

        _refreshTimer.Tick -= HardeningRefreshTimer_Tick;
        _refreshTimer.Tick += HardeningRefreshTimer_Tick;
    }

    private void MainWindow_HardeningContentRendered(object? sender, EventArgs e)
    {
        // AndroidParity cria parte das opções no OnContentRendered. Executar no idle
        // garante que todos os módulos já existam antes de reagrupar a navegação.
        Dispatcher.BeginInvoke(DispatcherPriority.ContextIdle, new Action(() =>
        {
            ApplySecondaryNavigationVisibility();
            ApplyAndroidParityVisibility();
            RebuildProfessionalNavigation(true);
        }));
    }

    private void HardeningRefreshTimer_Tick(object? sender, EventArgs e)
    {
        RebuildProfessionalNavigation();
    }

    private async void HardenedHubTimer_Tick(object? sender, EventArgs e)
    {
        if (_hubBusy || ShellPanel.Visibility != Visibility.Visible || _store is null || _api is null || !HasShift)
            return;

        var unitId = ShiftInt("unit_id");
        if (unitId < 1) return;

        _hubBusy = true;
        try
        {
            // RefreshOperationalPermissionsAsync já limita a consulta real a 45 s.
            await RefreshOperationalPermissionsAsync();
            ApplySecondaryNavigationVisibility();
            ApplyAndroidParityVisibility();
            RebuildProfessionalNavigation();

            EnsureHubRuntime();
            if (_hubIntegrationApi is null || _hubHardwareStore is null || _hubProcessor is null)
                return;

            if (DateTimeOffset.UtcNow - _lastHubHeartbeat > TimeSpan.FromSeconds(30))
            {
                await _hubIntegrationApi.HardwareHeartbeatAsync(unitId, _hubHardwareStore, _hubHardwareStore.Load());
                _lastHubHeartbeat = DateTimeOffset.UtcNow;
            }

            var response = await _hubIntegrationApi.PollHubAsync(10);
            foreach (var command in response.Commands)
            {
                if (command.UnitId != unitId) continue;
                await _hubProcessor.ProcessAsync(command);
            }

            if (ShiftIs("operation") &&
                (Can("production_print") || Can("orders_kitchen")) &&
                _productionPrintProcessor is not null)
            {
                for (var i = 0; i < 2; i++)
                    if (!await _productionPrintProcessor.ProcessOneAsync()) break;
            }

            ResetHubBackoff();
        }
        catch (ApiClientException)
        {
            IncreaseHubBackoff();
        }
        catch
        {
            IncreaseHubBackoff();
        }
        finally
        {
            _hubBusy = false;
        }
    }

    private void ResetHubBackoff()
    {
        if (_hubFailureCount == 0 && _hubTimer?.Interval == HubNormalInterval) return;
        _hubFailureCount = 0;
        if (_hubTimer is not null) _hubTimer.Interval = HubNormalInterval;
    }

    private void IncreaseHubBackoff()
    {
        _hubFailureCount = Math.Min(_hubFailureCount + 1, 4);
        var seconds = Math.Min(
            HubMaximumBackoff.TotalSeconds,
            HubNormalInterval.TotalSeconds * Math.Pow(2, _hubFailureCount));

        if (_hubTimer is not null)
            _hubTimer.Interval = TimeSpan.FromSeconds(seconds);
    }

    private void RebuildProfessionalNavigation(bool force = false)
    {
        if (PosNavButton.Parent is not StackPanel sidebar) return;

        _dashboardNavButton ??= FindNavigationButton(sidebar, "Visão geral");
        _ordersNavButton ??= FindNavigationButton(sidebar, "Pedidos");

        var groups = BuildNavigationGroups();
        var signature = BuildNavigationSignature(groups);
        if (!force && string.Equals(signature, _lastNavigationSignature, StringComparison.Ordinal)) return;
        _lastNavigationSignature = signature;

        sidebar.Children.Clear();
        foreach (var group in OrderNavigationGroups(groups))
        {
            var visibleButtons = group.Buttons
                .Where(button => button is not null && button.Visibility == Visibility.Visible)
                .Cast<Button>()
                .ToList();

            if (visibleButtons.Count == 0) continue;

            sidebar.Children.Add(CreateNavigationGroupHeader(group.Title, group.IsCurrent));
            foreach (var button in visibleButtons)
            {
                button.Click -= ProfessionalNavigationButton_Click;
                button.Click += ProfessionalNavigationButton_Click;
                sidebar.Children.Add(button);
            }
        }
    }

    private List<NavigationGroup> BuildNavigationGroups()
    {
        var mode = HasShift ? ShiftValue("mode") : "";
        return new List<NavigationGroup>
        {
            new(
                "OPERAÇÃO",
                mode == "operation",
                new Button?[]
                {
                    _dashboardNavButton,
                    PosNavButton,
                    _ordersNavButton,
                    TablesNavButton,
                    CashNavButton,
                    _productionNavButton,
                    _inventoryNavButton
                }),
            new(
                "DELIVERY",
                mode == "delivery",
                new Button?[]
                {
                    _deliveryMonitorButton,
                    _deliveryTriageButton,
                    _deliveryWorkButton
                }),
            new(
                "EVENTOS",
                mode == "events",
                new Button?[]
                {
                    _eventOperationsButton
                }),
            new(
                "GESTÃO",
                mode == "pay",
                new Button?[]
                {
                    _managerCenterButton,
                    _approvalsNavButton,
                    _notificationsNavButton,
                    _fiscalNavButton
                }),
            new(
                "FERRAMENTAS",
                false,
                new Button?[]
                {
                    _qrNavButton,
                    _myQrButton,
                    _shiftSummaryButton,
                    _hubNavButton,
                    _hardwareSettingsButton
                })
        };
    }

    private IEnumerable<NavigationGroup> OrderNavigationGroups(List<NavigationGroup> groups)
    {
        var current = groups.FirstOrDefault(group => group.IsCurrent);
        if (current is not null) yield return current;

        foreach (var group in groups)
        {
            if (ReferenceEquals(group, current)) continue;
            yield return group;
        }
    }

    private static Button? FindNavigationButton(StackPanel sidebar, string label) =>
        sidebar.Children
            .OfType<Button>()
            .FirstOrDefault(button => string.Equals(button.Content?.ToString(), label, StringComparison.Ordinal));

    private string BuildNavigationSignature(IEnumerable<NavigationGroup> groups)
    {
        var mode = HasShift ? ShiftValue("mode") : "none";
        var parts = new List<string> { mode };
        foreach (var group in groups)
        {
            parts.Add(group.Title);
            foreach (var button in group.Buttons)
            {
                if (button is null) continue;
                parts.Add($"{button.Content}:{button.Visibility}");
            }
        }
        return string.Join('|', parts);
    }

    private TextBlock CreateNavigationGroupHeader(string title, bool isCurrent)
    {
        var foreground = TryFindResource("SidebarMutedBrush") as Brush ?? Brushes.SlateGray;
        return new TextBlock
        {
            Text = isCurrent ? $"{title}  •  TURNO ATUAL" : title,
            Foreground = foreground,
            FontWeight = FontWeights.SemiBold,
            FontSize = 10,
            Margin = new Thickness(12, 14, 0, 7)
        };
    }

    private void ProfessionalNavigationButton_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button active || PosNavButton.Parent is not StackPanel sidebar) return;
        foreach (var button in sidebar.Children.OfType<Button>())
            button.Tag = null;
        active.Tag = "active";
    }

    private void MainWindow_HardeningClosed(object? sender, EventArgs e)
    {
        Loaded -= MainWindow_HardeningLoaded;
        ContentRendered -= MainWindow_HardeningContentRendered;
        Closed -= MainWindow_HardeningClosed;
        _refreshTimer.Tick -= HardeningRefreshTimer_Tick;
        if (_hubTimer is not null) _hubTimer.Tick -= HardenedHubTimer_Tick;
    }

    private sealed record NavigationGroup(string Title, bool IsCurrent, Button?[] Buttons);
}
