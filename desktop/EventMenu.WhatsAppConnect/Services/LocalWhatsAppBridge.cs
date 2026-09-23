using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using EventMenu.WhatsAppConnect.Models;

namespace EventMenu.WhatsAppConnect.Services;

public sealed class LocalWhatsAppBridge : IDisposable
{
    private readonly LocalWhatsAppSettingsStore _store;
    private readonly LocalWhatsAppSettings _settings;
    private readonly HttpClient _http;
    private Process? _ownedProcess;
    private static readonly JsonSerializerOptions JsonOptions=new(){PropertyNameCaseInsensitive=true};

    public LocalWhatsAppBridge(int tenantId)
    {
        _store=new LocalWhatsAppSettingsStore(tenantId);
        _settings=_store.LoadOrCreate();
        _http=new HttpClient{BaseAddress=new Uri($"http://127.0.0.1:{_settings.Port}/"),Timeout=TimeSpan.FromSeconds(12)};
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.Authorization=new AuthenticationHeaderValue("Bearer",_settings.Secret);
    }

    public string SessionKey=>_settings.SessionKey;

    public async Task EnsureStartedAsync(CancellationToken ct=default)
    {
        if(await IsHealthyAsync(ct))return;
        if(_ownedProcess is not null&&!_ownedProcess.HasExited)return;

        var baseDir=AppContext.BaseDirectory;
        var nodeOverride=Environment.GetEnvironmentVariable("EVENTMENU_WHATSAPP_NODE_PATH");
        var workerOverride=Environment.GetEnvironmentVariable("EVENTMENU_WHATSAPP_WORKER_PATH");
        var node=string.IsNullOrWhiteSpace(nodeOverride)?Path.Combine(baseDir,"whatsapp-connect","node","node.exe"):nodeOverride;
        var worker=string.IsNullOrWhiteSpace(workerOverride)?Path.Combine(baseDir,"whatsapp-connect","worker","server.js"):workerOverride;
        if(!File.Exists(node))throw new InvalidOperationException("O mecanismo local do WhatsApp não foi instalado. Reinstale o EventMenu Desktop.");
        if(!File.Exists(worker))throw new InvalidOperationException("Os arquivos do WhatsApp Connect não foram encontrados. Reinstale o EventMenu Desktop.");

        Directory.CreateDirectory(_store.SessionRoot);
        var psi=new ProcessStartInfo
        {
            FileName=node,
            WorkingDirectory=Path.GetDirectoryName(worker)!,
            UseShellExecute=false,
            CreateNoWindow=true,
            WindowStyle=ProcessWindowStyle.Hidden
        };
        psi.ArgumentList.Add(worker);
        psi.Environment["HOST"]="127.0.0.1";
        psi.Environment["PORT"]=_settings.Port.ToString();
        psi.Environment["EVENTMENU_WHATSAPP_BRIDGE_SECRET"]=_settings.Secret;
        psi.Environment["WHATSAPP_SESSION_ROOT"]=_store.SessionRoot;
        psi.Environment["NODE_ENV"]="production";
        _ownedProcess=Process.Start(psi)??throw new InvalidOperationException("Não foi possível iniciar o mecanismo local do WhatsApp.");

        var deadline=DateTime.UtcNow.AddSeconds(25);
        while(DateTime.UtcNow<deadline)
        {
            ct.ThrowIfCancellationRequested();
            if(_ownedProcess.HasExited)throw new InvalidOperationException("O mecanismo local do WhatsApp encerrou durante a inicialização.");
            if(await IsHealthyAsync(ct))return;
            await Task.Delay(650,ct);
        }
        throw new TimeoutException("O mecanismo local do WhatsApp demorou para iniciar.");
    }

    public async Task<LocalBridgeState> StateAsync(CancellationToken ct=default)=>
        await SendAsync<LocalBridgeState>(HttpMethod.Get,$"v1/sessions/{_settings.SessionKey}",null,ct);

    public async Task<LocalBridgeState> StartSessionAsync(CancellationToken ct=default)=>
        await SendAsync<LocalBridgeState>(HttpMethod.Post,$"v1/sessions/{_settings.SessionKey}/start",new{},ct);

    public async Task<LocalBridgeState> RequestPairingCodeAsync(string phone,CancellationToken ct=default)=>
        await SendAsync<LocalBridgeState>(HttpMethod.Post,$"v1/sessions/{_settings.SessionKey}/pairing-code",new{phone},ct);

    public async Task<LocalBridgeState> LogoutAsync(CancellationToken ct=default)=>
        await SendAsync<LocalBridgeState>(HttpMethod.Post,$"v1/sessions/{_settings.SessionKey}/logout",new{},ct);

    public async Task<LocalSendResponse> SendAsync(string phone,string message,CancellationToken ct=default)=>
        await SendAsync<LocalSendResponse>(HttpMethod.Post,$"v1/sessions/{_settings.SessionKey}/send",new{phone,message},ct);

    private async Task<bool> IsHealthyAsync(CancellationToken ct)
    {
        try
        {
            using var response=await _http.GetAsync("health",ct);
            return response.IsSuccessStatusCode;
        }
        catch{return false;}
    }

    private async Task<T> SendAsync<T>(HttpMethod method,string path,object? body,CancellationToken ct)
    {
        using var request=new HttpRequestMessage(method,path);
        if(body is not null)request.Content=new StringContent(JsonSerializer.Serialize(body,JsonOptions),Encoding.UTF8,"application/json");
        HttpResponseMessage response;
        try{response=await _http.SendAsync(request,HttpCompletionOption.ResponseContentRead,ct);}
        catch(HttpRequestException){throw new InvalidOperationException("O mecanismo local do WhatsApp não está respondendo.");}
        using(response)
        {
            var text=await response.Content.ReadAsStringAsync(ct);
            if(!response.IsSuccessStatusCode)
            {
                try{var err=JsonSerializer.Deserialize<ApiError>(text,JsonOptions);throw new InvalidOperationException(err?.Error??$"Falha local do WhatsApp (HTTP {(int)response.StatusCode}).");}
                catch(JsonException){throw new InvalidOperationException($"Falha local do WhatsApp (HTTP {(int)response.StatusCode}).");}
            }
            try{return JsonSerializer.Deserialize<T>(text,JsonOptions)??throw new InvalidOperationException("Resposta vazia do mecanismo local do WhatsApp.");}
            catch(JsonException){throw new InvalidOperationException("Resposta inválida do mecanismo local do WhatsApp.");}
        }
    }

    public void Dispose()
    {
        _http.Dispose();
        try
        {
            if(_ownedProcess is not null&&!_ownedProcess.HasExited)
            {
                _ownedProcess.Kill(entireProcessTree:true);
                _ownedProcess.WaitForExit(3000);
            }
        }
        catch{}
        _ownedProcess?.Dispose();
    }
}
