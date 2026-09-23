using System.Windows;

namespace EventMenu.Desktop;

public partial class HardwareFiscalSettingsWindow
{
    public void UseFiscalOnlyMode()
    {
        Title = "Nota fiscal • EventMenu";
        SettingsHeadingText.Text = "Nota fiscal";
        HardwareTab.Visibility = Visibility.Collapsed;
        TefTab.Visibility = Visibility.Collapsed;
        FiscalTab.Visibility = Visibility.Visible;
        FiscalTab.Header = "Configuração";
        SettingsTabs.SelectedItem = FiscalTab;

        var unitOnly = UnitText.Text.Split(" • ", StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries);
        if (unitOnly.Length > 0) UnitText.Text = unitOnly[0];
    }
}
