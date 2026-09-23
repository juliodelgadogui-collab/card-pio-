using System.Globalization;
using System.IO.Ports;
using System.Text.RegularExpressions;

namespace EventMenu.Desktop.Services;

public sealed record ScaleReading(int WeightGrams,bool Stable,string Device);

public sealed class ScaleReaderService
{
    public async Task<ScaleReading> ReadAsync(string configuration,CancellationToken ct=default)
    {
        var raw=(configuration??"").Trim();
        if(string.IsNullOrWhiteSpace(raw))throw new InvalidOperationException("Configure a balança no EventMenu Desktop.");
        var parts=raw.Split('|',StringSplitOptions.TrimEntries|StringSplitOptions.RemoveEmptyEntries);
        var portName=parts[0];
        if(!Regex.IsMatch(portName,"^COM[0-9]{1,3}$",RegexOptions.IgnoreCase))throw new InvalidOperationException("A balança precisa estar configurada como COM, por exemplo COM3|9600.");
        var baud=9600;if(parts.Length>1&&!int.TryParse(parts[1],out baud))throw new InvalidOperationException("Velocidade serial da balança inválida.");
        if(baud is <1200 or >115200)throw new InvalidOperationException("Velocidade serial da balança fora do intervalo suportado.");
        using var port=new SerialPort(portName.ToUpperInvariant(),baud,Parity.None,8,StopBits.One){ReadTimeout=1800,WriteTimeout=1000,NewLine="\r\n",DtrEnable=true,RtsEnable=true};
        try{port.Open();}catch(Exception ex){throw new InvalidOperationException($"Não foi possível abrir a balança em {portName}.",ex);}
        var deadline=DateTime.UtcNow.AddSeconds(4);var samples=new List<int>();
        while(DateTime.UtcNow<deadline&&samples.Count<4){ct.ThrowIfCancellationRequested();string line;try{line=await Task.Run(port.ReadLine,ct);}catch(TimeoutException){continue;}var grams=ParseGrams(line);if(grams>=0)samples.Add(grams);}
        if(samples.Count==0)throw new InvalidOperationException("A balança não retornou uma leitura válida.");
        var last=samples[^1];var stable=samples.Count>=2&&samples.TakeLast(Math.Min(3,samples.Count)).All(v=>Math.Abs(v-last)<=2);
        return new ScaleReading(last,stable,$"{portName.ToUpperInvariant()} @ {baud}");
    }

    private static int ParseGrams(string input)
    {
        var text=(input??"").Trim().ToUpperInvariant();if(text.Length==0)return-1;
        var match=Regex.Match(text,@"[-+]?\s*(\d+(?:[.,]\d+)?)\s*(KG|G)?");if(!match.Success)return-1;
        if(!decimal.TryParse(match.Groups[1].Value.Replace(',','.'),NumberStyles.Number,CultureInfo.InvariantCulture,out var value)||value<0)return-1;
        var unit=match.Groups[2].Value;var grams=unit=="G"?value:value*1000m;
        return grams>2_000_000m?-1:(int)Math.Round(grams,MidpointRounding.AwayFromZero);
    }
}
