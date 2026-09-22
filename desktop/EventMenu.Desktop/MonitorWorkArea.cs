using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using System.Windows.Media;

namespace EventMenu.Desktop;

/// <summary>
/// Returns the Windows work area for the monitor that currently contains a WPF window.
/// Coordinates from Win32 are converted from physical pixels to WPF device-independent units
/// using the actual monitor DPI applied to the window.
/// </summary>
internal static class MonitorWorkArea
{
    private const uint MonitorDefaultToNearest = 2;

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeRect
    {
        public int Left;
        public int Top;
        public int Right;
        public int Bottom;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)]
    private struct MonitorInfo
    {
        public uint Size;
        public NativeRect Monitor;
        public NativeRect Work;
        public uint Flags;
    }

    [DllImport("user32.dll")]
    private static extern nint MonitorFromWindow(nint hwnd, uint flags);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetMonitorInfo(nint monitor, ref MonitorInfo info);

    public static Rect Get(Window window)
    {
        var handle = new WindowInteropHelper(window).Handle;
        if (handle == nint.Zero)
            return SystemParameters.WorkArea;

        var monitor = MonitorFromWindow(handle, MonitorDefaultToNearest);
        if (monitor == nint.Zero)
            return SystemParameters.WorkArea;

        var info = new MonitorInfo { Size = (uint)Marshal.SizeOf<MonitorInfo>() };
        if (!GetMonitorInfo(monitor, ref info))
            return SystemParameters.WorkArea;

        var transform = HwndSource.FromHwnd(handle)?.CompositionTarget?.TransformFromDevice ?? Matrix.Identity;
        var topLeft = transform.Transform(new Point(info.Work.Left, info.Work.Top));
        var bottomRight = transform.Transform(new Point(info.Work.Right, info.Work.Bottom));

        var width = Math.Max(1, bottomRight.X - topLeft.X);
        var height = Math.Max(1, bottomRight.Y - topLeft.Y);
        return new Rect(topLeft.X, topLeft.Y, width, height);
    }
}
