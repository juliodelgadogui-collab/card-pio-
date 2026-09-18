using System.IO;
using System.Text.Json;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class LocalHardwareProfileStore
{
    private readonly string _path;
    private static readonly JsonSerializerOptions JsonOptions=new(){WriteIndented=true};

    public LocalHardwareProfileStore()
    {
        var directory=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"EventMenu","Desktop");
        Directory.CreateDirectory(directory);
        _path=Path.Combine(directory,"hardware.json");
    }

    public LocalHardwareProfile Load()
    {
        try
        {
            if(!File.Exists(_path))return NewProfile();
            var profile=JsonSerializer.Deserialize<LocalHardwareProfile>(File.ReadAllText(_path));
            if(profile is null)return NewProfile();
            profile.ComputerName=Environment.MachineName;
            profile.AppVersion=CurrentAppVersion();
            return profile;
        }
        catch{return NewProfile();}
    }

    public void Save(LocalHardwareProfile profile)
    {
        profile.ComputerName=Environment.MachineName;
        profile.AppVersion=CurrentAppVersion();
        profile.Printers=profile.Printers.Where(v=>!string.IsNullOrWhiteSpace(v)).Select(v=>v.Trim()).Distinct(StringComparer.OrdinalIgnoreCase).Take(30).ToList();
        File.WriteAllText(_path,JsonSerializer.Serialize(profile,JsonOptions));
    }

    public Dictionary<string,object?> ToServerReport(LocalHardwareProfile profile)=>new()
    {
        ["computer_name"]=Environment.MachineName,
        ["windows_version"]=Environment.OSVersion.VersionString,
        ["app_version"]=CurrentAppVersion(),
        ["default_printer"]=profile.DefaultPrinter,
        ["printers"]=profile.Printers,
        ["cash_drawer"]=profile.CashDrawer,
        ["scale"]=profile.Scale,
        ["barcode_scanner"]=profile.BarcodeScanner,
        ["customer_display"]=profile.CustomerDisplay,
        ["tef_provider"]=profile.TefProvider,
        ["pinpad"]=profile.Pinpad,
    };

    private static string CurrentAppVersion()=>typeof(LocalHardwareProfileStore).Assembly.GetName().Version?.ToString(3)??"0.3.0";

    private static LocalHardwareProfile NewProfile()=>new()
    {
        ComputerName=Environment.MachineName,
        AppVersion=CurrentAppVersion()
    };
}
