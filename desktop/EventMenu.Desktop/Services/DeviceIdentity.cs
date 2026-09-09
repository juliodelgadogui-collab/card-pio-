using System.IO;

namespace EventMenu.Desktop.Services;

public static class DeviceIdentity
{
    private static readonly string AppDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "EventMenu",
        "Desktop");

    private static readonly string DeviceFile = Path.Combine(AppDirectory, "device.id");

    public static string GetOrCreate()
    {
        Directory.CreateDirectory(AppDirectory);
        if (File.Exists(DeviceFile))
        {
            var saved = File.ReadAllText(DeviceFile).Trim();
            if (saved.Length >= 8) return saved;
        }

        var id = $"desktop-{Guid.NewGuid():N}";
        File.WriteAllText(DeviceFile, id);
        return id;
    }
}
