using System.ComponentModel;
using System.IO;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using EventMenu.WhatsAppConnect.Models;
using EventMenu.WhatsAppConnect.Services;
using Forms = System.Windows.Forms;

namespace EventMenu.WhatsAppConnect;

public partial class MainWindow : Window
{
    private readonly bool _startInBackground;
    private readonly SharedEventMenuSession _sharedSession=new();
    private readonly DispatcherTimer _timer=new(){Interval=TimeSpan.FromSeconds(8)};
    private readonly SemaphoreSlim _syncGate=new(1,1);
    private readonly CancellationTokenSource _shutdown=new();
    private Forms.NotifyIcon? _tray;
    private WhatsAppCloudClient? _cloud;
    private LocalWhatsAppBridge? _bridge;
    private SessionEnvelope? _session;
    private string _deviceId="";
    private int _tenantId;
    private bool _initialized;
    private bool _reallyExit;
    private bool _pairingMode;

    public MainWindow(bool startInBackground=false)
    {
        InitializeComponent();
        _startInBackground=startInBackground;
        _timer.Tick+=Timer_Tick;
        CreateTrayIcon();
        SetConnectionMode(false);
    }

    private void CreateTrayIcon()
    {
        _tray=new Forms.NotifyIcon
        {
            Text="EventMenu WhatsApp Connect",
            Icon=System.Drawing.SystemIcons.Application,
            Visible=true
        };
        _tray.DoubleClick+=(_,_)=>OpenFromTray();
        var menu=new Forms.ContextMenuStrip();
        menu.Items.Add("Abrir EventMenu WhatsApp Connect",null,(_,_)=>OpenFromTray());
        menu.Items.Add("Sair",null,(_,_)=>Dispatcher.Invoke(ExitFromTray));
        _tray.ContextMenuStrip=menu;
    }

    private async void Window_Loaded(object sender,RoutedEventArgs e)
    {
        if(_initialized)return;
        _initialized=true;
        if(_startInBackground)
        {
            WindowState=WindowState.Minimized;
            ShowInTaskbar=false;
            Hide();
        }
        await InitializeAsync(promptLogin:!_startInBackground);
    }

    private async Task InitializeAsync(bool promptLogin=false)
    {
        try
        {
            _deviceId=_sharedSession.GetOrCreateDeviceId();
            _cloud?.Dispose();
            _cloud=new WhatsAppCloudClient(_sharedSession,_deviceId);
            _session=_sharedSession.Load();

            if(_session?.User is null||_session.User.TenantId<1)
            {
                if(promptLogin&&IsVisible)
                {
                    var login=new LoginWindow(_cloud){Owner=this};
                    if(login.ShowDialog()==true)_session=_sharedSession.Load();
                }

                if(_session?.User is null||_session.User.TenantId<1)
                {
                    _timer.Start();
                    SetUnavailable("Faça login no EventMenu Connect para identificar seu estabelecimento. Clique em Atualizar para abrir a tela de login.");
                    return;
                }
            }

            _tenantId=_session.User.TenantId;
            TenantText.Text=string.IsNullOrWhiteSpace(_session.User.TenantName)?"Minha empresa":_session.User.TenantName;
            DeviceText.Text=Environment.MachineName;
            _bridge?.Dispose();
            _bridge=new LocalWhatsAppBridge(_tenantId);

            FooterText.Text="Iniciando o mecanismo local do WhatsApp...";
            await _bridge.EnsureStartedAsync(_shutdown.Token);
            _timer.Start();
            await SyncOnceAsync(force:true);
        }
        catch(Exception ex)
        {
            _timer.Start();
            SetUnavailable(ex.Message);
        }
    }

    private async void Timer_Tick(object? sender,EventArgs e)
    {
        if(_bridge is null||_cloud is null)
        {
            await InitializeAsync();
            return;
        }
        await SyncOnceAsync();
    }

    private async Task SyncOnceAsync(bool force=false)
    {
        if(_bridge is null||_cloud is null)return;
        if(!force&&!await _syncGate.WaitAsync(0))return;
        if(force)await _syncGate.WaitAsync(_shutdown.Token);
        try
        {
            await _bridge.EnsureStartedAsync(_shutdown.Token);
            var local=await _bridge.StateAsync(_shutdown.Token);
            UpdateLocalState(local);

            var heartbeat=await _cloud.HeartbeatAsync(
                local.Status,
                local.Phone,
                local.Error??"",
                $"EventMenu WhatsApp Connect - {Environment.MachineName}",
                _shutdown.Token);
            PendingText.Text=heartbeat.Pending.ToString();

            var sent=0;
            var failed=0;
            if(string.Equals(local.Status,"connected",StringComparison.OrdinalIgnoreCase))
            {
                var claim=await _cloud.ClaimAsync(5,_shutdown.Token);
                foreach(var message in claim.Messages)
                {
                    try
                    {
                        var result=await _bridge.SendAsync(message.Recipient,message.MessageText,_shutdown.Token);
                        await _cloud.AckAsync(message.Id,message.ClaimToken,result.MessageId,_shutdown.Token);
                        sent++;
                    }
                    catch(Exception ex)
                    {
                        try{await _cloud.FailAsync(message.Id,message.ClaimToken,ex.Message,_shutdown.Token);}catch{}
                        failed++;
                    }
                }
                if(sent>0||failed>0)
                {
                    var state=await _cloud.StateAsync(_shutdown.Token);
                    PendingText.Text=state.Pending.ToString();
                    ActivityText.Text=sent>0?$"{sent} mensagem(ns) enviada(s) às {DateTime.Now:HH:mm:ss}.":$"Fila processada às {DateTime.Now:HH:mm:ss}.";
                    if(failed>0)ErrorText.Text=$"{failed} mensagem(ns) não puderam ser enviadas agora e voltarão para a fila.";
                }
            }

            FooterText.Text=$"Conectado ao EventMenu • sincronizado às {DateTime.Now:HH:mm:ss}";
            if(failed==0&&string.IsNullOrWhiteSpace(local.Error))ErrorText.Text="";
        }
        catch(OperationCanceledException){}
        catch(Exception ex)
        {
            FooterText.Text="Sem comunicação com o servidor. Tentando novamente automaticamente...";
            ErrorText.Text=ex.Message;
        }
        finally{_syncGate.Release();}
    }

    private void UpdateLocalState(LocalBridgeState state)
    {
        var status=(state.Status??"disconnected").ToLowerInvariant();
        var hasPairingCode=!string.IsNullOrWhiteSpace(state.PairingCode);
        StatusText.Text=status switch
        {
            "connected"=>"Conectado",
            "qr" when hasPairingCode=>"Aguardando código",
            "qr"=>"Aguardando QR",
            "starting"=>"Iniciando",
            "reconnecting"=>"Reconectando",
            "error"=>"Erro",
            _=>"Desconectado"
        };
        var connected=status=="connected";
        StatusBadge.Background=new SolidColorBrush((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(connected?"#DCFCE7":status=="error"?"#FEE2E2":"#F1F5F9"));
        StatusText.Foreground=new SolidColorBrush((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(connected?"#166534":status=="error"?"#B91C1C":"#475569"));
        PhoneText.Text=string.IsNullOrWhiteSpace(state.Phone)?"—":FormatPhone(state.Phone);
        ConnectButton.IsEnabled=status is not "starting" and not "reconnecting" and not "connected";
        GenerateCodeButton.IsEnabled=status is not "starting" and not "reconnecting" and not "connected";
        DisconnectButton.IsEnabled=status is "connected" or "qr" or "starting" or "reconnecting";

        if(hasPairingCode)
        {
            SetConnectionMode(true);
            PairingCodeText.Text=FormatPairingCode(state.PairingCode!);
            PairingCodeBox.Visibility=Visibility.Visible;
            if(!string.IsNullOrWhiteSpace(state.PairingPhone))PairingPhoneText.Text="+"+new string(state.PairingPhone.Where(char.IsDigit).ToArray());
            ActivityText.Text="Código gerado. Digite-o no WhatsApp do celular.";
        }
        else
        {
            PairingCodeBox.Visibility=Visibility.Collapsed;
            PairingCodeText.Text="—";
        }

        if(!string.IsNullOrWhiteSpace(state.Qr))
        {
            SetQrImage(state.Qr);
            QrPlaceholder.Visibility=Visibility.Collapsed;
            QrImage.Visibility=Visibility.Visible;
            QrHintText.Text="Abra WhatsApp > Aparelhos conectados > Conectar um aparelho.";
        }
        else
        {
            QrImage.Source=null;
            QrImage.Visibility=Visibility.Collapsed;
            QrPlaceholder.Visibility=Visibility.Visible;
            QrHintText.Text=connected?"WhatsApp conectado. Você pode minimizar esta janela.":status=="reconnecting"?"Tentando reconectar a sessão salva...":"Clique em Gerar QR Code ou use Conectar por código.";
        }

        if(!string.IsNullOrWhiteSpace(state.Error))ErrorText.Text=state.Error;
        if(connected)
        {
            PairingCodeBox.Visibility=Visibility.Collapsed;
            ActivityText.Text=$"WhatsApp ativo neste computador • {DateTime.Now:HH:mm:ss}";
        }
    }

    private void SetConnectionMode(bool pairing)
    {
        _pairingMode=pairing;
        PairingPanel.Visibility=pairing?Visibility.Visible:Visibility.Collapsed;
        QrPanel.Visibility=pairing?Visibility.Collapsed:Visibility.Visible;
        ConnectButton.Visibility=pairing?Visibility.Collapsed:Visibility.Visible;
        QrModeButton.Background=BrushFrom(pairing?"#475569":"#16A34A");
        CodeModeButton.Background=BrushFrom(pairing?"#16A34A":"#475569");
    }

    private static SolidColorBrush BrushFrom(string color)=>new((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(color));

    private void SetQrImage(string dataUrl)
    {
        try
        {
            var comma=dataUrl.IndexOf(',');
            if(comma<0)throw new InvalidOperationException();
            var bytes=Convert.FromBase64String(dataUrl[(comma+1)..]);
            using var stream=new MemoryStream(bytes);
            var image=new BitmapImage();
            image.BeginInit();
            image.CacheOption=BitmapCacheOption.OnLoad;
            image.CreateOptions=BitmapCreateOptions.PreservePixelFormat;
            image.DecodePixelWidth=360;
            image.StreamSource=stream;
            image.EndInit();
            image.Freeze();
            QrImage.Source=image;
        }
        catch
        {
            QrImage.Source=null;
            QrImage.Visibility=Visibility.Collapsed;
            QrPlaceholder.Visibility=Visibility.Visible;
            QrHintText.Text="Não foi possível exibir o QR Code. Use Conectar por código.";
        }
    }

    private async void ConnectButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null)return;
        SetConnectionMode(false);
        ConnectButton.IsEnabled=false;
        ErrorText.Text="";
        try
        {
            await _bridge.EnsureStartedAsync(_shutdown.Token);
            await _bridge.StartSessionAsync(_shutdown.Token);
            ActivityText.Text="Gerando QR Code...";
            await Task.Delay(900,_shutdown.Token);
            await SyncOnceAsync(force:true);
        }
        catch(Exception ex){ErrorText.Text=ex.Message;}
        finally{ConnectButton.IsEnabled=true;}
    }

    private void QrModeButton_Click(object sender,RoutedEventArgs e)
    {
        SetConnectionMode(false);
        ActivityText.Text="Modo QR Code selecionado.";
    }

    private void CodeModeButton_Click(object sender,RoutedEventArgs e)
    {
        SetConnectionMode(true);
        ActivityText.Text="Informe o número do WhatsApp para gerar o código de conexão.";
        PairingPhoneText.Focus();
        PairingPhoneText.CaretIndex=PairingPhoneText.Text.Length;
    }

    private async void GenerateCodeButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null)return;
        var phone=PairingPhoneText.Text.Trim();
        if(string.IsNullOrWhiteSpace(phone)||new string(phone.Where(char.IsDigit).ToArray()).Length<10)
        {
            ErrorText.Text="Informe o número do WhatsApp com DDD.";
            PairingPhoneText.Focus();
            return;
        }

        SetConnectionMode(true);
        GenerateCodeButton.IsEnabled=false;
        PairingCodeBox.Visibility=Visibility.Collapsed;
        ErrorText.Text="";
        ActivityText.Text="Solicitando código ao WhatsApp...";
        try
        {
            await _bridge.EnsureStartedAsync(_shutdown.Token);
            var state=await _bridge.RequestPairingCodeAsync(phone,_shutdown.Token);
            UpdateLocalState(state);
            if(string.IsNullOrWhiteSpace(state.PairingCode))throw new InvalidOperationException("O código ainda não foi gerado. Tente novamente em alguns segundos.");
            ActivityText.Text="Código pronto. Digite-o no WhatsApp do celular.";
        }
        catch(Exception ex)
        {
            ErrorText.Text=ex.Message;
            ActivityText.Text="Não foi possível gerar o código agora.";
        }
        finally{GenerateCodeButton.IsEnabled=true;}
    }

    private void CopyCodeButton_Click(object sender,RoutedEventArgs e)
    {
        var code=new string(PairingCodeText.Text.Where(c=>!char.IsWhiteSpace(c)&&c!='-').ToArray());
        if(string.IsNullOrWhiteSpace(code)||code=="—")return;
        try
        {
            System.Windows.Clipboard.SetText(code);
            ActivityText.Text="Código copiado.";
        }
        catch(Exception ex){ErrorText.Text=ex.Message;}
    }

    private async void DisconnectButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null)return;
        if(System.Windows.MessageBox.Show("Desconectar este WhatsApp do EventMenu? Será necessário usar um novo QR Code ou código de conexão para vincular novamente.","Desconectar WhatsApp",MessageBoxButton.YesNo,MessageBoxImage.Question)!=MessageBoxResult.Yes)return;
        DisconnectButton.IsEnabled=false;
        try
        {
            var state=await _bridge.LogoutAsync(_shutdown.Token);
            UpdateLocalState(state);
            if(_cloud is not null)await _cloud.HeartbeatAsync("disconnected","","",$"EventMenu WhatsApp Connect - {Environment.MachineName}",_shutdown.Token);
            PendingText.Text="0";
            PairingCodeBox.Visibility=Visibility.Collapsed;
            ActivityText.Text="WhatsApp desconectado deste computador.";
        }
        catch(Exception ex){ErrorText.Text=ex.Message;}
        finally{DisconnectButton.IsEnabled=true;}
    }

    private async void RefreshButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null||_cloud is null)
        {
            await InitializeAsync(promptLogin:true);
            return;
        }
        await SyncOnceAsync(force:true);
    }

    private void SetUnavailable(string message)
    {
        StatusText.Text="Indisponível";
        ConnectButton.IsEnabled=false;
        GenerateCodeButton.IsEnabled=false;
        DisconnectButton.IsEnabled=false;
        FooterText.Text=message;
        ErrorText.Text=message;
        QrHintText.Text="Clique em Atualizar para entrar no EventMenu Connect e identificar seu estabelecimento.";
    }

    private static string FormatPairingCode(string code)
    {
        var clean=new string(code.Where(c=>char.IsLetterOrDigit(c)).ToArray()).ToUpperInvariant();
        if(clean.Length<=4)return clean;
        var parts=new List<string>();
        for(var i=0;i<clean.Length;i+=4)parts.Add(clean.Substring(i,Math.Min(4,clean.Length-i)));
        return string.Join(" ",parts);
    }

    private static string FormatPhone(string digits)
    {
        digits=new string(digits.Where(char.IsDigit).ToArray());
        if(digits.StartsWith("55")&&digits.Length>=12)
        {
            var ddd=digits.Substring(2,2);var number=digits[4..];
            if(number.Length==9)return $"+55 ({ddd}) {number[..5]}-{number[5..]}";
            if(number.Length==8)return $"+55 ({ddd}) {number[..4]}-{number[4..]}";
        }
        return "+"+digits;
    }

    private void OpenFromTray()
    {
        Dispatcher.Invoke(()=>
        {
            ShowInTaskbar=true;
            Show();
            WindowState=WindowState.Normal;
            Activate();
            Topmost=true;Topmost=false;
        });
    }

    private void Window_Closing(object? sender,CancelEventArgs e)
    {
        if(!_reallyExit)
        {
            e.Cancel=true;
            ShowInTaskbar=false;
            Hide();
            _tray?.ShowBalloonTip(1800,"EventMenu WhatsApp Connect","O WhatsApp continua funcionando em segundo plano.",Forms.ToolTipIcon.Info);
            return;
        }
        _timer.Stop();
        _shutdown.Cancel();
        _cloud?.Dispose();
        _bridge?.Dispose();
        _tray?.Dispose();
        _syncGate.Dispose();
        _shutdown.Dispose();
    }

    private void ExitFromTray()
    {
        _reallyExit=true;
        Close();
        System.Windows.Application.Current.Shutdown();
    }
}
