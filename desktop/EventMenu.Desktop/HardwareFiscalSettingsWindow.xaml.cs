using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;
using Microsoft.Win32;

namespace EventMenu.Desktop;

public partial class HardwareFiscalSettingsWindow : Window
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly LocalHardwareProfileStore _hardwareStore;
    private readonly int _tenantId;
    private readonly int _unitId;
    private readonly bool _canHardware;
    private readonly bool _canFiscal;
    private List<PaymentTerminalConfig> _terminals=new();
    private FiscalProfile? _fiscalProfile;
    private string? _certificateFile;

    public HardwareFiscalSettingsWindow(
        DesktopIntegrationApiClient api,
        LocalHardwareProfileStore hardwareStore,
        int tenantId,
        int unitId,
        string unitName,
        bool canHardware,
        bool canFiscal)
    {
        _api=api;
        _hardwareStore=hardwareStore;
        _tenantId=tenantId;
        _unitId=unitId;
        _canHardware=canHardware;
        _canFiscal=canFiscal;
        InitializeComponent();
        UnitText.Text=$"Unidade: {(string.IsNullOrWhiteSpace(unitName)?$"#{unitId}":unitName)} • Computador: {Environment.MachineName}";

        ProviderCombo.ItemsSource=new[]
        {
            new Choice("generic_tef","Conector TEF local"),
            new Choice("pagbank_tef","PagBank / PlugPag ou TEF"),
            new Choice("stone_tef","Stone TEF"),
            new Choice("sitef","SiTef")
        };
        ProviderCombo.DisplayMemberPath=nameof(Choice.Label);ProviderCombo.SelectedValuePath=nameof(Choice.Value);ProviderCombo.SelectedIndex=0;
        IntegrationModeCombo.ItemsSource=new[]{new Choice("local_service","Conector local"),new Choice("dll","SDK / DLL"),new Choice("tcp","Serviço TCP"),new Choice("serial","Serial")};
        IntegrationModeCombo.DisplayMemberPath=nameof(Choice.Label);IntegrationModeCombo.SelectedValuePath=nameof(Choice.Value);IntegrationModeCombo.SelectedIndex=0;
        FiscalEnvironmentCombo.ItemsSource=new[]{new Choice("homologation","Homologação"),new Choice("production","Produção")};
        FiscalEnvironmentCombo.DisplayMemberPath=nameof(Choice.Label);FiscalEnvironmentCombo.SelectedValuePath=nameof(Choice.Value);FiscalEnvironmentCombo.SelectedIndex=0;
        DefaultDocumentCombo.ItemsSource=new[]{new Choice("nfce","NFC-e (65)"),new Choice("nfe","NF-e (55)")};
        DefaultDocumentCombo.DisplayMemberPath=nameof(Choice.Label);DefaultDocumentCombo.SelectedValuePath=nameof(Choice.Value);DefaultDocumentCombo.SelectedIndex=0;
        CertificateModeCombo.ItemsSource=new[]{new Choice("server","A1 no servidor"),new Choice("desktop","A1 somente neste Windows"),new Choice("hybrid","A1 servidor + Windows"),new Choice("a3_local","A3 local")};
        CertificateModeCombo.DisplayMemberPath=nameof(Choice.Label);CertificateModeCombo.SelectedValuePath=nameof(Choice.Value);CertificateModeCombo.SelectedIndex=0;

        HardwareTab.IsEnabled=_canHardware;
        TefTab.IsEnabled=_canHardware;
        FiscalTab.IsEnabled=_canFiscal;
        Loaded+=Window_Loaded;
    }

    private async void Window_Loaded(object sender,RoutedEventArgs e)
    {
        LoadHardware();
        try
        {
            if(_canHardware)await LoadTerminalsAsync();
            if(_canFiscal)
            {
                await LoadFiscalAsync();
                await LoadReadinessAsync(false);
            }
            StatusText.Text="Configurações carregadas.";
        }
        catch(Exception ex){SetError(ex.Message);}
    }

    private void LoadHardware()
    {
        var profile=_hardwareStore.Load();
        DefaultPrinterBox.Text=profile.DefaultPrinter;
        CashDrawerBox.Text=profile.CashDrawer;
        ScaleBox.Text=profile.Scale;
        BarcodeBox.Text=profile.BarcodeScanner;
        CustomerDisplayBox.Text=profile.CustomerDisplay;
        PinpadBox.Text=profile.Pinpad;
    }

    private async Task LoadTerminalsAsync()
    {
        var response=await _api.TerminalListAsync(_unitId);
        _terminals=response.Terminals;
        TerminalSelector.ItemsSource=null;
        TerminalSelector.ItemsSource=_terminals;
        TerminalSelector.SelectedIndex=_terminals.Count>0?0:-1;
        if(_terminals.Count==0)ClearTerminalForm();
    }

    private async Task LoadFiscalAsync()
    {
        var response=await _api.FiscalProfileAsync(_unitId);
        _fiscalProfile=response.Profile;
        if(_fiscalProfile is null)
        {
            NfceSeriesBox.Text="1";NfeSeriesBox.Text="1";CountryCodeBox.Text="1058";CountryNameBox.Text="Brasil";
            CertificateStatusText.Text="Nenhum perfil fiscal salvo para esta unidade.";
            FiscalReadinessText.Text="Salve o perfil fiscal para iniciar a validação.";
            return;
        }

        var p=_fiscalProfile;
        FiscalEnabledCheck.IsChecked=p.Enabled==1;
        ContingencyCheck.IsChecked=p.ContingencyEnabled==1;
        SelectChoice(FiscalEnvironmentCombo,p.Environment);
        SelectChoice(DefaultDocumentCombo,p.DefaultDocument);
        SelectChoice(CertificateModeCombo,p.CertificateMode);
        LegalNameBox.Text=p.LegalName??"";
        TradeNameBox.Text=p.TradeName??"";
        CnpjBox.Text=p.Cnpj;
        IeBox.Text=p.StateRegistration;
        MunicipalRegistrationBox.Text=p.MunicipalRegistration??"";
        CnaeBox.Text=p.Cnae??"";
        UfBox.Text=p.StateCode;
        CityCodeBox.Text=p.CityCode??"";
        TaxRegimeBox.Text=p.TaxRegime??"";
        FiscalPhoneBox.Text=p.Phone??"";
        FiscalEmailBox.Text=p.Email??"";
        CountryCodeBox.Text=string.IsNullOrWhiteSpace(p.CountryCode)?"1058":p.CountryCode;
        CountryNameBox.Text=string.IsNullOrWhiteSpace(p.CountryName)?"Brasil":p.CountryName;
        StreetBox.Text=p.Street??"";
        AddressNumberBox.Text=p.AddressNumber??"";
        AddressComplementBox.Text=p.AddressComplement??"";
        DistrictBox.Text=p.District??"";
        CityNameBox.Text=p.CityName??"";
        PostalCodeBox.Text=p.PostalCode??"";
        NfceSeriesBox.Text=p.NfceSeries.ToString();
        NfeSeriesBox.Text=p.NfeSeries.ToString();
        CscIdBox.Text=p.CscId??"";
        CertificateStatusText.Text=p.Certificate is null
            ?"Nenhum certificado registrado."
            :$"{p.Certificate.CertificateType.ToUpperInvariant()} • {p.Certificate.Status} • válido até {FormatDate(p.Certificate.ValidUntil)} • {p.Certificate.StorageScope}";
    }

    private async Task LoadReadinessAsync(bool announce)
    {
        if(!_canFiscal)return;
        try
        {
            var response=await _api.FiscalReadinessAsync(_unitId);
            var r=response.Readiness;
            if(r is null){FiscalReadinessText.Text="Não foi possível determinar a prontidão fiscal.";return;}
            if(r.Ready)
            {
                FiscalReadinessText.Text="✓ Perfil, certificado e tributação dos produtos estão completos para preparar a emissão.";
                FiscalReadinessText.Foreground=System.Windows.Media.Brushes.SeaGreen;
                if(announce)SetSuccess("Configuração fiscal pronta para a etapa de transmissão/homologação.");
                return;
            }

            var parts=new List<string>();
            if(!r.ProfileReady)parts.Add("perfil do emitente incompleto");
            if(!r.CertificateReady)parts.Add("certificado ausente/inválido");
            if(r.ProductsMissing.Count>0)
            {
                var names=string.Join(", ",r.ProductsMissing.Take(4).Select(p=>p.Name));
                var extra=r.ProductsMissing.Count>4?$" +{r.ProductsMissing.Count-4}":"";
                parts.Add($"{r.ProductsMissing.Count} produto(s) sem tributação completa: {names}{extra}");
            }
            FiscalReadinessText.Text="Pendente: "+string.Join(" • ",parts);
            FiscalReadinessText.Foreground=System.Windows.Media.Brushes.DarkOrange;
            if(announce)SetError("A configuração fiscal ainda possui pendências. Veja o diagnóstico na aba Fiscal.");
        }
        catch(Exception ex)
        {
            FiscalReadinessText.Text=Friendly(ex.Message);
            FiscalReadinessText.Foreground=System.Windows.Media.Brushes.Firebrick;
            if(announce)SetError(ex.Message);
        }
    }

    private async void SaveHardwareButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canHardware)return;
        try
        {
            var profile=_hardwareStore.Load();
            profile.DefaultPrinter=DefaultPrinterBox.Text.Trim();profile.CashDrawer=CashDrawerBox.Text.Trim();profile.Scale=ScaleBox.Text.Trim();
            profile.BarcodeScanner=BarcodeBox.Text.Trim();profile.CustomerDisplay=CustomerDisplayBox.Text.Trim();profile.Pinpad=PinpadBox.Text.Trim();
            profile.Printers=new[]{profile.DefaultPrinter,profile.CashDrawer}.Where(v=>!string.IsNullOrWhiteSpace(v)).Distinct(StringComparer.OrdinalIgnoreCase).ToList();
            profile.TefProvider=ChoiceValue(ProviderCombo);
            _hardwareStore.Save(profile);
            await _api.HardwareHeartbeatAsync(_unitId,_hardwareStore,profile);
            SetSuccess("Equipamentos salvos e sincronizados com o Hub.");
        }
        catch(Exception ex){SetError(ex.Message);}
    }

    private void TerminalSelector_SelectionChanged(object sender,SelectionChangedEventArgs e)
    {
        if(TerminalSelector.SelectedItem is not PaymentTerminalConfig terminal)return;
        SelectChoice(ProviderCombo,terminal.Provider);SelectChoice(IntegrationModeCombo,terminal.IntegrationMode);
        TerminalLabelBox.Text=terminal.TerminalLabel??"";TerminalPinpadBox.Text=terminal.PinpadIdentifier??"";
        TerminalEnabledCheck.IsChecked=terminal.Enabled==1;AutoCaptureCheck.IsChecked=terminal.AutoCapture==1;
        BridgeUrlBox.Text=RuntimeString(terminal.RuntimeConfig,"bridge_url");
    }

    private void NewTerminalButton_Click(object sender,RoutedEventArgs e)
    {
        TerminalSelector.SelectedItem=null;ClearTerminalForm();TerminalLabelBox.Focus();
    }

    private void ClearTerminalForm()
    {
        ProviderCombo.SelectedIndex=0;IntegrationModeCombo.SelectedIndex=0;TerminalLabelBox.Clear();TerminalPinpadBox.Clear();BridgeUrlBox.Text="http://127.0.0.1:18765/";
        TerminalEnabledCheck.IsChecked=true;AutoCaptureCheck.IsChecked=true;
    }

    private async void SaveTerminalButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canHardware)return;
        try
        {
            var id=(TerminalSelector.SelectedItem as PaymentTerminalConfig)?.Id??0;
            var provider=ChoiceValue(ProviderCombo);var mode=ChoiceValue(IntegrationModeCombo);var bridge=BridgeUrlBox.Text.Trim();
            if(mode=="local_service"&&!string.IsNullOrWhiteSpace(bridge))ValidateLoopback(bridge);
            var config=new Dictionary<string,object?>();if(!string.IsNullOrWhiteSpace(bridge))config["bridge_url"]=bridge;
            await _api.SaveTerminalAsync(id,_unitId,provider,TerminalEnabledCheck.IsChecked==true,mode,TerminalLabelBox.Text.Trim(),TerminalPinpadBox.Text.Trim(),AutoCaptureCheck.IsChecked==true,config);
            await LoadTerminalsAsync();
            SetSuccess("Terminal TEF salvo. O servidor continuará sendo a autoridade sobre o pagamento.");
        }
        catch(Exception ex){SetError(ex.Message);}
    }

    private async void SaveFiscalButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canFiscal)return;
        try
        {
            var response=await _api.SaveFiscalProfileAsync(new
            {
                unit_id=_unitId,
                enabled=FiscalEnabledCheck.IsChecked==true,
                environment=ChoiceValue(FiscalEnvironmentCombo),
                default_document=ChoiceValue(DefaultDocumentCombo),
                legal_name=LegalNameBox.Text.Trim(),
                trade_name=TradeNameBox.Text.Trim(),
                cnpj=CnpjBox.Text.Trim(),
                state_registration=IeBox.Text.Trim(),
                state_code=UfBox.Text.Trim().ToUpperInvariant(),
                city_code=CityCodeBox.Text.Trim(),
                tax_regime=TaxRegimeBox.Text.Trim(),
                street=StreetBox.Text.Trim(),
                address_number=AddressNumberBox.Text.Trim(),
                address_complement=AddressComplementBox.Text.Trim(),
                district=DistrictBox.Text.Trim(),
                city_name=CityNameBox.Text.Trim(),
                postal_code=PostalCodeBox.Text.Trim(),
                phone=FiscalPhoneBox.Text.Trim(),
                email=FiscalEmailBox.Text.Trim(),
                municipal_registration=MunicipalRegistrationBox.Text.Trim(),
                cnae=CnaeBox.Text.Trim(),
                country_code=CountryCodeBox.Text.Trim(),
                country_name=CountryNameBox.Text.Trim(),
                nfce_series=PositiveInt(NfceSeriesBox.Text,1),
                nfe_series=PositiveInt(NfeSeriesBox.Text,1),
                csc_id=CscIdBox.Text.Trim(),
                csc_token=CscTokenBox.Password,
                certificate_mode=ChoiceValue(CertificateModeCombo),
                contingency_enabled=ContingencyCheck.IsChecked==true
            });
            _fiscalProfile=response.Profile;
            CscTokenBox.Clear();
            await LoadFiscalAsync();
            await LoadReadinessAsync(false);
            SetSuccess("Configuração fiscal salva. Mantenha em homologação até concluir a validação real.");
        }
        catch(Exception ex){SetError(ex.Message);}
    }

    private async void ProductTaxButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canFiscal)return;
        var window=new FiscalProductsWindow(_api){Owner=this};
        window.ShowDialog();
        await LoadReadinessAsync(false);
    }

    private async void CheckFiscalButton_Click(object sender,RoutedEventArgs e)=>await LoadReadinessAsync(true);

    private void SelectCertificateButton_Click(object sender,RoutedEventArgs e)
    {
        var dialog=new OpenFileDialog{Title="Selecionar certificado A1",Filter="Certificado A1 (*.pfx;*.p12)|*.pfx;*.p12|Todos os arquivos (*.*)|*.*",CheckFileExists=true,Multiselect=false};
        if(dialog.ShowDialog(this)==true){_certificateFile=dialog.FileName;CertificateFileText.Text=Path.GetFileName(dialog.FileName);}
    }

    private async void ImportCertificateButton_Click(object sender,RoutedEventArgs e)
    {
        if(!_canFiscal)return;
        try
        {
            if(_fiscalProfile is null||_fiscalProfile.Id<1)throw new InvalidOperationException("Salve a configuração fiscal antes de importar o certificado.");
            if(string.IsNullOrWhiteSpace(_certificateFile)||!File.Exists(_certificateFile))throw new InvalidOperationException("Escolha o arquivo PFX/P12.");
            if(string.IsNullOrWhiteSpace(CertificatePasswordBox.Password))throw new InvalidOperationException("Informe a senha do certificado.");
            var mode=ChoiceValue(CertificateModeCombo);
            if(mode=="a3_local")throw new InvalidOperationException("Para A3, selecione o certificado/token pelo módulo A3 local; não importe um PFX.");
            var pfx=await File.ReadAllBytesAsync(_certificateFile);
            try
            {
                var enrollment=new FiscalCertificateEnrollmentService(_api,new LocalFiscalCertificateVault());
                await enrollment.ImportA1Async(_tenantId,_fiscalProfile.Id,pfx,CertificatePasswordBox.Password,mode);
            }
            finally{Array.Clear(pfx,0,pfx.Length);CertificatePasswordBox.Clear();}
            _certificateFile=null;CertificateFileText.Text="";
            await LoadFiscalAsync();
            await LoadReadinessAsync(false);
            SetSuccess("Certificado A1 importado com proteção do servidor/Windows conforme o modo escolhido.");
        }
        catch(Exception ex){CertificatePasswordBox.Clear();SetError(ex.Message);}
    }

    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();

    private void SetSuccess(string message){StatusText.Foreground=System.Windows.Media.Brushes.SeaGreen;StatusText.Text=message;}
    private void SetError(string message){StatusText.Foreground=System.Windows.Media.Brushes.Firebrick;StatusText.Text=Friendly(message);}
    private static string Friendly(string message){var lower=message.ToLowerInvariant();return lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception")?"Não foi possível concluir a configuração.":message;}
    private static int PositiveInt(string text,int fallback)=>int.TryParse(text,out var value)&&value>0?value:fallback;
    private static string FormatDate(string? value)=>DateTimeOffset.TryParse(value,out var d)?d.ToLocalTime().ToString("dd/MM/yyyy"):"não informado";
    private static string ChoiceValue(ComboBox combo)=>(combo.SelectedItem as Choice)?.Value??"";
    private static void SelectChoice(ComboBox combo,string value){combo.SelectedItem=combo.Items.Cast<object>().OfType<Choice>().FirstOrDefault(x=>x.Value.Equals(value,StringComparison.OrdinalIgnoreCase))??combo.Items.Cast<object>().FirstOrDefault();}
    private static string RuntimeString(Dictionary<string,object?> values,string key){if(!values.TryGetValue(key,out var raw)||raw is null)return"";if(raw is JsonElement j)return j.ValueKind==JsonValueKind.String?j.GetString()??"":j.ToString();return Convert.ToString(raw)??"";}
    private static void ValidateLoopback(string raw){if(!raw.EndsWith('/'))raw+="/";if(!Uri.TryCreate(raw,UriKind.Absolute,out var uri))throw new InvalidOperationException("Endereço do conector TEF inválido.");var host=uri.Host.Trim('[',']');if(!host.Equals("localhost",StringComparison.OrdinalIgnoreCase)&&host!="127.0.0.1"&&host!="::1")throw new InvalidOperationException("O conector TEF precisa rodar neste próprio computador.");}

    private sealed record Choice(string Value,string Label);
}
