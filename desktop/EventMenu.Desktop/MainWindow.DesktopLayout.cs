using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _desktopLayoutHooksReady;
    private bool _desktopInitialFitDone;

    static MainWindow()
    {
        EventManager.RegisterClassHandler(typeof(MainWindow), FrameworkElement.LoadedEvent, new RoutedEventHandler(DesktopLayout_Loaded));
    }

    private static void DesktopLayout_Loaded(object sender, RoutedEventArgs e)
    {
        if (sender is not MainWindow window) return;
        if (!window._desktopLayoutHooksReady)
        {
            window._desktopLayoutHooksReady = true;
            window.MinWidth = 860;
            window.MinHeight = 560;
            window.SizeChanged += (_, _) => window.ApplyDesktopLayout();
            window.StateChanged += (_, _) => window.Dispatcher.BeginInvoke(window.ApplyDesktopLayout);
        }

        if (!window._desktopInitialFitDone)
        {
            window._desktopInitialFitDone = true;
            window.FitMainWindowToWorkArea();
        }

        window.ApplyDesktopLayout();
    }

    private void FitMainWindowToWorkArea()
    {
        if (WindowState != WindowState.Normal) return;

        var workArea = SystemParameters.WorkArea;
        const double safeMargin = 16;
        var availableWidth = Math.Max(760, workArea.Width - safeMargin);
        var availableHeight = Math.Max(520, workArea.Height - safeMargin);

        MinWidth = Math.Min(MinWidth, availableWidth);
        MinHeight = Math.Min(MinHeight, availableHeight);

        if (double.IsNaN(Width) || Width <= 0 || Width > availableWidth)
            Width = availableWidth;
        if (double.IsNaN(Height) || Height <= 0 || Height > availableHeight)
            Height = availableHeight;

        Width = Math.Max(MinWidth, Math.Min(Width, availableWidth));
        Height = Math.Max(MinHeight, Math.Min(Height, availableHeight));

        // Recenter after clamping. This prevents part of the window from opening
        // outside the visible work area when Windows display scaling is 125/150%.
        Left = workArea.Left + Math.Max(0, (workArea.Width - Width) / 2);
        Top = workArea.Top + Math.Max(0, (workArea.Height - Height) / 2);
    }

    private void ApplyDesktopLayout()
    {
        if (!IsLoaded || PosView is null) return;

        var width = ActualWidth > 0 ? ActualWidth : Width;
        var height = ActualHeight > 0 ? ActualHeight : Height;
        var ultraCompact = width < 1080;
        var compact = width < 1320;
        var standard = width < 1680;
        var shortScreen = height < 700;

        // Login is a native WPF surface too: keep text crisp and adapt it to
        // Windows scaling / smaller work areas before laying out the POS shell.
        ApplyLoginPolish(width, height);

        // Shell: use more of the real monitor area instead of preserving web-like gutters.
        if (PosView.Parent is Grid contentGrid && contentGrid.Parent is Grid bodyGrid && bodyGrid.ColumnDefinitions.Count >= 2)
        {
            bodyGrid.ColumnDefinitions[0].Width = new GridLength(
                ultraCompact ? 156 : compact ? 184 : standard ? 208 : 228);
            contentGrid.Margin = ultraCompact
                ? new Thickness(8, 8, 8, 8)
                : compact
                    ? new Thickness(12, 12, 12, 10)
                    : standard
                        ? new Thickness(18, 16, 18, 14)
                        : new Thickness(24, 20, 24, 18);
        }

        // Keep navigation usable without consuming a quarter of a small notebook screen.
        if (PosNavButton.Parent is StackPanel navMenu)
        {
            foreach (var button in navMenu.Children.OfType<Button>())
            {
                button.FontSize = ultraCompact ? 11 : 12;
                button.Padding = ultraCompact
                    ? new Thickness(9, 9, 7, 9)
                    : new Thickness(14, 11, 14, 11);
            }
        }

        UnitSelector.Width = ultraCompact ? 92 : compact ? 108 : standard ? 138 : 165;
        ModeSelector.Width = ultraCompact ? 82 : compact ? 94 : standard ? 110 : 125;

        // The center block in the title bar is the first area to collide on 1366px
        // notebooks with display scaling. Remove labels before removing controls.
        if (UnitSelector.Parent is StackPanel headerControls)
        {
            var labels = headerControls.Children.OfType<TextBlock>().ToList();
            foreach (var label in labels)
            {
                if (label.Text is "Unidade" or "Turno")
                    label.Visibility = ultraCompact ? Visibility.Collapsed : Visibility.Visible;
            }

            if (DesktopAncestor<Border>(headerControls) is { } headerCard)
                headerCard.Padding = ultraCompact ? new Thickness(5, 4, 5, 4) : new Thickness(10, 5, 10, 5);
        }

        ShiftStatusText.Visibility = ultraCompact ? Visibility.Collapsed : Visibility.Visible;
        RoleText.Visibility = compact ? Visibility.Collapsed : Visibility.Visible;
        UserNameText.Visibility = ultraCompact ? Visibility.Collapsed : Visibility.Visible;

        if (TenantNameText.Parent is Border tenantBadge)
            tenantBadge.Visibility = ultraCompact ? Visibility.Collapsed : Visibility.Visible;

        // POS workspace adapts like a real desktop application.
        var workspace = PosView.Children.OfType<Grid>().FirstOrDefault(x => Grid.GetRow(x) == 1 && x.ColumnDefinitions.Count >= 3);
        if (workspace is not null)
        {
            workspace.ColumnDefinitions[0].Width = new GridLength(ultraCompact ? 1.02 : compact ? 1.08 : standard ? 1.20 : 1.25, GridUnitType.Star);
            workspace.ColumnDefinitions[1].Width = new GridLength(ultraCompact ? 7 : compact ? 10 : standard ? 14 : 18);
            workspace.ColumnDefinitions[2].Width = new GridLength(ultraCompact ? 0.98 : compact ? 0.92 : standard ? 0.86 : 0.82, GridUnitType.Star);
        }

        // 1366x768 (and its logical size at 125/150%) needs fewer product columns.
        if (ProductsGrid.Columns.Count >= 5)
        {
            ProductsGrid.Columns[1].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // SKU
            ProductsGrid.Columns[4].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // situação
            ProductsGrid.Columns[2].Width = ultraCompact ? new DataGridLength(76) : compact ? new DataGridLength(86) : new DataGridLength(108);
            ProductsGrid.Columns[3].Width = ultraCompact ? new DataGridLength(64) : compact ? new DataGridLength(72) : new DataGridLength(88);
        }

        // Dashboard metrics switch to two columns only when the available width is
        // genuinely tight, avoiding microscopic cards while keeping normal monitors dense.
        var metrics = DashboardView.Children.OfType<UniformGrid>().FirstOrDefault();
        if (metrics is not null)
            metrics.Columns = ultraCompact ? 2 : 4;

        // Vertical density follows the actual monitor/work area instead of a fixed web page height.
        CartGrid.Height = height < 620 ? 82 : height < 700 ? 102 : height < 780 ? 124 : height < 860 ? 148 : 174;

        var pageHeaderMargin = shortScreen ? new Thickness(0, 0, 0, 10) : new Thickness(0, 0, 0, 18);
        foreach (var dock in DesktopDescendants<DockPanel>(ShellPanel))
        {
            if (dock.Margin.Bottom >= 16 && dock.Margin.Top == 0)
                dock.Margin = pageHeaderMargin;
        }

        // Native Windows feel: operational surfaces are flatter and denser than website cards.
        ApplyOperationalCardDensity(PosView, compact ? 8 : 10);
        ApplyOperationalCardDensity(OrdersView, compact ? 8 : 10);
        ApplyOperationalCardDensity(TablesView, compact ? 8 : 10);
    }

    private static T? DesktopAncestor<T>(DependencyObject? start) where T : DependencyObject
    {
        var current = start;
        while (current is not null)
        {
            if (current is T match) return match;
            current = VisualTreeHelper.GetParent(current);
        }
        return null;
    }

    private static void ApplyOperationalCardDensity(DependencyObject root, double radius)
    {
        foreach (var border in DesktopDescendants<Border>(root))
        {
            if (border.Style == Application.Current.TryFindResource("Card") as Style)
                border.CornerRadius = new CornerRadius(radius);
        }
    }

    private static IEnumerable<T> DesktopDescendants<T>(DependencyObject root) where T : DependencyObject
    {
        var count = VisualTreeHelper.GetChildrenCount(root);
        for (var i = 0; i < count; i++)
        {
            var child = VisualTreeHelper.GetChild(root, i);
            if (child is T match) yield return match;
            foreach (var nested in DesktopDescendants<T>(child)) yield return nested;
        }
    }
}
