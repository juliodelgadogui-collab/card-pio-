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
    public Task<FiscalCertificateResponse> SaveA1Async(int profileId,byte[] pfx,string password,string storageScope,CancellationToken ct=default)=>PostAsync<FiscalCertificateResponse>("api-desktop.php","certificate-save-a1",new{profile_id=profileId,pfx_base64=Convert.ToBase64String(pfx),password,storage_scope=storageScope},ct);
    public Task<FiscalCertificateResponse> RegisterA3Async(int profileId,string subject,string serial,string thumbprint,string? validUntil,CancellationToken ct=default)=>PostAsync<FiscalCertificateResponse>("api-desktop.php","certificate-register-a3",new{profile_id=profileId,subject,serial_number=serial,thumbprint,valid_until=validUntil},ct);
    public Task<FiscalDocumentResponse> QueueFiscalAsync(int orderId,string document,CancellationToken ct=default)=>PostAsync<FiscalDocumentResponse>("api-desktop.php","fiscal-queue",new{order_id=orderId,document},ct);
    public Task<FiscalDocumentsResponse> FiscalDocumentsAsync(int limit=100,CancellationToken ct=default)=>GetAsync<FiscalDocumentsResponse>("api-desktop.php","fiscal-documents",new(){["limit"]=limit.ToString()},ct);
    public Task<PaymentTerminalListResponse> TerminalListAsync(int unitId,CancellationToken ct=default)=>GetAsync<PaymentTerminalListResponse>("api-desktop.php","terminal-list",new(){["unit_id"]=unitId.ToString()},ct);
    public Task<PaymentTerminalResponse> SaveTerminalAsync(int id,int unitId,string provider,bool enabled,string integrationMode,string label,string pinpad,bool autoCapture,Dictionary<string,object?> config,CancellationToken ct=default)=>
        PostAsync<PaymentTerminalResponse>("api-desktop.php","terminal-save",new{id,unit_id=unitId,provider,enabled,integration_mode=integrationMode,terminal_label=label,pinpad_identifier=pinpad,auto_capture=autoCapture,config},ct);
    public Task<HardwareBindingResponse> HardwareHeartbeatAsync(int unitId,LocalHardwareProfileStore store,LocalHardwareProfile profile,CancellationToken ct=default)=>PostAsync<HardwareBindingResponse>("api-desktop.php","hardware-heartbeat",new{unit_id=unitId,device_id=_deviceId,device_label=$"EventMenu Desktop - {Environment.MachineName}",hardware=store.ToServerReport(profile)},ct);
    public Task<TerminalIntentResponse> CreateTerminalIntentAsync(TerminalPaymentRequest request,int terminalConfigId,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-create",new{order_id=request.OrderId,terminal_config_id=terminalConfigId,amount_cents=request.AmountCents,payment_type=request.PaymentType,installments=request.Installments,device_id=_deviceId,idempotency_key=request.IdempotencyKey},ct);
    public Task<TerminalIntentResponse> MarkTerminalProcessingAsync(string intentToken,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-processing",new{intent_token=intentToken,device_id=_deviceId},ct);
    public Task<TerminalIntentResponse> RecordTerminalResultAsync(string intentToken,TerminalPaymentResult result,Dictionary<string,object?> raw,CancellationToken ct=default)=>PostAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-result",new{intent_token=intentToken,device_id=_deviceId,approved=result.Approved,provider_transaction_id=result.TransactionCode,authorization_code=result.AuthorizationCode,raw_result=raw},ct);
    public Task<TerminalIntentResponse> TerminalIntentStatusAsync(string intentToken,CancellationToken ct=default)=>GetAsync<TerminalIntentResponse>("api-desktop.php","terminal-intent-status",new(){["intent_token"]=intentToken},ct);

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
