using System.Text.Json.Serialization;

namespace EventMenu.Desktop.Models;

public sealed class FiscalEventsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("events")] public List<FiscalEventInfo> Events { get; set; } = new();
}

public sealed class FiscalEventResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("event")] public FiscalEventInfo? Event { get; set; }
}

public sealed class FiscalEventInfo
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("fiscal_document_id")] public long FiscalDocumentId { get; set; }
    [JsonPropertyName("event_type")] public string EventType { get; set; } = "";
    [JsonPropertyName("sequence_no")] public int SequenceNo { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
    [JsonPropertyName("protocol")] public string? Protocol { get; set; }
    [JsonPropertyName("rejection_code")] public string? RejectionCode { get; set; }
    [JsonPropertyName("rejection_message")] public string? RejectionMessage { get; set; }
    [JsonPropertyName("attempts")] public int Attempts { get; set; }
    [JsonPropertyName("model")] public string? Model { get; set; }
    [JsonPropertyName("series")] public int Series { get; set; }
    [JsonPropertyName("document_number")] public long DocumentNumber { get; set; }
    [JsonPropertyName("access_key")] public string? AccessKey { get; set; }
    [JsonPropertyName("created_at")] public string? CreatedAt { get; set; }
}

public sealed class FiscalInutilizationsResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("inutilizations")] public List<FiscalInutilizationInfo> Inutilizations { get; set; } = new();
}

public sealed class FiscalInutilizationResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("inutilization")] public FiscalInutilizationInfo? Inutilization { get; set; }
}

public sealed class FiscalInutilizationInfo
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("unit_id")] public int UnitId { get; set; }
    [JsonPropertyName("model")] public string Model { get; set; } = "";
    [JsonPropertyName("environment")] public string Environment { get; set; } = "";
    [JsonPropertyName("fiscal_year")] public int FiscalYear { get; set; }
    [JsonPropertyName("series")] public int Series { get; set; }
    [JsonPropertyName("number_start")] public long NumberStart { get; set; }
    [JsonPropertyName("number_end")] public long NumberEnd { get; set; }
    [JsonPropertyName("justification")] public string Justification { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("protocol")] public string? Protocol { get; set; }
    [JsonPropertyName("rejection_code")] public string? RejectionCode { get; set; }
    [JsonPropertyName("rejection_message")] public string? RejectionMessage { get; set; }
    [JsonPropertyName("attempts")] public int Attempts { get; set; }
    [JsonPropertyName("created_at")] public string? CreatedAt { get; set; }
}

public sealed class FiscalRuntimeCapabilities
{
    [JsonPropertyName("transmitter_configured")] public bool TransmitterConfigured { get; set; }
    [JsonPropertyName("lifecycle_supported")] public bool LifecycleSupported { get; set; }
    [JsonPropertyName("contingency_verifier_configured")] public bool ContingencyVerifierConfigured { get; set; }
}

public sealed class FiscalDanfeResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("danfe")] public FiscalDanfeData? Danfe { get; set; }
}

public sealed class FiscalDanfeData
{
    [JsonPropertyName("document")] public FiscalDanfeDocument Document { get; set; } = new();
    [JsonPropertyName("issuer")] public Dictionary<string, object?> Issuer { get; set; } = new();
    [JsonPropertyName("recipient")] public Dictionary<string, object?> Recipient { get; set; } = new();
    [JsonPropertyName("order")] public Dictionary<string, object?> Order { get; set; } = new();
    [JsonPropertyName("items")] public List<FiscalDanfeItem> Items { get; set; } = new();
    [JsonPropertyName("qr_code")] public string? QrCode { get; set; }
    [JsonPropertyName("print_mode")] public string PrintMode { get; set; } = "";
    [JsonPropertyName("warning")] public string? Warning { get; set; }
}

public sealed class FiscalDanfeDocument
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("kind")] public string Kind { get; set; } = "";
    [JsonPropertyName("model")] public string Model { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("environment")] public string Environment { get; set; } = "";
    [JsonPropertyName("series")] public int Series { get; set; }
    [JsonPropertyName("number")] public long Number { get; set; }
    [JsonPropertyName("access_key")] public string? AccessKey { get; set; }
    [JsonPropertyName("protocol")] public string? Protocol { get; set; }
}

public sealed class FiscalDanfeItem
{
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("sku")] public string? Sku { get; set; }
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
    [JsonPropertyName("unit")] public string Unit { get; set; } = "UN";
    [JsonPropertyName("unit_price_cents")] public int UnitPriceCents { get; set; }
    [JsonPropertyName("total_cents")] public int TotalCents { get; set; }
    [JsonPropertyName("ncm")] public string? Ncm { get; set; }
    [JsonPropertyName("cfop")] public string? Cfop { get; set; }
}
