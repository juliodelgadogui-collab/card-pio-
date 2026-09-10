using System.Windows;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class FiscalCenterWindow : Window
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly LocalHardwareProfileStore _hardwareStore;
    private readonly int _tenantId;
    private readonly int _unitId;
    private readonly string _unitName;
    private readonly bool _canManage;
    private readonly bool _canIssue;
    private bool _loading;

    public FiscalCenterWindow(
        DesktopIntegrationApiClient api,
        LocalHardwareProfileStore hardwareStore,
        int tenantId,
        int unitId,
        string unitName,
        bool canManage,
        bool canIssue)
    {
        _api = api;
        _hardwareStore = hardwareStore;
        _tenantId = tenantId;
        _unitId = unitId;
        _unitName = string.IsNullOrWhiteSpace(unitName) ? $"Unidade #{unitId}" : unitName;
        _canManage = canManage;
        _canIssue = canIssue;
        InitializeComponent();
        UnitText.Text = $"Emissão fiscal • {_unitName}";
        ConfigureCard.Visibility = _canManage ? Visibility.Visible : Visibility.Collapsed;
        ProductsCard.Visibility = _canManage ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) => await RefreshAsync();
    }

    private async Task RefreshAsync()
    {
        if (_loading) return;
        _loading = true;
        StatusText.Text = "Atualizando situação fiscal...";
        try
        {
            if (!_canManage)
            {
                ReadinessTitleText.Text = "Documentos fiscais disponíveis";
                ReadinessDetailText.Text = "Seu acesso permite acompanhar as notas emitidas pela operação.";
                ReadinessBadgeText.Text = "Consulta";
                ReadinessIcon.Text = "NF";
                StatusText.Text = "Selecione “Ver notas fiscais” para acompanhar os documentos.";
                return;
            }

            var response = await _api.FiscalReadinessAsync(_unitId);
            var readiness = response.Readiness;
            if (readiness is null)
            {
                ReadinessTitleText.Text = "Configuração ainda não verificada";
                ReadinessDetailText.Text = "Abra a configuração de emissão e confira os dados da empresa.";
                ReadinessBadgeText.Text = "Verificar";
                ReadinessIcon.Text = "!";
                StatusText.Text = "A emissão permanece protegida até a configuração estar completa.";
                return;
            }

            if (readiness.Ready)
            {
                ReadinessTitleText.Text = "Configuração pronta para emissão";
                ReadinessDetailText.Text = "Dados da empresa, certificado e tributação dos produtos estão completos.";
                ReadinessBadgeText.Text = "Pronto";
                ReadinessIcon.Text = "✓";
                StatusText.Text = "A emissão pode ser iniciada a partir de um pedido apto para nota fiscal.";
                return;
            }

            var pending = new List<string>();
            if (!readiness.ProfileReady) pending.Add("dados da empresa");
            if (!readiness.CertificateReady) pending.Add("certificado digital");
            if (readiness.ProductsMissing.Count > 0) pending.Add($"tributação de {readiness.ProductsMissing.Count} produto(s)");

            ReadinessTitleText.Text = "Existem pendências para emitir";
            ReadinessDetailText.Text = pending.Count == 0
                ? "Revise a configuração fiscal antes de usar a emissão."
                : "Revise: " + string.Join(", ", pending) + ".";
            ReadinessBadgeText.Text = "Pendente";
            ReadinessIcon.Text = "!";
            StatusText.Text = "Corrija as pendências antes de colocar a emissão em produção.";
        }
        catch (Exception ex)
        {
            ReadinessTitleText.Text = "Não foi possível verificar agora";
            ReadinessDetailText.Text = "Você ainda pode abrir a configuração ou consultar documentos.";
            ReadinessBadgeText.Text = "Atenção";
            ReadinessIcon.Text = "!";
            StatusText.Text = ex.Message;
        }
        finally
        {
            _loading = false;
        }
    }

    private async void ConfigureButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canManage) return;
        var window = new HardwareFiscalSettingsWindow(_api, _hardwareStore, _tenantId, _unitId, _unitName, false, true)
        {
            Owner = this
        };
        window.UseFiscalOnlyMode();
        window.ShowDialog();
        await RefreshAsync();
    }

    private async void ProductsButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canManage) return;
        var window = new FiscalProductsWindow(_api) { Owner = this };
        window.ShowDialog();
        await RefreshAsync();
    }

    private void DocumentsButton_Click(object sender, RoutedEventArgs e)
    {
        if (!_canManage && !_canIssue) return;
        var window = new FiscalDocumentsWindow(_api) { Owner = this };
        window.ShowDialog();
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await RefreshAsync();
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
