using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Media;
using System.Windows.Media.Effects;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private void ApplyLoginPolish(double width, double height)
    {
        if (LoginPanel is null) return;

        // Avoid fractional pixels and bitmap-blurred text on Windows 10/11.
        UseLayoutRounding = true;
        SnapsToDevicePixels = true;
        TextOptions.SetTextFormattingMode(LoginPanel, TextFormattingMode.Display);
        TextOptions.SetTextRenderingMode(LoginPanel, TextRenderingMode.ClearType);
        TextOptions.SetTextHintingMode(LoginPanel, TextHintingMode.Fixed);
        TextElement.SetFontFamily(LoginPanel, new FontFamily("Segoe UI"));

        // A WPF DropShadowEffect on the same visual tree as the form can force
        // text/inputs through an off-screen bitmap and make ClearType look washed out.
        // Keep the login content fully native/crisp; the card border already gives depth.
        foreach (var border in DesktopDescendants<Border>(LoginPanel))
        {
            if (border.Effect is DropShadowEffect)
                border.Effect = null;
        }

        EmailBox.FontFamily = new FontFamily("Segoe UI");
        EmailBox.FontSize = 15;
        EmailBox.FontWeight = FontWeights.Normal;
        EmailBox.Height = 50;
        EmailBox.MinHeight = 50;
        EmailBox.Padding = new Thickness(14, 8, 14, 8);
        EmailBox.Foreground = new SolidColorBrush(Color.FromRgb(16, 24, 40));
        EmailBox.CaretBrush = new SolidColorBrush(Color.FromRgb(37, 99, 235));
        EmailBox.VerticalContentAlignment = VerticalAlignment.Center;

        PasswordBox.FontFamily = new FontFamily("Segoe UI");
        PasswordBox.FontSize = 15;
        PasswordBox.FontWeight = FontWeights.Normal;
        PasswordBox.Height = 50;
        PasswordBox.MinHeight = 50;
        PasswordBox.Padding = new Thickness(14, 8, 14, 8);
        PasswordBox.Foreground = new SolidColorBrush(Color.FromRgb(16, 24, 40));
        PasswordBox.CaretBrush = new SolidColorBrush(Color.FromRgb(37, 99, 235));
        PasswordBox.VerticalContentAlignment = VerticalAlignment.Center;

        LoginButton.Height = 50;
        LoginButton.MinHeight = 50;
        LoginButton.FontSize = 14;
        LoginStatus.FontSize = 13;

        if (LoginPanel.ColumnDefinitions.Count < 2) return;

        // On small work areas or high Windows scaling, prioritize the actual login
        // instead of forcing the marketing panel and form to share too little space.
        var singlePane = width < 1120 || height < 680;
        LoginPanel.ColumnDefinitions[0].Width = singlePane
            ? new GridLength(0)
            : new GridLength(0.95, GridUnitType.Star);
        LoginPanel.ColumnDefinitions[1].Width = singlePane
            ? new GridLength(1, GridUnitType.Star)
            : new GridLength(1.05, GridUnitType.Star);

        var promo = LoginPanel.Children
            .OfType<Border>()
            .FirstOrDefault(x => Grid.GetColumn(x) == 0);
        if (promo is not null)
        {
            promo.Visibility = singlePane ? Visibility.Collapsed : Visibility.Visible;
            promo.Padding = width < 1360
                ? new Thickness(42, 38, 42, 38)
                : new Thickness(64, 54, 64, 54);
        }

        var loginHost = LoginPanel.Children
            .OfType<Grid>()
            .FirstOrDefault(x => Grid.GetColumn(x) == 1);
        if (loginHost is null) return;

        loginHost.Margin = singlePane
            ? new Thickness(24, 20, 24, 20)
            : width < 1360
                ? new Thickness(42, 34, 42, 34)
                : new Thickness(64, 46, 64, 46);

        var card = loginHost.Children.OfType<Border>().FirstOrDefault();
        if (card is not null)
        {
            card.MaxWidth = singlePane ? 500 : 480;
            card.Padding = width < 1360 ? new Thickness(34) : new Thickness(42);
        }
    }
}
