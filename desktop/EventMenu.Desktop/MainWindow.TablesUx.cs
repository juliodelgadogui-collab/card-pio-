using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop;

public partial class MainWindow
{
    private bool _tablesUxReady;
    private Button? _tableBoardButton;

    private void EnsureTablesUx()
    {
        if (_tablesUxReady) return;
        _tablesUxReady = true;

        var header = TablesView.Children.OfType<DockPanel>().FirstOrDefault(x => Grid.GetRow(x) == 0);
        if (header is not null)
        {
            header.LastChildFill = false;
            _tableBoardButton = new Button
            {
                Content = "Visão do salão",
                Height = 40,
                MinHeight = 40,
                Padding = new Thickness(14, 8, 14, 8),
                Margin = new Thickness(0, 0, 8, 0),
                Style = TryFindResource("SecondaryButton") as Style,
                ToolTip = "Ver mesas, comandas e valores em aberto"
            };
            DockPanel.SetDock(_tableBoardButton, Dock.Right);
            _tableBoardButton.Click += TableBoardButton_Click;
            header.Children.Add(_tableBoardButton);
        }

        TablesGrid.LoadingRow += TablesGrid_LoadingRow;
    }

    private void TablesGrid_LoadingRow(object? sender, DataGridRowEventArgs e)
    {
        if (e.Row.Item is not TableInfo table) return;
        e.Row.Opacity = table.Status == "inactive" ? 0.55 : 1;
        e.Row.ToolTip = table.TabId is > 0
            ? $"{table.Name} • comanda aberta • {table.UnpaidDisplay} em aberto"
            : $"{table.Name} • livre para atendimento";
    }

    private async void TableBoardButton_Click(object sender, RoutedEventArgs e)
    {
        if (_api is null || _store is null || !Can("tables")) return;
        if (!ShiftIs("operation"))
        {
            MessageBox.Show("Inicie um turno de Operação para abrir a visão do salão.", "Mesas e comandas", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        var window = new TableBoardWindow(_api, _store, Can("payments"), Can("cash")) { Owner = this };
        window.ShowDialog();
        if (window.Changed)
        {
            await TryLoadTablesAsync(false);
            await TryLoadOrdersAsync(false);
            if (Can("cash")) await TryLoadCashAsync(false);
            RefreshDashboardUx();
        }
    }

    private void DisposeTablesUx()
    {
        if (!_tablesUxReady) return;
        TablesGrid.LoadingRow -= TablesGrid_LoadingRow;
        if (_tableBoardButton is not null) _tableBoardButton.Click -= TableBoardButton_Click;
        _tableBoardButton = null;
        _tablesUxReady = false;
    }
}
