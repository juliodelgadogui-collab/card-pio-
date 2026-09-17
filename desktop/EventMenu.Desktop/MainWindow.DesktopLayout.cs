using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    protected override void OnInitialized(EventArgs e)
    {
        base.OnInitialized(e);
        MinWidth = 980;
        MinHeight = 620;
        SizeChanged += (_, _) => ApplyDesktopLayout();
        StateChanged += (_, _) => Dispatcher.BeginInvoke(ApplyDesktopLayout);
        Loaded += (_, _) => ApplyDesktopLayout();
    }

    private void ApplyDesktopLayout()
    {
        if (!IsLoaded || PosView is null) return;

        var width = ActualWidth > 0 ? ActualWidth : Width;
        var height = ActualHeight > 0 ? ActualHeight : Height;
        var compact = width < 1420;
        var standard = width < 1680;

        // Shell: use more of the real monitor area instead of preserving web-like gutters.
        if (PosView.Parent is Grid contentGrid && contentGrid.Parent is Grid bodyGrid && bodyGrid.ColumnDefinitions.Count >= 2)
        {
            bodyGrid.ColumnDefinitions[0].Width = new GridLength(compact ? 184 : standard ? 208 : 228);
            contentGrid.Margin = compact
                ? new Thickness(12, 12, 12, 10)
                : standard
                    ? new Thickness(18, 16, 18, 14)
                    : new Thickness(24, 20, 24, 18);
        }

        UnitSelector.Width = compact ? 112 : standard ? 138 : 165;
        ModeSelector.Width = compact ? 96 : standard ? 110 : 125;

        // POS workspace adapts like a real desktop application.
        var workspace = PosView.Children.OfType<Grid>().FirstOrDefault(x => Grid.GetRow(x) == 1 && x.ColumnDefinitions.Count >= 3);
        if (workspace is not null)
        {
            workspace.ColumnDefinitions[0].Width = new GridLength(compact ? 1.08 : standard ? 1.20 : 1.25, GridUnitType.Star);
            workspace.ColumnDefinitions[1].Width = new GridLength(compact ? 10 : standard ? 14 : 18);
            workspace.ColumnDefinitions[2].Width = new GridLength(compact ? 0.92 : standard ? 0.86 : 0.82, GridUnitType.Star);
        }

        // 1366x768 is a very common POS resolution: preserve the useful columns
        // and remove metadata that otherwise squeezes product/customer text.
        if (ProductsGrid.Columns.Count >= 5)
        {
            ProductsGrid.Columns[1].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // SKU
            ProductsGrid.Columns[4].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // situação
            ProductsGrid.Columns[2].Width = compact ? new DataGridLength(86) : new DataGridLength(108);
            ProductsGrid.Columns[3].Width = compact ? new DataGridLength(72) : new DataGridLength(88);
        }

        // Vertical density follows the actual monitor/work area instead of a fixed web page height.
        CartGrid.Height = height < 740 ? 112 : height < 820 ? 142 : height < 900 ? 164 : 184;

        // Native Windows feel: operational surfaces are flatter and denser than website cards.
        ApplyOperationalCardDensity(PosView, compact ? 8 : 10);
        ApplyOperationalCardDensity(OrdersView, compact ? 8 : 10);
        ApplyOperationalCardDensity(TablesView, compact ? 8 : 10);
    }

    private static void ApplyOperationalCardDensity(DependencyObject root, double radius)
    {
        foreach (var border in Descendants<Border>(root))
        {
            if (border.Style == Application.Current.TryFindResource("Card") as Style)
                border.CornerRadius = new CornerRadius(radius);
        }
    }

    private static IEnumerable<T> Descendants<T>(DependencyObject root) where T : DependencyObject
    {
        var count = VisualTreeHelper.GetChildrenCount(root);
        for (var i = 0; i < count; i++)
        {
            var child = VisualTreeHelper.GetChild(root, i);
            if (child is T match) yield return match;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}
