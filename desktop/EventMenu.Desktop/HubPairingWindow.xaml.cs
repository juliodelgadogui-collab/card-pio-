using System.Windows;
using EventMenu.Desktop.Models;

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
    }

    private void CopyButton_Click(object sender,RoutedEventArgs e)
    {
        Clipboard.SetText(_pairing.Qr);
        ExpiresText.Text="Código copiado • "+FormatExpiry(_pairing.ExpiresAt);
    }

    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();

    private static string FormatExpiry(string value)
    {
        if(DateTimeOffset.TryParse(value,out var date))return $"Expira às {date.ToLocalTime():HH:mm:ss}";
        return "Código válido por poucos minutos";
    }
}
