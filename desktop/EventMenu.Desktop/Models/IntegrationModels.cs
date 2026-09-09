using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class DesktopIntegrationContext
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("permissions")] public Dictionary<string, bool> Permissions { get; set; } = new();
    [JsonPropertyName("permission_names")] public List<string> PermissionNames { get; set; } = new();
    [JsonPropertyName("units")] public List<OperatingUnit> Units { get; set; } = new();
}

public sealed class FiscalProfileResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("profile")] public FiscalProfile? Profile { get; set; }
}

public sealed class FiscalProfile
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("enabled")] public int Enabled { get; set; }
    [JsonPropertyName("environment")] public string Environment { get; set; } = "homologation";
    [JsonPropertyName("default_document")] public string DefaultDocument { get; set; } = "nfce";
    [JsonPropertyName("legal_name")] public string? LegalName { get; set; }
    [JsonPropertyName("trade_name")] public string? TradeName { get; set; }
    [JsonPropertyName("cnpj")] public string Cnpj { get; set; } = "";
    [JsonPropertyName("state_registration")] public string StateRegistration { get; set; } = "";
    [JsonPropertyName("state_code")] public string StateCode { get; set; } = "";
    [JsonPropertyName("city_code")] public string? CityCode { get; set; }
    [JsonPropertyName("tax_regime")] public string? TaxRegime { get; set; }
    [JsonPropertyName("nfce_series")] public int NfceSeries { get; set; } = 1;
    [JsonPropertyName("nfce_next_number")] public long NfceNextNumber { get; set; } = 1;
    [JsonPropertyName("nfe_series")] public int NfeSeries { get; set; } = 1;
    [JsonPropertyName("nfe_next_number")] public long NfeNextNumber { get; set; } = 1;
    [JsonPropertyName("csc_id")] public string? CscId { get; set; }
    [JsonPropertyName("has_csc")] public int HasCsc { get; set; }
    [JsonPropertyName("certificate_mode")] public string CertificateMode { get; set; } = "server";
    [JsonPropertyName("contingency_enabled")] public int ContingencyEnabled { get; set; }
    [JsonPropertyName("certificate")] public FiscalCertificateMetadata? Certificate { get; set; }
}

public sealed class FiscalCertificateMetadata
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("certificate_type")] public string CertificateType { get; set; } = "";
    [JsonPropertyName("storage_scope")] public string StorageScope { get; set; } = "";
    [JsonPropertyName("subject_name")] public string? SubjectName { get; set; }
    [JsonPropertyName("issuer_name")] public string? IssuerName { get; set; }
    [JsonPropertyName("serial_number")] public string? SerialNumber { get; set; }
    [JsonPropertyName("thumbprint")] public string? Thumbprint { get; set; }
    [JsonPropertyName("valid_from")] public string? ValidFrom { get; set; }
    [JsonPropertyName("valid_until")] public string? ValidUntil { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
}

public sealed class FiscalCertificateResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("certificate")] public FiscalCertificateMetadata? Certificate { get; set; }
}

public sealed class FiscalDocumentResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("document")] public FiscalDocument? Document { get; set; }
}

public sealed class FiscalDocumentsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("documents")] public List<FiscalDocument> Documents { get; set; } = new();
}

public sealed class FiscalDocument
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("order_id")] public int? OrderId { get; set; }
    [JsonPropertyName("model")] public string Model { get; set; } = "";
    [JsonPropertyName("environment")] public string Environment { get; set; } = "";
    [JsonPropertyName("series")] public int Series { get; set; }
    [JsonPropertyName("document_number")] public long DocumentNumber { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("access_key")] public string? AccessKey { get; set; }
    [JsonPropertyName("protocol")] public string? Protocol { get; set; }
    [JsonPropertyName("rejection_code")] public string? RejectionCode { get; set; }
    [JsonPropertyName("rejection_message")] public string? RejectionMessage { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
}

public sealed class PaymentTerminalConfig
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("provider")] public string Provider { get; set; } = "generic_tef";
    [JsonPropertyName("enabled")] public int Enabled { get; set; }
    [JsonPropertyName("integration_mode")] public string IntegrationMode { get; set; } = "local_service";
    [JsonPropertyName("terminal_label")] public string? TerminalLabel { get; set; }
    [JsonPropertyName("pinpad_identifier")] public string? PinpadIdentifier { get; set; }
    [JsonPropertyName("auto_capture")] public int AutoCapture { get; set; } = 1;
    [JsonPropertyName("runtime_config")] public Dictionary<string, object?> RuntimeConfig { get; set; } = new();
}

public sealed class PaymentTerminalListResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("terminals")] public List<PaymentTerminalConfig> Terminals { get; set; } = new();
}

public sealed class HardwareBindingResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("binding")] public Dictionary<string, object?>? Binding { get; set; }
}

public sealed class LocalHardwareProfile
{
    public string ComputerName { get; set; } = Environment.MachineName;
    public string AppVersion { get; set; } = "";
    public string DefaultPrinter { get; set; } = "";
    public List<string> Printers { get; set; } = new();
    public string CashDrawer { get; set; } = "";
    public string Scale { get; set; } = "";
    public string BarcodeScanner { get; set; } = "";
    public string CustomerDisplay { get; set; } = "";
    public string TefProvider { get; set; } = "";
    public string Pinpad { get; set; } = "";
}

public sealed record TerminalPaymentRequest(int OrderId,int AmountCents,string PaymentType,int Installments,string IdempotencyKey);
public sealed record TerminalPaymentResult(bool Approved,string Provider,string TransactionCode,string AuthorizationCode,int AmountCents,string Message,string? ReceiptText=null);
