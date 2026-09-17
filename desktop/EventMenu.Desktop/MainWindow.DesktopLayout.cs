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
        var compact = width < 1240;
        var standard = width < 1500;

        // Shell: use more of the real monitor area instead of preserving web-like gutters.
        if (PosView.Parent is Grid contentGrid && contentGrid.Parent is Grid bodyGrid && bodyGrid.ColumnDefinitions.Count >= 2)
        {
            bodyGrid.ColumnDefinitions[0].Width = new GridLength(compact ? 184 : standard ? 208 : 228);
            contentGrid.Margin = compact
                ? new Thickness(14, 14, 14, 12)
                : standard
                    ? new Thickness(20, 18, 20, 16)
                    : new Thickness(26, 22, 26, 20);
        }

        UnitSelector.Width = compact ? 112 : standard ? 138 : 165;
        ModeSelector.Width = compact ? 96 : standard ? 110 : 125;

        // POS workspace adapts like a real desktop application.
        var workspace = PosView.Children.OfType<Grid>().FirstOrDefault(x => Grid.GetRow(x) == 1 && x.ColumnDefinitions.Count >= 3);
        if (workspace is not null)
        {
            workspace.ColumnDefinitions[0].Width = new GridLength(compact ? 1.05 : 1.25, GridUnitType.Star);
            workspace.ColumnDefinitions[1].Width = new GridLength(compact ? 10 : standard ? 14 : 18);
            workspace.ColumnDefinitions[2].Width = new GridLength(compact ? 0.95 : 0.82, GridUnitType.Star);
        }

        // On common 1366x768 terminals, keep the useful columns and stop squeezing text.
        if (ProductsGrid.Columns.Count >= 5)
        {
            ProductsGrid.Columns[1].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // SKU
            ProductsGrid.Columns[4].Visibility = compact ? Visibility.Collapsed : Visibility.Visible; // situação
            ProductsGrid.Columns[2].Width = compact ? new DataGridLength(88) : new DataGridLength(110);
            ProductsGrid.Columns[3].Width = compact ? new DataGridLength(76) : new DataGridLength(90);
        }

        // Vertical density follows the available monitor height.
        CartGrid.Height = height < 760 ? 128 : height < 860 ? 158 : 180;

        // Native Windows feel: flatter operational cards and less oversized web spacing.
        ApplyOperationalCardDensity(PosView, compact ? 10 : 12);
        ApplyOperationalCardDensity(OrdersView, compact ? 9 : 11);
        ApplyOperationalCardDensity(TablesView, compact ? 9 : 11);
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
