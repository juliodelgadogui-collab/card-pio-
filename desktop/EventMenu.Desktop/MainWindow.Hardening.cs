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
    private StackPanel? _professionalNavigationSidebar;
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

        // O Hub é importante, mas não pode martelar o servidor quando um módulo
        // opcional ainda não estiver implantado ou estiver temporariamente fora.
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
            ApplyServerCapabilityVisibility();
            RebuildProfessionalNavigation(true);
        }));
    }

    private void HardeningRefreshTimer_Tick(object? sender, EventArgs e)
    {
        ApplyServerCapabilityVisibility();
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

            EnsureHubRuntime();
            if (_hubIntegrationApi is null || _hubHardwareStore is null || _hubProcessor is null)
                return;

            await ProbeOptionalFeaturesAsync(unitId);
            ApplyServerCapabilityVisibility();
            RebuildProfessionalNavigation();

            var healthy = true;
            healthy &= await TryHardwareHeartbeatAsync(unitId);
            healthy &= await TryHubCommandsAsync(unitId);
            healthy &= await TryProductionPrintAsync();

            if (healthy) ResetHubBackoff();
            else IncreaseHubBackoff();
        }
        catch (ApiClientException)
        {
            IncreaseHubBackoff();
        }
        catch
        {
            // Recursos em segundo plano nunca devem derrubar PDV, caixa ou pedidos.
            IncreaseHubBackoff();
        }
        finally
        {
            _hubBusy = false;
        }
    }

    private async Task ProbeOptionalFeaturesAsync(int unitId)
    {
        if (_hubIntegrationApi is null || _hubHardwareStore is null) return;

        if (Can("hardware_manage"))
        {
            await ProbeFeatureAsync(
                DesktopFeatureNames.Hardware,
                async () =>
                {
                    await _hubIntegrationApi.HardwareHeartbeatAsync(unitId, _hubHardwareStore, _hubHardwareStore.Load());
                    _lastHubHeartbeat = DateTimeOffset.UtcNow;
                });

            await ProbeFeatureAsync(
                DesktopFeatureNames.Terminal,
                async () => { await _hubIntegrationApi.TerminalListAsync(unitId); });

            await ProbeFeatureAsync(
                DesktopFeatureNames.Hub,
                async () => { await _hubIntegrationApi.PollHubAsync(1); });
        }

        if (Can("orders_kitchen") || Can("orders_dispatch") || Can("production_print") || Can("production_manage"))
        {
            await ProbeFeatureAsync(
                DesktopFeatureNames.Production,
                async () => { await _hubIntegrationApi.ProductionBoardAsync(); });
        }

        if (Can("fiscal_manage") || Can("fiscal_issue"))
        {
            await ProbeFeatureAsync(
                DesktopFeatureNames.Fiscal,
                async () => { await _hubIntegrationApi.FiscalReadinessAsync(unitId); });
        }
    }

    private static async Task ProbeFeatureAsync(string feature, Func<Task> probe)
    {
        if (!DesktopFeatureAvailability.ShouldProbe(feature)) return;
        DesktopFeatureAvailability.MarkProbeAttempt(feature);

        try
        {
            await probe();
            DesktopFeatureAvailability.MarkAvailable(feature);
        }
        catch (ApiClientException ex)
        {
            DesktopFeatureAvailability.MarkUnavailableIfUnsupported(feature, ex);
            // Falha transitória permanece como desconhecida e só será testada novamente
            // depois do cooldown, evitando requisições repetitivas.
        }
        catch
        {
            // Falhas locais/transitórias não significam que o recurso não existe.
        }
    }

    private async Task<bool> TryHardwareHeartbeatAsync(int unitId)
    {
        if (_hubIntegrationApi is null || _hubHardwareStore is null) return true;
        if (!DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Hardware)) return true;
        if (DateTimeOffset.UtcNow - _lastHubHeartbeat <= TimeSpan.FromSeconds(30)) return true;

        try
        {
            await _hubIntegrationApi.HardwareHeartbeatAsync(unitId, _hubHardwareStore, _hubHardwareStore.Load());
            _lastHubHeartbeat = DateTimeOffset.UtcNow;
            DesktopFeatureAvailability.MarkAvailable(DesktopFeatureNames.Hardware);
            return true;
        }
        catch (ApiClientException ex) when (DesktopFeatureAvailability.MarkUnavailableIfUnsupported(DesktopFeatureNames.Hardware, ex))
        {
            ApplyServerCapabilityVisibility();
            return true;
        }
        catch
        {
            return false;
        }
    }

    private async Task<bool> TryHubCommandsAsync(int unitId)
    {
        if (_hubIntegrationApi is null || _hubProcessor is null) return true;
        if (!DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Hub)) return true;

        try
        {
            var response = await _hubIntegrationApi.PollHubAsync(10);
            DesktopFeatureAvailability.MarkAvailable(DesktopFeatureNames.Hub);
            foreach (var command in response.Commands)
            {
                if (command.UnitId != unitId) continue;
                await _hubProcessor.ProcessAsync(command);
            }
            return true;
        }
        catch (ApiClientException ex) when (DesktopFeatureAvailability.MarkUnavailableIfUnsupported(DesktopFeatureNames.Hub, ex))
        {
            ApplyServerCapabilityVisibility();
            return true;
        }
        catch
        {
            return false;
        }
    }

    private async Task<bool> TryProductionPrintAsync()
    {
        if (!ShiftIs("operation") ||
            (!Can("production_print") && !Can("orders_kitchen")) ||
            _productionPrintProcessor is null ||
            !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Production))
            return true;

        try
        {
            for (var i = 0; i < 2; i++)
                if (!await _productionPrintProcessor.ProcessOneAsync()) break;
            DesktopFeatureAvailability.MarkAvailable(DesktopFeatureNames.Production);
            return true;
        }
        catch (ApiClientException ex) when (DesktopFeatureAvailability.MarkUnavailableIfUnsupported(DesktopFeatureNames.Production, ex))
        {
            ApplyServerCapabilityVisibility();
            return true;
        }
        catch
        {
            return false;
        }
    }

    private void ApplyServerCapabilityVisibility()
    {
        if (_productionNavButton is not null && !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Production))
            _productionNavButton.Visibility = Visibility.Collapsed;

        if (_fiscalNavButton is not null && !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Fiscal))
            _fiscalNavButton.Visibility = Visibility.Collapsed;

        if (_hubNavButton is not null && !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Hub))
            _hubNavButton.Visibility = Visibility.Collapsed;

        if (_hardwareSettingsButton is not null &&
            !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Hardware) &&
            !DesktopFeatureAvailability.IsAvailable(DesktopFeatureNames.Terminal))
            _hardwareSettingsButton.Visibility = Visibility.Collapsed;

        var unavailable = new[]
        {
            DesktopFeatureNames.Production,
            DesktopFeatureNames.Fiscal,
            DesktopFeatureNames.Hub,
            DesktopFeatureNames.Hardware,
            DesktopFeatureNames.Terminal
        }
        .Where(feature => !DesktopFeatureAvailability.IsAvailable(feature))
        .Select(DesktopFeatureNames.Label)
        .Distinct()
        .ToList();

        AutoRefreshText.Text = unavailable.Count == 0
            ? "Atualização automática ativa"
            : $"Operação ativa • indisponível neste servidor: {string.Join(", ", unavailable)}";
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
        _professionalNavigationSidebar ??= PosNavButton.Parent as StackPanel;
        if (_professionalNavigationSidebar is not StackPanel sidebar) return;

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
        if (sender is not Button active || _professionalNavigationSidebar is not StackPanel sidebar) return;
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
        _professionalNavigationSidebar = null;
    }

    private sealed record NavigationGroup(string Title, bool IsCurrent, Button?[] Buttons);
}
