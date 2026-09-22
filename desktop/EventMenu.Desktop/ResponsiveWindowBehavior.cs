using System.Windows;
using System.Windows.Documents;
using System.Windows.Media;

namespace EventMenu.Desktop;

/// <summary>
/// Applies safe, DPI-friendly defaults to secondary WPF windows.
/// It intentionally does not scale the visual tree: text stays sharp and controls
/// keep their native size. Oversized windows are clamped to the Windows work area,
/// allowing existing star-sized grids and ScrollViewers to do the adaptation.
/// </summary>
internal static class ResponsiveWindowBehavior
{
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

        FitToWorkArea(window);
    }

    private static void FitToWorkArea(Window window)
    {
        if (window.WindowState != WindowState.Normal) return;

        var workArea = SystemParameters.WorkArea;
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

        // Re-center after changing the dimensions, but never place any edge
        // outside the Windows work area.
        var desiredLeft = window.Owner is { IsVisible: true } owner
            ? owner.Left + ((owner.ActualWidth > 0 ? owner.ActualWidth : owner.Width) - window.Width) / 2
            : workArea.Left + (workArea.Width - window.Width) / 2;
        var desiredTop = window.Owner is { IsVisible: true } visibleOwner
            ? visibleOwner.Top + ((visibleOwner.ActualHeight > 0 ? visibleOwner.ActualHeight : visibleOwner.Height) - window.Height) / 2
            : workArea.Top + (workArea.Height - window.Height) / 2;

        window.Left = Math.Max(workArea.Left, Math.Min(desiredLeft, workArea.Right - window.Width));
        window.Top = Math.Max(workArea.Top, Math.Min(desiredTop, workArea.Bottom - window.Height));
    }
}
