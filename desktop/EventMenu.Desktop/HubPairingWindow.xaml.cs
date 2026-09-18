using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using EventMenu.Desktop.Models;
using QRCoder;

namespace EventMenu.Desktop;

public partial class HubPairingWindow : Window
{
    private readonly HubPairing _pairing;
    private readonly string _payload;
    private readonly string _manualCode;

    public HubPairingWindow(HubPairing pairing)
    {
        _pairing = pairing;
        _payload = ResolvePayload(pairing);
        _manualCode = ResolveManualCode(pairing, _payload);

        InitializeComponent();
        PairingCodeText.Text = _manualCode;
        ExpiresText.Text = FormatExpiry(pairing.ExpiresAt);

        if (string.IsNullOrWhiteSpace(_payload))
        {
            PairingQrImage.Source = null;
            ExpiresText.Text = "Não foi possível gerar o código. Feche e tente novamente.";
            return;
        }

        try
        {
            PairingQrImage.Source = CreateQrNative(_payload);
        }
        catch
        {
            PairingQrImage.Source = null;
            ExpiresText.Text = "Use o código abaixo para conectar o celular.";
        }
    }

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(_manualCode)) return;
        Clipboard.SetText(_manualCode);
        ExpiresText.Text = "Código copiado • " + FormatExpiry(_pairing.ExpiresAt);
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private static string ResolvePayload(HubPairing pairing)
    {
        if (!string.IsNullOrWhiteSpace(pairing.Qr)) return pairing.Qr.Trim();
        if (!string.IsNullOrWhiteSpace(pairing.Token)) return "EVENTMENU:HUB:" + pairing.Token.Trim();
        return "";
    }

    private static string ResolveManualCode(HubPairing pairing, string payload)
    {
        if (!string.IsNullOrWhiteSpace(pairing.Token)) return pairing.Token.Trim();
        const string prefix = "EVENTMENU:HUB:";
        return payload.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)
            ? payload[prefix.Length..]
            : payload;
    }

    private static BitmapSource CreateQrNative(string value)
    {
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(value, QRCodeGenerator.ECCLevel.M, forceUtf8: true);

        var matrix = data.ModuleMatrix;
        if (matrix is null || matrix.Count == 0)
            throw new InvalidOperationException("QR inválido.");

        const int quietZoneModules = 4;
        const int modulePixels = 8;
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

    private static string FormatExpiry(string value)
    {
        if (DateTimeOffset.TryParse(value, out var date)) return $"Expira às {date.ToLocalTime():HH:mm:ss}";
        return "Código válido por poucos minutos";
    }
}
