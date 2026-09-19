using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Media;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public static class ReceiptPrinter
{
    private static readonly CultureInfo PtBr = new("pt-BR");

    public static bool PrintOrder(string tenantName, Order order, IReadOnlyCollection<OrderItem> items)
    {
        var dialog = new PrintDialog();
        if (dialog.ShowDialog() != true) return false;

        var document = new FlowDocument
        {
            PagePadding = new Thickness(18),
            ColumnGap = 0,
            FontFamily = new FontFamily("Segoe UI"),
            FontSize = 11,
            PageWidth = Math.Min(dialog.PrintableAreaWidth, 320),
            PageHeight = dialog.PrintableAreaHeight
        };

        document.Blocks.Add(new Paragraph(new Bold(new Run(string.IsNullOrWhiteSpace(tenantName) ? "EventMenu" : tenantName)))
        {
            FontSize = 16,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 0, 0, 4)
        });
        document.Blocks.Add(new Paragraph(new Run($"PEDIDO #{order.Id}"))
        {
            FontSize = 14,
            FontWeight = FontWeights.SemiBold,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 0, 0, 12)
        });

        AddLine(document, $"Canal: {ChannelLabel(order.Channel)}");
        if (!string.IsNullOrWhiteSpace(order.CustomerName)) AddLine(document, $"Cliente: {order.CustomerName}");
        if (!string.IsNullOrWhiteSpace(order.CustomerPhone)) AddLine(document, $"Telefone: {order.CustomerPhone}");
        AddLine(document, $"Data: {order.CreatedAt}");
        document.Blocks.Add(new Paragraph(new Run(new string('-', 38))) { Margin = new Thickness(0, 6, 0, 6) });

        foreach (var item in items)
        {
            var qty = item.Quantity.ToString("0.##", PtBr);
            var row = new Paragraph { Margin = new Thickness(0, 2, 0, 2) };
            row.Inlines.Add(new Bold(new Run($"{qty}x {item.Name}")));
            row.Inlines.Add(new LineBreak());
            row.Inlines.Add(new Run(item.TotalDisplay));
            if (!string.IsNullOrWhiteSpace(item.Notes))
            {
                row.Inlines.Add(new LineBreak());
                row.Inlines.Add(new Italic(new Run(item.Notes)));
            }
            document.Blocks.Add(row);
        }

        document.Blocks.Add(new Paragraph(new Run(new string('-', 38))) { Margin = new Thickness(0, 6, 0, 6) });
        document.Blocks.Add(new Paragraph(new Bold(new Run($"TOTAL: {Money(order.TotalCents)}")))
        {
            FontSize = 15,
            TextAlignment = TextAlignment.Right,
            Margin = new Thickness(0, 4, 0, 0)
        });
        document.Blocks.Add(new Paragraph(new Run("Impresso pelo EventMenu Desktop"))
        {
            FontSize = 9,
            Foreground = Brushes.DimGray,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 14, 0, 0)
        });

        var paginator = ((IDocumentPaginatorSource)document).DocumentPaginator;
        dialog.PrintDocument(paginator, $"EventMenu - Pedido {order.Id}");
        return true;
    }

    private static void AddLine(FlowDocument document, string text) =>
        document.Blocks.Add(new Paragraph(new Run(text)) { Margin = new Thickness(0, 1, 0, 1) });

    private static string Money(int cents) => (cents / 100m).ToString("C2", PtBr);

    private static string ChannelLabel(string channel) => channel switch
    {
        "counter" => "Balcão",
        "pickup" => "Retirada",
        "delivery" => "Delivery",
        "table" => "Mesa",
        "bar" => "Bar",
        _ => channel
    };
}
