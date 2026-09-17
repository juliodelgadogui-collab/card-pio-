using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Media;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

/// <summary>
/// Thermal renderer intentionally follows app/routes/receipt.php so the website
/// and Windows terminal do not evolve as two different receipt designs.
/// </summary>
public static class SiteReceiptPrinter
{
    public static bool Print(OrderReceipt receipt)
    {
        var dialog = new PrintDialog();
        if (dialog.ShowDialog() != true) return false;

        var paperMm = receipt.Identity.PaperWidth == "58" ? 58d : 80d;
        var pageWidth = Math.Min(dialog.PrintableAreaWidth, Mm(paperMm));
        var document = Build(receipt, pageWidth);
        document.PageHeight = dialog.PrintableAreaHeight;
        dialog.PrintDocument(((IDocumentPaginatorSource)document).DocumentPaginator, $"EventMenu - Cupom {receipt.OrderId}");
        return true;
    }

    private static FlowDocument Build(OrderReceipt r, double width)
    {
        var d = new FlowDocument
        {
            PageWidth = width,
            PagePadding = new Thickness(Mm(2.5)),
            ColumnGap = 0,
            FontFamily = new FontFamily("Arial"),
            FontSize = 10.5,
            Foreground = Brushes.Black
        };

        var tradeName = string.IsNullOrWhiteSpace(r.Identity.TradeName) ? r.TenantName : r.Identity.TradeName;
        Center(d, string.IsNullOrWhiteSpace(tradeName) ? "EventMenu" : tradeName, 14, FontWeights.Bold);
        if (Has(r.Identity.LegalName) && !string.Equals(r.Identity.LegalName, tradeName, StringComparison.OrdinalIgnoreCase)) Center(d, r.Identity.LegalName, 10);
        if (Has(r.Identity.Document)) Center(d, $"CPF/CNPJ: {r.Identity.Document}", 10);
        if (Has(r.Identity.Address)) Center(d, r.Identity.Address, 9.5);
        if (Has(r.Identity.Phone)) Center(d, $"Tel/WhatsApp: {r.Identity.Phone}", 9.5);
        if (Has(r.Identity.Email)) Center(d, r.Identity.Email, 9.5);

        Divider(d);
        Center(d, "COMPROVANTE NÃO FISCAL", 10.5, FontWeights.Bold);
        Center(d, $"Documento operacional EventMenu · {r.ReceiptNumber}", 8.5);
        Divider(d);

        Pair(d, "Pedido", $"#{r.OrderId}", true);
        Pair(d, "Data/hora", r.CreatedDisplay);
        Pair(d, "Canal", Channel(r.Channel));
        if (Has(r.CustomerName)) Pair(d, "Cliente", r.CustomerName);
        if (Has(r.CustomerPhone)) Pair(d, "Telefone", r.CustomerPhone);
        if (Has(r.TableName)) Pair(d, "Mesa", r.TableName);
        if (Has(r.TabLabel)) Pair(d, "Comanda", r.TabLabel);
        if (Has(r.CreatedByName)) Pair(d, "Operador", r.CreatedByName);

        Divider(d);
        Line(d, "ITENS", FontWeights.Bold);
        foreach (var item in r.Items)
        {
            Pair(d, $"{item.Quantity}x {item.Name}", item.TotalDisplay, true);
            Line(d, $"{item.UnitPriceDisplay} un.", null, 8.5);
            if (Has(item.Notes)) Line(d, $"Obs.: {item.Notes}", null, 8.5);
        }

        Divider(d);
        Pair(d, "Subtotal", r.SubtotalDisplay);
        if (r.DiscountCents > 0) Pair(d, "Desconto", $"- {r.DiscountDisplay}");
        if (r.DeliveryFeeCents > 0) Pair(d, "Entrega", r.DeliveryFeeDisplay);
        Pair(d, "TOTAL", r.TotalDisplay, true, 13.5);

        Divider(d);
        Line(d, "PAGAMENTO", FontWeights.Bold);
        if (r.Payments.Count == 0)
        {
            Line(d, $"Pagamento: {r.PaymentStatus}");
        }
        else
        {
            foreach (var payment in r.Payments)
                Pair(d, $"{payment.MethodDisplay} · {payment.StatusDisplay}", payment.AmountDisplay);
        }

        var footer = Has(r.Identity.Footer) ? r.Identity.Footer : "Obrigado pela preferência!";
        d.Blocks.Add(new Paragraph(new Run(footer)) { TextAlignment = TextAlignment.Center, FontSize = 9, Margin = new Thickness(0, 10, 0, 3) });
        Center(d, "Este comprovante não substitui NFC-e, NF-e ou documento fiscal legal quando exigido.", 7.5);
        Center(d, $"Impresso em {DateTime.Now:dd/MM/yyyy HH:mm:ss} · EventMenu", 7.5);
        return d;
    }

    private static void Divider(FlowDocument d) =>
        d.Blocks.Add(new Paragraph(new Run(new string('-', 42))) { Margin = new Thickness(0, 5, 0, 5), FontSize = 8 });

    private static void Line(FlowDocument d, string text, FontWeight? weight = null, double size = 10.5)
    {
        var p = new Paragraph(new Run(text)) { Margin = new Thickness(0, 1, 0, 1), FontSize = size };
        if (weight.HasValue) p.FontWeight = weight.Value;
        d.Blocks.Add(p);
    }

    private static void Center(FlowDocument d, string text, double size, FontWeight? weight = null)
    {
        var p = new Paragraph(new Run(text)) { Margin = new Thickness(0, 1, 0, 1), FontSize = size, TextAlignment = TextAlignment.Center };
        if (weight.HasValue) p.FontWeight = weight.Value;
        d.Blocks.Add(p);
    }

    private static void Pair(FlowDocument d, string left, string right, bool bold = false, double size = 10.5)
    {
        var table = new Table { CellSpacing = 0, Margin = new Thickness(0, 1, 0, 1), FontSize = size };
        table.Columns.Add(new TableColumn { Width = new GridLength(1, GridUnitType.Star) });
        table.Columns.Add(new TableColumn { Width = GridLength.Auto });
        var row = new TableRow();
        var lp = new Paragraph(new Run(left)) { Margin = new Thickness(0) };
        var rp = new Paragraph(new Run(right)) { Margin = new Thickness(6, 0, 0, 0), TextAlignment = TextAlignment.Right };
        if (bold) { lp.FontWeight = FontWeights.Bold; rp.FontWeight = FontWeights.Bold; }
        row.Cells.Add(new TableCell(lp));
        row.Cells.Add(new TableCell(rp));
        var group = new TableRowGroup();
        group.Rows.Add(row);
        table.RowGroups.Add(group);
        d.Blocks.Add(table);
    }

    private static string Channel(string value) => value switch
    {
        "counter" => "Balcão / PDV",
        "pickup" => "Retirada",
        "delivery" => "Delivery",
        "table" => "Mesa / comanda",
        "event" => "Evento / Ingresso",
        "bar" or "event_bar" => "Evento / Bar",
        _ => value
    };

    private static double Mm(double value) => value * 96d / 25.4d;
    private static bool Has(string? value) => !string.IsNullOrWhiteSpace(value);
}
