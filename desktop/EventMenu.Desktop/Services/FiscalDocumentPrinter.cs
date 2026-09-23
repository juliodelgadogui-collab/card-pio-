using System.Globalization;
using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using EventMenu.Desktop.Models;
using QRCoder;

namespace EventMenu.Desktop.Services;

public sealed class FiscalDocumentPrinter
{
    private static readonly CultureInfo PtBr=new("pt-BR");

    public void Print(FiscalDanfeData data,Window owner)
    {
        if(data.Document.Model!="65")throw new InvalidOperationException("O DANFE completo da NF-e modelo 55 será fornecido pelo renderer do transmissor fiscal homologado. O EventMenu não imprime um modelo improvisado.");
        var dialog=new PrintDialog();if(dialog.ShowDialog()!=true)return;
        var document=BuildNfce(data);document.PageWidth=dialog.PrintableAreaWidth;document.PagePadding=new Thickness(12);document.ColumnWidth=double.PositiveInfinity;
        dialog.PrintDocument(((IDocumentPaginatorSource)document).DocumentPaginator,$"EventMenu NFC-e {data.Document.Number}");
    }

    private static FlowDocument BuildNfce(FiscalDanfeData data)
    {
        var doc=new FlowDocument{FontFamily=new FontFamily("Arial"),FontSize=10,LineHeight=13};
        var issuerName=Value(data.Issuer,"trade_name");if(string.IsNullOrWhiteSpace(issuerName))issuerName=Value(data.Issuer,"legal_name");
        doc.Blocks.Add(Center(issuerName,14,FontWeights.Bold));
        doc.Blocks.Add(Center(Value(data.Issuer,"legal_name"),9,FontWeights.Normal));
        doc.Blocks.Add(Center($"CNPJ {MaskDocument(Value(data.Issuer,"cnpj"))} • IE {Value(data.Issuer,"state_registration")}",9,FontWeights.Normal));
        var address=$"{Value(data.Issuer,"street")}, {Value(data.Issuer,"number")} - {Value(data.Issuer,"district")} - {Value(data.Issuer,"city")}/{Value(data.Issuer,"state")}".Trim();
        doc.Blocks.Add(Center(address,9,FontWeights.Normal));
        doc.Blocks.Add(Line());
        doc.Blocks.Add(Center("DANFE NFC-e - Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica",10,FontWeights.Bold));
        if(data.Document.Environment.Equals("homologation",StringComparison.OrdinalIgnoreCase))doc.Blocks.Add(Center("AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL",10,FontWeights.Bold));
        if(!string.IsNullOrWhiteSpace(data.Warning))doc.Blocks.Add(Center(data.Warning!,10,FontWeights.Bold));
        doc.Blocks.Add(Line());

        foreach(var item in data.Items)
        {
            var p=new Paragraph{Margin=new Thickness(0,1,0,1)};
            p.Inlines.Add(new Run($"{item.Quantity:0.###} {item.Unit}  {item.Name}"){FontWeight=FontWeights.SemiBold});
            p.Inlines.Add(new LineBreak());
            p.Inlines.Add(new Run($"{Money(item.UnitPriceCents)}  →  {Money(item.TotalCents)}"));
            doc.Blocks.Add(p);
        }
        doc.Blocks.Add(Line());
        var total=Int(data.Order,"total_cents");var discount=Int(data.Order,"discount_cents");var delivery=Int(data.Order,"delivery_fee_cents");
        if(discount>0)doc.Blocks.Add(Right($"Desconto: -{Money(discount)}",9));
        if(delivery>0)doc.Blocks.Add(Right($"Entrega: {Money(delivery)}",9));
        doc.Blocks.Add(Right($"TOTAL: {Money(total)}",14,true));
        doc.Blocks.Add(Line());

        var recipient=Value(data.Recipient,"document");if(!string.IsNullOrWhiteSpace(recipient))doc.Blocks.Add(Center($"Consumidor: {MaskDocument(recipient)} {Value(data.Recipient,"name")}",9,FontWeights.Normal));
        doc.Blocks.Add(Center($"NFC-e nº {data.Document.Number} • série {data.Document.Series}",9,FontWeights.Bold));
        if(!string.IsNullOrWhiteSpace(data.Document.AccessKey))doc.Blocks.Add(Center(FormatKey(data.Document.AccessKey!),8,FontWeights.Normal));
        if(!string.IsNullOrWhiteSpace(data.Document.Protocol))doc.Blocks.Add(Center($"Protocolo: {data.Document.Protocol}",8,FontWeights.Normal));

        if(!string.IsNullOrWhiteSpace(data.QrCode))
        {
            var image=QrImage(data.QrCode!);if(image is not null){var ui=new Image{Source=image,Width=150,Height=150,Stretch=Stretch.Uniform,HorizontalAlignment=HorizontalAlignment.Center};doc.Blocks.Add(new BlockUIContainer(ui){TextAlignment=TextAlignment.Center,Margin=new Thickness(0,8,0,4)});}
            doc.Blocks.Add(Center("Consulte pelo QR Code",8,FontWeights.Normal));
        }
        return doc;
    }

    private static Paragraph Center(string text,double size,FontWeight weight)=>new(new Run(text??""){FontWeight=weight}){TextAlignment=TextAlignment.Center,FontSize=size,Margin=new Thickness(0,2,0,2)};
    private static Paragraph Right(string text,double size,bool bold=false)=>new(new Run(text){FontWeight=bold?FontWeights.Bold:FontWeights.Normal}){TextAlignment=TextAlignment.Right,FontSize=size,Margin=new Thickness(0,2,0,2)};
    private static Paragraph Line()=>Center(new string('-',42),8,FontWeights.Normal);
    private static string Money(int cents)=>(cents/100m).ToString("C2",PtBr);

    private static string Value(Dictionary<string,object?> values,string key)
    {
        if(!values.TryGetValue(key,out var raw)||raw is null)return"";if(raw is JsonElement e)return e.ValueKind==JsonValueKind.String?e.GetString()??"":e.ToString();return Convert.ToString(raw,CultureInfo.InvariantCulture)??"";
    }
    private static int Int(Dictionary<string,object?> values,string key)=>int.TryParse(Value(values,key),NumberStyles.Integer,CultureInfo.InvariantCulture,out var value)?value:0;
    private static string FormatKey(string value){var digits=new string(value.Where(char.IsDigit).ToArray());return string.Join(' ',Enumerable.Range(0,(digits.Length+3)/4).Select(i=>digits.Substring(i*4,Math.Min(4,digits.Length-i*4))));}
    private static string MaskDocument(string value){var d=new string(value.Where(char.IsDigit).ToArray());if(d.Length==14)return$"{d[..2]}.{d[2..5]}.{d[5..8]}/{d[8..12]}-{d[12..]}";if(d.Length==11)return$"{d[..3]}.{d[3..6]}.{d[6..9]}-{d[9..]}";return value;}
    private static BitmapImage? QrImage(string value)
    {
        try{using var generator=new QRCodeGenerator();using var data=generator.CreateQrCode(value,QRCodeGenerator.ECCLevel.M);var png=new PngByteQRCode(data).GetGraphic(7);using var stream=new MemoryStream(png);var image=new BitmapImage();image.BeginInit();image.CacheOption=BitmapCacheOption.OnLoad;image.StreamSource=stream;image.EndInit();image.Freeze();return image;}catch{return null;}
    }
}
