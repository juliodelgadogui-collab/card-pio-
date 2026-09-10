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
        var document = NewDocument();
        AddHeader(document, string.IsNullOrWhiteSpace(tenantName) ? "EventMenu" : tenantName, $"PEDIDO #{order.Id}");
        AddLine(document, $"Canal: {ChannelLabel(order.Channel)}");
        if (!string.IsNullOrWhiteSpace(order.CustomerName)) AddLine(document, $"Cliente: {order.CustomerName}");
        if (!string.IsNullOrWhiteSpace(order.CustomerPhone)) AddLine(document, $"Telefone: {order.CustomerPhone}");
        AddLine(document, $"Data: {ServerTimeDisplay.Local(order.CreatedAt)}");
        AddDivider(document);

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

        AddDivider(document);
        AddTotal(document, $"TOTAL: {Money(order.TotalCents)}");
        AddFooter(document, "Pedido operacional • EventMenu");
        return Print(document, $"EventMenu - Pedido {order.Id}");
    }

    public static bool PrintReceipt(OrderReceipt receipt)
    {
        var document = NewDocument();
        var tradeName = string.IsNullOrWhiteSpace(receipt.Identity.TradeName) ? receipt.TenantName : receipt.Identity.TradeName;
        AddHeader(document, string.IsNullOrWhiteSpace(tradeName) ? "EventMenu" : tradeName, $"COMPROVANTE • PEDIDO #{receipt.OrderId}");

        var identityLine = JoinNonEmpty(" • ", receipt.Identity.Document, receipt.Identity.Phone);
        if (!string.IsNullOrWhiteSpace(identityLine)) AddCenteredLine(document, identityLine, 9);
        if (!string.IsNullOrWhiteSpace(receipt.Identity.Address)) AddCenteredLine(document, receipt.Identity.Address, 9);
        AddCenteredLine(document, "COMPROVANTE NÃO FISCAL", 9, FontWeights.SemiBold);
        AddDivider(document);

        AddLine(document, $"Número: {receipt.ReceiptNumber}");
        AddLine(document, $"Data: {receipt.CreatedDisplay}");
        AddLine(document, $"Canal: {ChannelLabel(receipt.Channel)}");
        if (!string.IsNullOrWhiteSpace(receipt.CustomerName)) AddLine(document, $"Cliente: {receipt.CustomerName}");
        if (!string.IsNullOrWhiteSpace(receipt.CustomerPhone)) AddLine(document, $"Telefone: {receipt.CustomerPhone}");
        var location = JoinNonEmpty(" • ", receipt.TableName, receipt.TabLabel);
        if (!string.IsNullOrWhiteSpace(location)) AddLine(document, $"Mesa/comanda: {location}");
        if (!string.IsNullOrWhiteSpace(receipt.CreatedByName)) AddLine(document, $"Atendimento: {receipt.CreatedByName}");
        AddDivider(document);

        foreach (var item in receipt.Items)
        {
            var row = new Paragraph { Margin = new Thickness(0, 2, 0, 3) };
            row.Inlines.Add(new Bold(new Run($"{item.Quantity}x {item.Name}")));
            row.Inlines.Add(new LineBreak());
            row.Inlines.Add(new Run($"{item.UnitPriceDisplay}  •  {item.TotalDisplay}"));
            if (!string.IsNullOrWhiteSpace(item.Notes))
            {
                row.Inlines.Add(new LineBreak());
                row.Inlines.Add(new Italic(new Run(item.Notes)));
            }
            document.Blocks.Add(row);
        }

        AddDivider(document);
        AddMoneyLine(document, "Subtotal", receipt.SubtotalDisplay);
        if (receipt.DiscountCents > 0) AddMoneyLine(document, "Desconto", $"- {receipt.DiscountDisplay}");
        if (receipt.DeliveryFeeCents > 0) AddMoneyLine(document, "Entrega", receipt.DeliveryFeeDisplay);
        AddTotal(document, $"TOTAL: {receipt.TotalDisplay}");
        AddMoneyLine(document, "Pago", receipt.PaidDisplay);
        if (receipt.RemainingCents > 0) AddMoneyLine(document, "Restante", receipt.RemainingDisplay, FontWeights.SemiBold);

        if (receipt.Payments.Count > 0)
        {
            AddDivider(document);
            AddLine(document, "PAGAMENTOS", FontWeights.SemiBold);
            foreach (var payment in receipt.Payments)
                AddLine(document, $"{payment.MethodDisplay}: {payment.AmountDisplay} • {payment.StatusDisplay}");
        }

        var footer = string.IsNullOrWhiteSpace(receipt.Identity.Footer) ? "Obrigado pela preferência!" : receipt.Identity.Footer;
        AddFooter(document, footer);
        AddCenteredLine(document, "Este documento é um comprovante de venda e não substitui documento fiscal.", 8);
        return Print(document, $"EventMenu - Comprovante {receipt.OrderId}");
    }

    public static bool PrintGroupReceipt(GroupReceipt receipt)
    {
        var document = NewDocument();
        AddHeader(document,
            string.IsNullOrWhiteSpace(receipt.TenantName) ? "EventMenu" : receipt.TenantName,
            "COMPROVANTE DA DIVISÃO");
        AddCenteredLine(document, "COMPROVANTE NÃO FISCAL", 9, FontWeights.SemiBold);
        AddDivider(document);

        AddLine(document, $"Número: {receipt.ReceiptNumber}");
        AddLine(document, $"Data: {receipt.DateDisplay}");
        var location = JoinNonEmpty(" • ", receipt.TableName, receipt.TabId.HasValue ? $"Comanda #{receipt.TabId.Value}" : "", receipt.TabLabel);
        if (!string.IsNullOrWhiteSpace(location)) AddLine(document, location);
        if (!string.IsNullOrWhiteSpace(receipt.OperatorName)) AddLine(document, $"Operador: {receipt.OperatorName}");
        AddLine(document, $"Divisão: {receipt.SplitDisplay}");
        AddLine(document, $"Forma: {receipt.MethodDisplay}");
        AddDivider(document);

        if (receipt.Items.Count > 0)
        {
            AddLine(document, "PRODUTOS DESTA PARTE", FontWeights.SemiBold);
            foreach (var item in receipt.Items)
                AddLine(document, $"{item.QuantityDisplay}x {item.Name} • Pedido #{item.OrderId} • {item.AmountDisplay}");
            AddDivider(document);
        }

        AddLine(document, "DISTRIBUIÇÃO ENTRE PEDIDOS", FontWeights.SemiBold);
        foreach (var allocation in receipt.Allocations)
            AddLine(document, $"Pedido #{allocation.OrderId}: {allocation.AmountDisplay} • {allocation.StatusDisplay}");

        AddDivider(document);
        AddTotal(document, $"VALOR DESTA PARTE: {receipt.AmountDisplay}");
        AddMoneyLine(document, "Confirmado", receipt.ConfirmedDisplay, FontWeights.SemiBold);
        AddLine(document, $"Situação: {receipt.StatusDisplay}");
        AddFooter(document, "Pagamento registrado na comanda pelo EventMenu.");
        AddCenteredLine(document, "Este comprovante não substitui documento fiscal.", 8);
        return Print(document, $"EventMenu - Divisão {receipt.GroupId}");
    }

    private static FlowDocument NewDocument() => new()
    {
        PagePadding = new Thickness(18),
        ColumnGap = 0,
        FontFamily = new FontFamily("Segoe UI"),
        FontSize = 11
    };

    private static bool Print(FlowDocument document, string description)
    {
        var dialog = new PrintDialog();
        if (dialog.ShowDialog() != true) return false;
        document.PageWidth = Math.Min(dialog.PrintableAreaWidth, 320);
        document.PageHeight = dialog.PrintableAreaHeight;
        var paginator = ((IDocumentPaginatorSource)document).DocumentPaginator;
        dialog.PrintDocument(paginator, description);
        return true;
    }

    private static void AddHeader(FlowDocument document, string title, string subtitle)
    {
        document.Blocks.Add(new Paragraph(new Bold(new Run(title)))
        {
            FontSize = 16,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 0, 0, 4)
        });
        document.Blocks.Add(new Paragraph(new Run(subtitle))
        {
            FontSize = 13,
            FontWeight = FontWeights.SemiBold,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 0, 0, 8)
        });
    }

    private static void AddDivider(FlowDocument document) =>
        document.Blocks.Add(new Paragraph(new Run(new string('-', 38))) { Margin = new Thickness(0, 6, 0, 6) });

    private static void AddTotal(FlowDocument document, string text) =>
        document.Blocks.Add(new Paragraph(new Bold(new Run(text)))
        {
            FontSize = 15,
            TextAlignment = TextAlignment.Right,
            Margin = new Thickness(0, 5, 0, 3)
        });

    private static void AddFooter(FlowDocument document, string text) =>
        document.Blocks.Add(new Paragraph(new Run(text))
        {
            FontSize = 9,
            TextAlignment = TextAlignment.Center,
            Margin = new Thickness(0, 14, 0, 2)
        });

    private static void AddLine(FlowDocument document, string text, FontWeight? weight = null)
    {
        var paragraph = new Paragraph(new Run(text)) { Margin = new Thickness(0, 1, 0, 1) };
        if (weight.HasValue) paragraph.FontWeight = weight.Value;
        document.Blocks.Add(paragraph);
    }

    private static void AddCenteredLine(FlowDocument document, string text, double fontSize, FontWeight? weight = null)
    {
        var paragraph = new Paragraph(new Run(text))
        {
            Margin = new Thickness(0, 1, 0, 1),
            TextAlignment = TextAlignment.Center,
            FontSize = fontSize
        };
        if (weight.HasValue) paragraph.FontWeight = weight.Value;
        document.Blocks.Add(paragraph);
    }

    private static void AddMoneyLine(FlowDocument document, string label, string value, FontWeight? weight = null)
    {
        var table = new Table { CellSpacing = 0, Margin = new Thickness(0, 1, 0, 1) };
        table.Columns.Add(new TableColumn { Width = new GridLength(1, GridUnitType.Star) });
        table.Columns.Add(new TableColumn { Width = GridLength.Auto });
        var group = new TableRowGroup();
        var row = new TableRow();
        var left = new TableCell(new Paragraph(new Run(label)) { Margin = new Thickness(0) });
        var rightParagraph = new Paragraph(new Run(value)) { Margin = new Thickness(0), TextAlignment = TextAlignment.Right };
        if (weight.HasValue)
        {
            left.FontWeight = weight.Value;
            rightParagraph.FontWeight = weight.Value;
        }
        row.Cells.Add(left);
        row.Cells.Add(new TableCell(rightParagraph));
        group.Rows.Add(row);
        table.RowGroups.Add(group);
        document.Blocks.Add(table);
    }

    private static string JoinNonEmpty(string separator, params string[] values) =>
        string.Join(separator, values.Where(x => !string.IsNullOrWhiteSpace(x)));

    private static string Money(int cents) => (cents / 100m).ToString("C2", PtBr);

    private static string ChannelLabel(string channel) => channel switch
    {
        "counter" => "Balcão",
        "pickup" => "Retirada",
        "delivery" => "Delivery",
        "table" => "Mesa",
        "bar" or "event_bar" => "Evento / Bar",
        _ => channel
    };
}
