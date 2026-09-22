using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Documents;
using System.Windows.Media;

namespace EventMenu.Desktop;

/// <summary>
/// Applies safe, DPI-friendly defaults to secondary WPF windows.
/// It intentionally does not scale the visual tree: text stays sharp and controls
/// keep their native size. Oversized windows are clamped to the work area of the
/// monitor that actually contains the window.
/// </summary>
internal static class ResponsiveWindowBehavior
{
    private sealed class ResponsiveState
    {
        public bool HooksReady;
        public bool Adjusting;
    }

    private static readonly ConditionalWeakTable<Window, ResponsiveState> States = new();
    private static bool _enabled;

    public static void Enable()
    {
        if (_enabled) return;
        _enabled = true;
        EventManager.RegisterClassHandler(
            typeof(Window),
            FrameworkElement.LoadedEvent,
            new RoutedEventHandler(Window_Loaded));
    }

    private static void Window_Loaded(object sender, RoutedEventArgs e)
    {
        if (sender is not Window window || window is MainWindow) return;

        window.UseLayoutRounding = true;
        window.SnapsToDevicePixels = true;
        TextOptions.SetTextFormattingMode(window, TextFormattingMode.Display);
        TextOptions.SetTextRenderingMode(window, TextRenderingMode.ClearType);
        TextOptions.SetTextHintingMode(window, TextHintingMode.Fixed);
        TextElement.SetFontFamily(window, new FontFamily("Segoe UI"));

        var state = States.GetOrCreateValue(window);
        if (!state.HooksReady)
        {
            state.HooksReady = true;
            window.LocationChanged += SecondaryWindow_LocationChanged;
            window.DpiChanged += SecondaryWindow_DpiChanged;
        }

        // On first display, owned dialogs belong on the owner's monitor. After that,
        // moving a window between monitors uses the window's own current monitor.
        FitToWorkArea(window, center: true, preferOwnerMonitor: true);
    }

    private static void SecondaryWindow_LocationChanged(object? sender, EventArgs e)
    {
        if (sender is Window window)
            FitToWorkArea(window, center: false, preferOwnerMonitor: false);
    }

    private static void SecondaryWindow_DpiChanged(object? sender, DpiChangedEventArgs e)
    {
        if (sender is not Window window) return;
        window.Dispatcher.BeginInvoke(new Action(() =>
            FitToWorkArea(window, center: false, preferOwnerMonitor: false)));
    }

    private static void FitToWorkArea(Window window, bool center, bool preferOwnerMonitor)
    {
        if (window.WindowState != WindowState.Normal) return;

        var state = States.GetOrCreateValue(window);
        if (state.Adjusting) return;
        state.Adjusting = true;

        try
        {
            var monitorReference = preferOwnerMonitor && window.Owner is { IsVisible: true } owner
                ? owner
                : window;
            var workArea = MonitorWorkArea.Get(monitorReference);
            const double safeMargin = 20;
            var availableWidth = Math.Max(320, workArea.Width - safeMargin);
            var availableHeight = Math.Max(280, workArea.Height - safeMargin);

            // A MinWidth/MinHeight larger than the logical work area is common when
            // Windows is at 125% or 150% scaling. Lower only the minimum necessary.
            if (window.MinWidth > availableWidth) window.MinWidth = availableWidth;
            if (window.MinHeight > availableHeight) window.MinHeight = availableHeight;

            var width = double.IsNaN(window.Width) || window.Width <= 0
                ? Math.Min(900, availableWidth)
                : Math.Min(window.Width, availableWidth);
            var height = double.IsNaN(window.Height) || window.Height <= 0
                ? Math.Min(700, availableHeight)
                : Math.Min(window.Height, availableHeight);

            window.Width = Math.Max(window.MinWidth, width);
            window.Height = Math.Max(window.MinHeight, height);

            double desiredLeft;
            double desiredTop;
            if (center)
            {
                desiredLeft = window.Owner is { IsVisible: true } visibleOwner
                    ? visibleOwner.Left + ((visibleOwner.ActualWidth > 0 ? visibleOwner.ActualWidth : visibleOwner.Width) - window.Width) / 2
                    : workArea.Left + (workArea.Width - window.Width) / 2;
                desiredTop = window.Owner is { IsVisible: true } ownerForTop
                    ? ownerForTop.Top + ((ownerForTop.ActualHeight > 0 ? ownerForTop.ActualHeight : ownerForTop.Height) - window.Height) / 2
                    : workArea.Top + (workArea.Height - window.Height) / 2;
            }
            else
            {
                desiredLeft = window.Left;
                desiredTop = window.Top;
            }

            window.Left = Math.Max(workArea.Left, Math.Min(desiredLeft, workArea.Right - window.Width));
            window.Top = Math.Max(workArea.Top, Math.Min(desiredTop, workArea.Bottom - window.Height));
        }
        finally
        {
            state.Adjusting = false;
        }
    }
}
