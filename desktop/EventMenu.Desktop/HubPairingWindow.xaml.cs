using System.IO;
using System.Windows;
using System.Windows.Media.Imaging;
using EventMenu.Desktop.Models;
using QRCoder;

namespace EventMenu.Desktop;

public partial class HubPairingWindow : Window
{
    private readonly HubPairing _pairing;

    public HubPairingWindow(HubPairing pairing)
    {
        _pairing=pairing;
        InitializeComponent();
        PairingCodeText.Text=pairing.Qr;
        ExpiresText.Text=FormatExpiry(pairing.ExpiresAt);
        PairingQrImage.Source=CreateQr(pairing.Qr);
    }

    private void CopyButton_Click(object sender,RoutedEventArgs e)
    {
        Clipboard.SetText(_pairing.Qr);
        ExpiresText.Text="Código copiado • "+FormatExpiry(_pairing.ExpiresAt);
    }

    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();

    private static BitmapSource CreateQr(string value)
    {
        using var generator=new QRCodeGenerator();
        using var data=generator.CreateQrCode(value,QRCodeGenerator.ECCLevel.M);
        var qr=new PngByteQRCode(data);
        var bytes=qr.GetGraphic(12);
        using var stream=new MemoryStream(bytes);
        var image=new BitmapImage();
        image.BeginInit();
        image.CacheOption=BitmapCacheOption.OnLoad;
        image.StreamSource=stream;
        image.EndInit();
        image.Freeze();
        return image;
    }

    private static string FormatExpiry(string value)
    {
        if(DateTimeOffset.TryParse(value,out var date))return $"Expira às {date.ToLocalTime():HH:mm:ss}";
        return "Código válido por poucos minutos";
    }
}
