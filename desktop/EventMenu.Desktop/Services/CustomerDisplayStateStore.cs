using System.IO;
using System.Text.Json;

namespace EventMenu.Desktop.Services;

public sealed class CustomerDisplayStateStore
{
    private readonly string _path;
    private static readonly JsonSerializerOptions JsonOptions=new(){WriteIndented=false};

    public CustomerDisplayStateStore()
    {
        var directory=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"EventMenu","Desktop");
        Directory.CreateDirectory(directory);
        _path=Path.Combine(directory,"customer-display.json");
    }

    public void ShowOrder(int orderId,string customer,int totalCents,string status)
    {
        var state=new
        {
            order_id=orderId,
            customer,
            total_cents=totalCents,
            status,
            updated_at=DateTimeOffset.Now,
        };
        File.WriteAllText(_path,JsonSerializer.Serialize(state,JsonOptions));
    }

    public void ShowMessage(string message)
    {
        File.WriteAllText(_path,JsonSerializer.Serialize(new{message=message.Trim(),updated_at=DateTimeOffset.Now},JsonOptions));
    }

    public void Clear()
    {
        try{if(File.Exists(_path))File.Delete(_path);}catch{}
    }
}
