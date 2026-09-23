using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using QRCoder;

namespace EventMenu.Desktop.Services;

public static class QrCodeRenderer
{
    public static BitmapSource Create(string value, int modulePixels = 8)
    {
        if (string.IsNullOrWhiteSpace(value))
            throw new ArgumentException("Conteúdo do QR Code vazio.", nameof(value));

        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(value.Trim(), QRCodeGenerator.ECCLevel.M, forceUtf8: true);
        var matrix = data.ModuleMatrix;
        if (matrix is null || matrix.Count == 0)
            throw new InvalidOperationException("Não foi possível montar o QR Code.");

        const int quietZoneModules = 4;
        modulePixels = Math.Clamp(modulePixels, 3, 14);
        var modules = matrix.Count;
        var size = (modules + quietZoneModules * 2) * modulePixels;

        var visual = new DrawingVisual();
        using (var dc = visual.RenderOpen())
        {
            dc.DrawRectangle(Brushes.White, null, new Rect(0, 0, size, size));
            for (var y = 0; y < modules; y++)
            {
                for (var x = 0; x < modules; x++)
                {
                    if (!matrix[y][x]) continue;
                    dc.DrawRectangle(
                        Brushes.Black,
                        null,
                        new Rect(
                            (x + quietZoneModules) * modulePixels,
                            (y + quietZoneModules) * modulePixels,
                            modulePixels,
                            modulePixels));
                }
            }
        }

        var bitmap = new RenderTargetBitmap(size, size, 96, 96, PixelFormats.Pbgra32);
        bitmap.Render(visual);
        bitmap.Freeze();
        return bitmap;
    }
}
