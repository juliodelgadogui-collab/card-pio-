using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using EventMenu.WhatsAppConnect.Models;

namespace EventMenu.WhatsAppConnect.Services;

public sealed class WhatsAppCloudClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly SharedEventMenuSession _sessionStore;
    private readonly string _deviceId;
    private readonly SemaphoreSlim _refreshLock=new(1,1);
    private static readonly JsonSerializerOptions JsonOptions=new(){PropertyNameCaseInsensitive=true};

    public WhatsAppCloudClient(SharedEventMenuSession sessionStore,string deviceId)
    {
        _sessionStore=sessionStore;
        _deviceId=deviceId;
        var baseUrl=(Environment.GetEnvironmentVariable("EVENTMENU_DESKTOP_API_BASE_URL")??"https://go.gestao2.store/1/").Trim();
        if(!baseUrl.EndsWith('/'))baseUrl+="/";
        if(!Uri.TryCreate(baseUrl,UriKind.Absolute,out var uri)||uri.Scheme!=Uri.UriSchemeHttps)throw new InvalidOperationException("O servidor EventMenu precisa usar HTTPS.");
        _http=new HttpClient{BaseAddress=uri,Timeout=TimeSpan.FromSeconds(20)};
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Add("X-Device-Id",_deviceId);
    }

    public async Task<SessionEnvelope> LoginAsync(string email,string password,CancellationToken ct=default)
    {
        email=email.Trim();
        if(string.IsNullOrWhiteSpace(email)||string.IsNullOrWhiteSpace(password))throw new WhatsAppConnectException("Informe e-mail e senha.");
        var login=await SendAsync<LoginRefreshResponse>(HttpMethod.Post,"api.php?action=login",new
        {
            email,
            password,
            device_id=_deviceId,
            device_label=$"EventMenu WhatsApp Connect - {Environment.MachineName}"
        },null,ct);
        if(string.IsNullOrWhiteSpace(login.Token)||string.IsNullOrWhiteSpace(login.RefreshToken)||login.User is null||login.User.TenantId<1)
            throw new WhatsAppConnectException("O servidor não retornou uma sessão válida.");
        var session=new SessionEnvelope
        {
            Token=login.Token,
            RefreshToken=login.RefreshToken,
            ExpiresAt=login.ExpiresAt,
            RefreshExpiresAt=login.RefreshExpiresAt,
            User=login.User
        };
        _sessionStore.Save(session);
        return session;
    }

    public Task<CloudStateResponse> StateAsync(CancellationToken ct=default)=>SendWithRefreshAsync<CloudStateResponse>(HttpMethod.Get,"api-whatsapp-desktop.php?action=state",null,ct);

    public Task<CloudStateResponse> HeartbeatAsync(string status,string phone,string error,string deviceLabel,CancellationToken ct=default)=>
        SendWithRefreshAsync<CloudStateResponse>(HttpMethod.Post,"api-whatsapp-desktop.php?action=heartbeat",new{device_id=_deviceId,device_label=deviceLabel,status,phone,error},ct);

    public Task<CloudClaimResponse> ClaimAsync(int limit=5,CancellationToken ct=default)=>
        SendWithRefreshAsync<CloudClaimResponse>(HttpMethod.Post,"api-whatsapp-desktop.php?action=claim",new{device_id=_deviceId,limit},ct);

    public Task AckAsync(long id,string claimToken,string externalMessageId,CancellationToken ct=default)=>
        SendWithRefreshAsync<Dictionary<string,object?>>(HttpMethod.Post,"api-whatsapp-desktop.php?action=ack",new{device_id=_deviceId,id,claim_token=claimToken,external_message_id=externalMessageId},ct);

    public Task FailAsync(long id,string claimToken,string error,CancellationToken ct=default)=>
        SendWithRefreshAsync<Dictionary<string,object?>>(HttpMethod.Post,"api-whatsapp-desktop.php?action=fail",new{device_id=_deviceId,id,claim_token=claimToken,error},ct);

    private async Task<T> SendWithRefreshAsync<T>(HttpMethod method,string url,object? body,CancellationToken ct)
    {
        var session=_sessionStore.Load()??throw new WhatsAppConnectException("Faça login no EventMenu Connect.",HttpStatusCode.Unauthorized);
        try{return await SendAsync<T>(method,url,body,session.Token,ct);}
        catch(WhatsAppConnectException ex) when(ex.StatusCode==HttpStatusCode.Unauthorized)
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
            var latest=_sessionStore.Load();
            if(latest is not null&&latest.Token!=current.Token&&!string.IsNullOrWhiteSpace(latest.Token))return latest;
            var refreshed=await SendAsync<LoginRefreshResponse>(HttpMethod.Post,"api.php?action=refresh",new{refresh_token=current.RefreshToken,device_id=_deviceId},null,ct);
            if(string.IsNullOrWhiteSpace(refreshed.Token)||string.IsNullOrWhiteSpace(refreshed.RefreshToken))throw new WhatsAppConnectException("A sessão do EventMenu terminou. Faça login novamente.",HttpStatusCode.Unauthorized);
            var session=new SessionEnvelope{Token=refreshed.Token,RefreshToken=refreshed.RefreshToken,ExpiresAt=refreshed.ExpiresAt,RefreshExpiresAt=refreshed.RefreshExpiresAt,User=refreshed.User??current.User};
            _sessionStore.Save(session);
            return session;
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
        catch(TaskCanceledException) when(!ct.IsCancellationRequested){throw new WhatsAppConnectException("O servidor EventMenu demorou para responder.");}
        catch(HttpRequestException){throw new WhatsAppConnectException("Sem conexão com o servidor EventMenu.");}
        using(response)
        {
            var text=await response.Content.ReadAsStringAsync(ct);
            if(!response.IsSuccessStatusCode)throw new WhatsAppConnectException(ReadError(text),response.StatusCode);
            try{return JsonSerializer.Deserialize<T>(text,JsonOptions)??throw new WhatsAppConnectException("O servidor retornou dados incompletos.");}
            catch(JsonException){throw new WhatsAppConnectException("O servidor retornou uma resposta inválida.");}
        }
    }

    private static string ReadError(string text)
    {
        try{return JsonSerializer.Deserialize<ApiError>(text,JsonOptions)?.Error??"Não foi possível concluir a operação.";}
        catch{return "Não foi possível concluir a operação.";}
    }

    public void Dispose(){_http.Dispose();_refreshLock.Dispose();}
}

public sealed class WhatsAppConnectException : Exception
{
    public HttpStatusCode? StatusCode { get; }
    public WhatsAppConnectException(string message,HttpStatusCode? statusCode=null):base(message){StatusCode=statusCode;}
}
