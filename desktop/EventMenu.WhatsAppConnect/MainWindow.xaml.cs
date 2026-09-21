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

    public MainWindow(bool startInBackground=false)
    {
        InitializeComponent();
        _startInBackground=startInBackground;
        _timer.Tick+=Timer_Tick;
        CreateTrayIcon();
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
        await InitializeAsync();
    }

    private async Task InitializeAsync()
    {
        try
        {
            _session=_sharedSession.Load();
            if(_session?.User is null||_session.User.TenantId<1)
            {
                _timer.Start();
                SetUnavailable("Abra o EventMenu Desktop e entre na sua conta. O Connect tentará reconhecer a sessão automaticamente.");
                return;
            }

            _tenantId=_session.User.TenantId;
            _deviceId=_sharedSession.GetOrCreateDeviceId();
            TenantText.Text=string.IsNullOrWhiteSpace(_session.User.TenantName)?"Minha empresa":_session.User.TenantName;
            DeviceText.Text=Environment.MachineName;
            _cloud?.Dispose();
            _bridge?.Dispose();
            _cloud=new WhatsAppCloudClient(_sharedSession,_deviceId);
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
        StatusText.Text=status switch
        {
            "connected"=>"Conectado",
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
        ConnectButton.IsEnabled=status is not "starting" and not "reconnecting";
        DisconnectButton.IsEnabled=status is "connected" or "qr" or "starting" or "reconnecting";

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
            QrHintText.Text=connected?"WhatsApp conectado. Você pode minimizar esta janela.":status=="reconnecting"?"Tentando reconectar a sessão salva...":"Clique em Conectar para gerar o QR Code.";
        }

        if(!string.IsNullOrWhiteSpace(state.Error))ErrorText.Text=state.Error;
        if(connected)ActivityText.Text=$"WhatsApp ativo neste computador • {DateTime.Now:HH:mm:ss}";
    }

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
            QrHintText.Text="Não foi possível exibir o QR Code. Clique em Atualizar.";
        }
    }

    private async void ConnectButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null)return;
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

    private async void DisconnectButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null)return;
        if(System.Windows.MessageBox.Show("Desconectar este WhatsApp do EventMenu? Será necessário escanear um novo QR Code para conectar novamente.","Desconectar WhatsApp",MessageBoxButton.YesNo,MessageBoxImage.Question)!=MessageBoxResult.Yes)return;
        DisconnectButton.IsEnabled=false;
        try
        {
            var state=await _bridge.LogoutAsync(_shutdown.Token);
            UpdateLocalState(state);
            if(_cloud is not null)await _cloud.HeartbeatAsync("disconnected","","",$"EventMenu WhatsApp Connect - {Environment.MachineName}",_shutdown.Token);
            PendingText.Text="0";
            ActivityText.Text="WhatsApp desconectado deste computador.";
        }
        catch(Exception ex){ErrorText.Text=ex.Message;}
        finally{DisconnectButton.IsEnabled=true;}
    }

    private async void RefreshButton_Click(object sender,RoutedEventArgs e)
    {
        if(_bridge is null||_cloud is null)
        {
            await InitializeAsync();
            return;
        }
        await SyncOnceAsync(force:true);
    }

    private void SetUnavailable(string message)
    {
        StatusText.Text="Indisponível";
        ConnectButton.IsEnabled=false;
        DisconnectButton.IsEnabled=false;
        FooterText.Text=message;
        ErrorText.Text=message;
        QrHintText.Text="Entre no EventMenu Desktop. O Connect tentará reconhecer a sessão automaticamente; você também pode clicar em Atualizar.";
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
