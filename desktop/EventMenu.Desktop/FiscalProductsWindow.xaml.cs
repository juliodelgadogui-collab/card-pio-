using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class FiscalProductsWindow : Window
{
    private readonly DesktopIntegrationApiClient _api;
    private readonly List<FiscalProduct> _products = new();
    private FiscalProduct? _selected;
    private bool _loading;
    private static readonly CultureInfo PtBr = new("pt-BR");

    public FiscalProductsWindow(DesktopIntegrationApiClient api)
    {
        InitializeComponent();
        _api = api;
        Loaded += async (_, _) => await LoadProductsAsync();
    }

    private async Task LoadProductsAsync(int? selectProductId = null)
    {
        if (_loading) return;
        try
        {
            SetBusy(true, "Carregando produtos...");
            var response = await _api.FiscalProductsAsync();
            _products.Clear();
            _products.AddRange(response.Products);
            ApplyFilter();

            var targetId = selectProductId ?? _selected?.ProductId;
            var target = targetId.HasValue ? _products.FirstOrDefault(p => p.ProductId == targetId.Value) : null;
            ProductsGrid.SelectedItem = target ?? _products.FirstOrDefault();
            UpdateSummary();
            StatusText.Text = "";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private void ApplyFilter()
    {
        var term = SearchBox.Text.Trim();
        IEnumerable<FiscalProduct> source = _products;
        if (term.Length > 0)
        {
            source = source.Where(p =>
                Contains(p.Name, term) || Contains(p.Sku, term) ||
                Contains(p.Ncm, term) || Contains(p.Cfop, term));
        }
        ProductsGrid.ItemsSource = source.ToList();
        UpdateSummary();
    }

    private void UpdateSummary()
    {
        var total = _products.Count;
        var ready = _products.Count(p => p.Active == 1 && p.Ready);
        var missing = _products.Count(p => p.Active == 1 && !p.Ready);
        ListSummaryText.Text = $"{total} produtos • {ready} prontos • {missing} incompletos";
    }

    private void ProductsGrid_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        _selected = ProductsGrid.SelectedItem as FiscalProduct;
        LoadEditor(_selected);
    }

    private void LoadEditor(FiscalProduct? p)
    {
        var enabled = p is not null;
        SaveButton.IsEnabled = enabled && !_loading;
        if (p is null)
        {
            SelectedProductNameText.Text = "Selecione um produto";
            SelectedProductStatusText.Text = "";
            ClearEditor();
            return;
        }

        SelectedProductNameText.Text = p.Name;
        SelectedProductStatusText.Text = $"SKU: {(string.IsNullOrWhiteSpace(p.Sku) ? "—" : p.Sku)} • {p.ReadinessLabel}";
        FiscalProductEnabledCheck.IsChecked = p.FiscalEnabled is null || p.FiscalEnabled == 1;
        NcmBox.Text = p.Ncm ?? "";
        CestBox.Text = p.Cest ?? "";
        CfopBox.Text = p.Cfop ?? "";
        CommercialUnitBox.Text = string.IsNullOrWhiteSpace(p.CommercialUnit) ? "UN" : p.CommercialUnit;
        TributaryUnitBox.Text = string.IsNullOrWhiteSpace(p.TributaryUnit) ? "UN" : p.TributaryUnit;
        OriginBox.Text = string.IsNullOrWhiteSpace(p.Origin) ? "0" : p.Origin;
        GtinBox.Text = p.Gtin ?? "";
        GtinTributaryBox.Text = p.GtinTributary ?? "";
        IcmsCstBox.Text = p.IcmsCst ?? "";
        IcmsCsosnBox.Text = p.IcmsCsosn ?? "";
        IcmsRateBox.Text = FormatRate(p.IcmsRate);
        PisCstBox.Text = p.PisCst ?? "";
        PisRateBox.Text = FormatRate(p.PisRate);
        CofinsCstBox.Text = p.CofinsCst ?? "";
        CofinsRateBox.Text = FormatRate(p.CofinsRate);
        IpiCstBox.Text = p.IpiCst ?? "";
        IpiRateBox.Text = FormatRate(p.IpiRate);
        BenefitCodeBox.Text = p.BenefitCode ?? "";
    }

    private void ClearEditor()
    {
        foreach (var box in new[] { NcmBox, CestBox, CfopBox, CommercialUnitBox, TributaryUnitBox, OriginBox, GtinBox, GtinTributaryBox,
                     IcmsCstBox, IcmsCsosnBox, IcmsRateBox, PisCstBox, PisRateBox, CofinsCstBox, CofinsRateBox, IpiCstBox, IpiRateBox, BenefitCodeBox })
            box.Text = "";
        FiscalProductEnabledCheck.IsChecked = true;
    }

    private async void SaveButton_Click(object sender, RoutedEventArgs e)
    {
        if (_selected is null) return;
        try
        {
            var ncm = Digits(NcmBox.Text, 8);
            var cfop = Digits(CfopBox.Text, 4);
            var origin = Digits(OriginBox.Text, 1);
            var commercialUnit = CommercialUnitBox.Text.Trim().ToUpperInvariant();
            var tributaryUnit = TributaryUnitBox.Text.Trim().ToUpperInvariant();
            var icmsCst = Digits(IcmsCstBox.Text, 3);
            var icmsCsosn = Digits(IcmsCsosnBox.Text, 3);
            var pisCst = Digits(PisCstBox.Text, 2);
            var cofinsCst = Digits(CofinsCstBox.Text, 2);

            if (FiscalProductEnabledCheck.IsChecked == true)
            {
                if (ncm.Length != 8) throw new InvalidOperationException("Informe o NCM com 8 dígitos.");
                if (cfop.Length != 4) throw new InvalidOperationException("Informe o CFOP com 4 dígitos.");
                if (origin.Length != 1 || origin[0] < '0' || origin[0] > '8') throw new InvalidOperationException("A origem deve estar entre 0 e 8.");
                if (commercialUnit.Length == 0 || tributaryUnit.Length == 0) throw new InvalidOperationException("Informe as unidades comercial e tributável.");
                if (icmsCst.Length == 0 && icmsCsosn.Length == 0) throw new InvalidOperationException("Informe CST ou CSOSN do ICMS.");
                if (pisCst.Length != 2 || cofinsCst.Length != 2) throw new InvalidOperationException("Informe CST de PIS e COFINS.");
            }

            var payload = new
            {
                product_id = _selected.ProductId,
                enabled = FiscalProductEnabledCheck.IsChecked == true,
                ncm,
                cest = Digits(CestBox.Text, 7),
                cfop,
                commercial_unit = commercialUnit,
                tributary_unit = tributaryUnit,
                origin,
                gtin = Digits(GtinBox.Text, 14),
                gtin_tributary = Digits(GtinTributaryBox.Text, 14),
                icms_cst = icmsCst,
                icms_csosn = icmsCsosn,
                icms_rate = ParseRate(IcmsRateBox.Text),
                pis_cst = pisCst,
                pis_rate = ParseRate(PisRateBox.Text),
                cofins_cst = cofinsCst,
                cofins_rate = ParseRate(CofinsRateBox.Text),
                ipi_cst = Digits(IpiCstBox.Text, 2),
                ipi_rate = ParseRate(IpiRateBox.Text),
                benefit_code = BenefitCodeBox.Text.Trim()
            };

            SetBusy(true, "Salvando tributação...");
            var saved = await _api.SaveFiscalProductAsync(payload);
            var productId = saved.Product?.ProductId ?? _selected.ProductId;
            await LoadProductsAsync(productId);
            StatusText.Text = saved.Product?.Ready == true
                ? "Tributação salva. Produto pronto para compor o documento fiscal."
                : "Tributação salva, mas o produto ainda está incompleto.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async void RefreshButton_Click(object sender, RoutedEventArgs e) => await LoadProductsAsync();
    private void SearchBox_TextChanged(object sender, TextChangedEventArgs e) { if (ProductsGrid is not null) ApplyFilter(); }
    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private void SetBusy(bool busy, string? message = null)
    {
        _loading = busy;
        ProductsGrid.IsEnabled = !busy;
        SaveButton.IsEnabled = !busy && _selected is not null;
        if (!string.IsNullOrWhiteSpace(message)) StatusText.Text = message;
    }

    private static bool Contains(string? value, string term) => value?.Contains(term, StringComparison.OrdinalIgnoreCase) == true;
    private static string Digits(string value, int max) => new(value.Where(char.IsDigit).Take(max).ToArray());
    private static string FormatRate(decimal? value) => value?.ToString("0.####", PtBr) ?? "";

    private static decimal? ParseRate(string raw)
    {
        raw = raw.Trim();
        if (raw.Length == 0) return null;
        if (decimal.TryParse(raw, NumberStyles.Number, PtBr, out var pt)) return ValidateRate(pt);
        if (decimal.TryParse(raw, NumberStyles.Number, CultureInfo.InvariantCulture, out var inv)) return ValidateRate(inv);
        throw new InvalidOperationException($"Alíquota inválida: {raw}");
    }

    private static decimal ValidateRate(decimal value)
    {
        if (value < 0 || value > 100) throw new InvalidOperationException("A alíquota deve estar entre 0 e 100%.");
        return decimal.Round(value, 4);
    }

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("stack trace") || lower.Contains("exception")
            ? "Não foi possível salvar a tributação do produto."
            : message;
    }
}
