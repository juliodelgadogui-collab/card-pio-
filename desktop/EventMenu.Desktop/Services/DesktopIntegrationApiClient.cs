using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using EventMenu.Desktop.Models;

namespace EventMenu.Desktop.Services;

public sealed class DesktopIntegrationApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly SecureSessionStore _store;
    private readonly string _deviceId;
    private readonly SemaphoreSlim _refreshLock=new(1,1);
    private static readonly JsonSerializerOptions JsonOptions=new()
    {
        PropertyNameCaseInsensitive=true,
        NumberHandling=JsonNumberHandling.AllowReadingFromString
    };

    public DesktopIntegrationApiClient(SecureSessionStore store)
    {
        _store=store;
        _deviceId=DeviceIdentity.GetOrCreate();
        var baseUrl=(Environment.GetEnvironmentVariable("EVENTMENU_DESKTOP_API_BASE_URL")??"https://go.gestao2.store/1/").Trim();
        if(!baseUrl.EndsWith('/'))baseUrl+="/";
        if(!Uri.TryCreate(baseUrl,UriKind.Absolute,out var uri)||uri.Scheme!=Uri.UriSchemeHttps)
            throw new InvalidOperationException("O endereço do servidor EventMenu precisa usar HTTPS.");
        _http=new HttpClient{BaseAddress=uri,Timeout=TimeSpan.FromSeconds(30)};
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Add("X-Device-Id",_deviceId);
    }

    public string DeviceId=>_deviceId;

    public Task<DesktopIntegrationContext> ContextAsync(CancellationToken ct=default)=>GetAsync<DesktopIntegrationContext>("api-desktop.php","context",null,ct);
    public Task<FiscalProfileResponse> FiscalProfileAsync(int unitId,CancellationToken ct=default)=>GetAsync<FiscalProfileResponse>("api-desktop.php","fiscal-profile",new(){["unit_id"]=unitId.ToString()},ct);
    public Task<FiscalProfileResponse> SaveFiscalProfileAsync(object payload,CancellationToken ct=default)=>PostAsync<FiscalProfileResponse>("api-desktop.php","fiscal-profile-save",payload,ct);
    public Task<FiscalProductsResponse> FiscalProductsAsync(CancellationToken ct=default)=>GetAsync<FiscalProductsResponse>("api-desktop.php","fiscal-products",null,ct);
    public Task<FiscalProductResponse> SaveFiscalProductAsync(object payload,CancellationToken ct=default)=>PostAsync<FiscalProductResponse>("api-desktop.php","fiscal-product-save",payload,ct);
    public Task<FiscalReadinessResponse> FiscalReadinessAsync(int unitId,CancellationToken ct=default)=>GetAsync<FiscalReadinessResponse>("api-desktop.php","fiscal-readiness",new(){["unit_id"]=unitId.ToString()},ct);
    public Task<FiscalCertificateResponse> SaveA1Async(int profileId,byte[] pfx,string password,string storageScope,CancellationToken ct=default)=>PostAsync<FiscalCertificateResponse>("api-desktop.php","certificate-save-a1",new{profile_id=profileId,pfx_base64=Convert.ToBase64String(pfx),password,storage_scope=storageScope},ct);
    public Task<FiscalCertificateResponse> RegisterA3Async(int profileId,string subject,string serial,string thumbprint,string? validUntil,CancellationToken ct=default)=>PostAsync<FiscalCertificateResponse>("api-desktop.php","certificate-register-a3",new{profile_id=profileId,subject,serial_number=serial,thumbprint,valid_until=validUntil},ct);
    public Task<FiscalDocumentResponse> QueueFiscalAsync(int orderId,string document,CancellationToken ct=default)=>PostAsync<FiscalDocumentResponse>("api-desktop.php","fiscal-queue",new{order_id=orderId,document},ct);
    public Task<FiscalDocumentsResponse> FiscalDocumentsAsync(int limit=100,CancellationToken ct=default)=>GetAsync<FiscalDocumentsResponse>("api-desktop.php","fiscal-documents",new(){["limit"]=limit.ToString()},ct);
    public Task<FiscalDocumentResponse> RetryFiscalAsync(long documentId,CancellationToken ct=default)=>PostAsync<FiscalDocumentResponse>("api-desktop.php","fiscal-retry",new{document_id=documentId},ct);
    public Task<FiscalEventsResponse> FiscalEventsAsync(int limit=100,CancellationToken ct=default)=>GetAsync<FiscalEventsResponse>("api-desktop.php","fiscal-events",new(){["limit"]=limit.ToString()},ct);
    public Task<FiscalEventResponse> CancelFiscalAsync(long documentId,string reason,CancellationToken ct=default)=>PostAsync<FiscalEventResponse>("api-desktop.php","fiscal-cancel",new{document_id=documentId,reason},ct);
    public Task<FiscalEventResponse> RetryFiscalEventAsync(long eventId,CancellationToken ct=default)=>PostAsync<FiscalEventResponse>("api-desktop.php","fiscal-event-retry",new{event_id=eventId},ct);
    public Task<FiscalInutilizationsResponse> FiscalInutilizationsAsync(int limit=100,CancellationToken ct=default)=>GetAsync<FiscalInutilizationsResponse>("api-desktop.php","fiscal-inutilizations",new(){["limit"]=limit.ToString()},ct);
    public Task<FiscalInutilizationResponse> InutilizeFiscalAsync(int unitId,string document,int year,int series,long start,long end,string reason,CancellationToken ct=default)=>PostAsync<FiscalInutilizationResponse>("api-desktop.php","fiscal-inutilize",new{unit_id=unitId,document,year,series,number_start=start,number_end=end,reason},ct);
    public Task<FiscalInutilizationResponse> RetryFiscalInutilizationAsync(long id,CancellationToken ct=default)=>PostAsync<FiscalInutilizationResponse>("api-desktop.php","fiscal-inutilization-retry",new{inutilization_id=id},ct);
    public Task<FiscalDocumentResponse> PrepareFiscalContingencyAsync(long documentId,string reason,CancellationToken ct=default)=>PostAsync<FiscalDocumentResponse>("api-desktop.php","fiscal-contingency-prepare",new{document_id=documentId,reason,device_id=_deviceId},ct);
    public Task<FiscalDocumentResponse> RegisterFiscalContingencyAsync(long documentId,string signedXml,CancellationToken ct=default)=>PostAsync<FiscalDocumentResponse>("api-desktop.php","fiscal-contingency-register",new{document_id=documentId,signed_xml=signedXml,device_id=_deviceId},ct);
    public Task<Dictionary<string,JsonElement>> QueryFiscalAsync(long documentId,CancellationToken ct=default)=>PostAsync<Dictionary<string,JsonElement>>("api-desktop.php","fiscal-query",new{document_id=documentId},ct);
    public Task<FiscalDanfeResponse> FiscalDanfeAsync(long documentId,CancellationToken ct=default)=>GetAsync<FiscalDanfeResponse>("api-fiscal-print.php","danfe",new(){["document_id"]=documentId.ToString()},ct);

    public Task<PaymentTerminalListResponse> TerminalListAsync(int unitId,CancellationToken ct=default)=>GetAsync<PaymentTerminalListResponse>("api-desktop.php","terminal-list",new(){["unit_id"]=unitId.ToString()},ct);
    public Task<PaymentTerminalResponse> SaveTerminalAsync(int id,int unitId,string provider,bool enabled,string integrationMode,string label,string pinpad,bool autoCapture,Dictionary<string,object?> config,CancellationToken ct=default)=>
        PostAsync<PaymentTerminalResponse>("api-desktop.php","terminal-save",new{id,unit_id=unitId,provider,enabled,integration_mode=integrationMode,terminal_label=label,pinpad_identifier=pinpad,auto_capture=autoCapture,config},ct);
    public Task<HardwareBindingResponse> HardwareHeartbeatAsync(int unitId,LocalHardwareProfileStore store,LocalHardwareProfile profile,CancellationToken ct=default)=>PostAsync<HardwareBindingResponse>("api-desktop.php","hardware-heartbeat",new{unit_id=unitId,device_id=_deviceId,device_label=$"EventMenu Desktop - {Environment.MachineName}",hardware=store.ToServerReport(profile)},ct);
    public Task<TerminalIntentResponse> CreateTerminalIntentAsync(TerminalPaymentRequest request,int terminalConfigId,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-create",new{order_id=request.OrderId,terminal_config_id=terminalConfigId,amount_cents=request.AmountCents,payment_type=request.PaymentType,installments=request.Installments,device_id=_deviceId,idempotency_key=request.IdempotencyKey},ct);
    public Task<TerminalIntentResponse> MarkTerminalProcessingAsync(string intentToken,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-processing",new{intent_token=intentToken,device_id=_deviceId},ct);
    public Task<TerminalIntentResponse> RecordTerminalResultAsync(string intentToken,TerminalPaymentResult result,Dictionary<string,object?> raw,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-result",new{intent_token=intentToken,device_id=_deviceId,approved=result.Approved,provider_transaction_id=result.TransactionCode,authorization_code=result.AuthorizationCode,raw_result=raw},ct);
    public Task<TerminalIntentResponse> TerminalIntentStatusAsync(string intentToken,CancellationToken ct=default)=>GetAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-status",new(){["intent_token"]=intentToken},ct);

    public Task<ProductionBoardResponse> ProductionBoardAsync(CancellationToken ct=default)=>GetAsync<ProductionBoardResponse>("api-production.php","board",null,ct);
    public Task<ExpeditionResponse> ExpeditionAsync(CancellationToken ct=default)=>GetAsync<ExpeditionResponse>("api-production.php","expedition",null,ct);
    public Task<ProductionMutationResponse> ChangeProductionJobAsync(int jobId,string status,string reason="",CancellationToken ct=default)=>PostAsync<ProductionMutationResponse>("api-production.php","job-status",new{job_id=jobId,status,reason},ct);
    public Task<ProductionMutationResponse> ExpediteProductionOrderAsync(int orderId,CancellationToken ct=default)=>PostAsync<ProductionMutationResponse>("api-production.php","order-expedite",new{order_id=orderId},ct);
    public Task<ProductionPrintClaimResponse> ClaimProductionPrintAsync(CancellationToken ct=default)=>PostAsync<ProductionPrintClaimResponse>("api-production.php","print-claim",new{device_id=_deviceId},ct);
    public Task<ProductionMutationResponse> CompleteProductionPrintAsync(int queueId,bool success,string error="",CancellationToken ct=default)=>PostAsync<ProductionMutationResponse>("api-production.php","print-complete",new{queue_id=queueId,device_id=_deviceId,success,error},ct);
    public Task<ProductionMutationResponse> RetryProductionPrintAsync(int queueId,string reason,CancellationToken ct=default)=>PostAsync<ProductionMutationResponse>("api-production.php","print-retry",new{queue_id=queueId,reason},ct);
    public Task<ProductionMutationResponse> BindStationPrinterAsync(int stationId,string printer,bool automatic,bool bindThisDesktop=true,CancellationToken ct=default)=>PostAsync<ProductionMutationResponse>("api-production.php","station-printer",new{station_id=stationId,device_id=bindThisDesktop?_deviceId:"",printer_target=printer,automatic},ct);

    public Task<HubPairingResponse> CreateHubPairingAsync(int unitId,CancellationToken ct=default)=>PostAsync<HubPairingResponse>("api-hub.php","pairing-create",new{unit_id=unitId,device_id=_deviceId},ct);
    public Task<HubCommandsResponse> PollHubAsync(int limit=20,CancellationToken ct=default)=>GetAsync<HubCommandsResponse>("api-hub.php","desktop-poll",new(){["device_id"]=_deviceId,["limit"]=limit.ToString()},ct);
    public Task<HubCommandResponse> ClaimHubCommandAsync(int id,CancellationToken ct=default)=>PostAsync<HubCommandResponse>("api-hub.php","desktop-claim",new{id,device_id=_deviceId},ct);
    public Task<HubCommandResponse> CompleteHubCommandAsync(int id,bool success,object? result=null,string error="",CancellationToken ct=default)=>PostAsync<HubCommandResponse>("api-hub.php","desktop-complete",new{id,device_id=_deviceId,success,result=result??new{},error},ct);

    private async Task<T> GetAsync<T>(string path,string action,Dictionary<string,string>? query,CancellationToken ct)
    {
        var url=BuildUrl(path,action,query);
        return await SendWithRefreshAsync<T>(HttpMethod.Get,url,null,ct);
    }

    private async Task<T> PostAsync<T>(string path,string action,object body,CancellationToken ct)
    {
        var url=BuildUrl(path,action,null);
        return await SendWithRefreshAsync<T>(HttpMethod.Post,url,body,ct);
    }

    private async Task<T> SendWithRefreshAsync<T>(HttpMethod method,string url,object? body,CancellationToken ct)
    {
        var session=_store.Load()??throw new ApiClientException("Faça login novamente.",HttpStatusCode.Unauthorized);
        try{return await SendAsync<T>(method,url,body,session.Token,ct);}
        catch(ApiClientException ex) when(ex.StatusCode==HttpStatusCode.Unauthorized)
        {
            session=await RefreshAsync(session,ct);
            return await SendAsync<T>(method,url,body,session.Token,ct);
        }
    }

    private async Task<SessionEnvelope> RefreshAsync(SessionEnvelope current,CancellationToken ct)
    {
        await _refreshLock.WaitAsync(ct);
        try
        {
            var latest=_store.Load();
            if(latest is not null&&latest.Token!=current.Token&&!string.IsNullOrWhiteSpace(latest.Token))return latest;
            var refreshed=await SendAsync<LoginResponse>(HttpMethod.Post,"api.php?action=refresh",new{refresh_token=current.RefreshToken,device_id=_deviceId},null,ct);
            if(string.IsNullOrWhiteSpace(refreshed.Token)||string.IsNullOrWhiteSpace(refreshed.RefreshToken))throw new ApiClientException("Faça login novamente.",HttpStatusCode.Unauthorized);
            var session=new SessionEnvelope{Token=refreshed.Token,RefreshToken=refreshed.RefreshToken,ExpiresAt=refreshed.ExpiresAt,RefreshExpiresAt=refreshed.RefreshExpiresAt,User=refreshed.User??current.User};
            _store.Save(session);return session;
        }
        finally{_refreshLock.Release();}
    }

    private async Task<T> SendAsync<T>(HttpMethod method,string url,object? body,string? token,CancellationToken ct)
    {
        using var request=new HttpRequestMessage(method,url);
        if(!string.IsNullOrWhiteSpace(token))request.Headers.Authorization=new AuthenticationHeaderValue("Bearer",token);
        if(body is not null)request.Content=new StringContent(JsonSerializer.Serialize(body,JsonOptions),Encoding.UTF8,"application/json");
        HttpResponseMessage response;
        try{response=await _http.SendAsync(request,HttpCompletionOption.ResponseContentRead,ct);}
        catch(TaskCanceledException) when(!ct.IsCancellationRequested){throw new ApiClientException("O servidor demorou para responder.");}
        catch(HttpRequestException){throw new ApiClientException("Sem conexão com o servidor.");}
        using(response)
        {
            var text=await response.Content.ReadAsStringAsync(ct);
            if(!response.IsSuccessStatusCode)throw new ApiClientException(ReadError(text),response.StatusCode);
            var result=JsonSerializer.Deserialize<T>(text,JsonOptions);return result??throw new ApiClientException("O servidor retornou dados incompletos.");
        }
    }

    private static string BuildUrl(string path,string action,Dictionary<string,string>? query)
    {
        var sb=new StringBuilder(path).Append("?action=").Append(Uri.EscapeDataString(action));
        if(query is not null)foreach(var pair in query)sb.Append('&').Append(Uri.EscapeDataString(pair.Key)).Append('=').Append(Uri.EscapeDataString(pair.Value));
        return sb.ToString();
    }

    private static string ReadError(string text)
    {
        try{return JsonSerializer.Deserialize<ApiError>(text,JsonOptions)?.Error??"Não foi possível concluir a operação.";}
        catch{return "Não foi possível concluir a operação.";}
    }

    public void Dispose(){_http.Dispose();_refreshLock.Dispose();}
}
