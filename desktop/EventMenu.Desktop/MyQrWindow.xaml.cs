using System.Windows;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class MyQrWindow : Window
{
    private readonly UniversalQrApiClient _api;
    private readonly LocalUserQrStore _localStore;
    private readonly UserInfo _user;
    private readonly string _qrType;
    private LocalUserQrState? _state;
    private bool _busy;

    public MyQrWindow(SecureSessionStore sessionStore, bool deliveryUser)
    {
        _api = new UniversalQrApiClient(sessionStore);
        _localStore = new LocalUserQrStore();
        _user = sessionStore.Load()?.User ?? throw new InvalidOperationException("Faça login novamente.");
        _qrType = deliveryUser ? "delivery_user" : "employee";
        InitializeComponent();
        Loaded += (_, _) => LoadView();
        Closed += (_, _) => _api.Dispose();
    }

    private void LoadView()
    {
        NameText.Text = string.IsNullOrWhiteSpace(_user.Name) ? "Meu usuário" : _user.Name;
        RoleText.Text = RoleLabel(_user.Role);
        CompanyText.Text = string.IsNullOrWhiteSpace(_user.TenantName) ? "EventMenu" : _user.TenantName;
        _state = _localStore.Load(_user.TenantId, _user.Id, _qrType);
        RenderState();
    }

    private void RenderState()
    {
        if (_state is null || string.IsNullOrWhiteSpace(_state.Payload))
        {
            QrImage.Source = null;
            EmptyQrPanel.Visibility = Visibility.Visible;
            CopyButton.Visibility = Visibility.Collapsed;
            RevokeButton.Visibility = Visibility.Collapsed;
            GenerateButton.Content = "Gerar meu QR";
            ExpiryText.Text = "";
            StateTitleText.Text = "QR ainda não gerado";
            StateDetailText.Text = "Gere seu código para utilizá-lo nas operações compatíveis.";
            return;
        }

        try
        {
            QrImage.Source = QrCodeRenderer.Create(_state.Payload, 8);
            EmptyQrPanel.Visibility = Visibility.Collapsed;
            CopyButton.Visibility = Visibility.Visible;
            RevokeButton.Visibility = Visibility.Visible;
            GenerateButton.Content = "Gerar novo QR";
            ExpiryText.Text = string.IsNullOrWhiteSpace(_state.ExpiresAt)
                ? "Sem prazo definido. Você pode revogar este QR a qualquer momento."
                : $"Válido até {ServerTimeDisplay.Local(_state.ExpiresAt!)}";
            StateTitleText.Text = "QR disponível";
            StateDetailText.Text = _state.Type == "delivery_user"
                ? "Identificação do entregador pronta para leitura."
                : "Identificação do funcionário pronta para leitura.";
        }
        catch
        {
            _localStore.Clear();
            _state = null;
            RenderState();
            StatusText.Text = "O QR salvo estava inválido e foi descartado. Gere um novo código.";
        }
    }

    private async void GenerateButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy) return;
        if (_state is not null && MessageBox.Show(
                "Gerar um novo QR fará o código atual deixar de funcionar. Continuar?",
                "Meu QR",
                MessageBoxButton.YesNo,
                MessageBoxImage.Question) != MessageBoxResult.Yes) return;

        _busy = true;
        SetBusy(true);
        StatusText.Text = "Gerando seu QR...";
        try
        {
            var response = await _api.IssueAsync(_qrType, _user.Id, _user.Name, 720);
            var qr = response.Qr ?? throw new InvalidOperationException("Não foi possível gerar o QR.");
            if (string.IsNullOrWhiteSpace(qr.Payload)) throw new InvalidOperationException("O QR foi criado sem conteúdo válido.");

            _state = new LocalUserQrState
            {
                TenantId = _user.TenantId,
                Type = qr.Type,
                EntityId = qr.EntityId,
                Label = qr.Label,
                Payload = qr.Payload,
                ExpiresAt = qr.ExpiresAt
            };
            _localStore.Save(_state);
            RenderState();
            StatusText.Text = "QR gerado com sucesso.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            SetBusy(false);
        }
    }

    private async void RevokeButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || _state is null) return;
        if (MessageBox.Show(
                "Revogar este QR? Ele deixará de ser aceito imediatamente.",
                "Meu QR",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning) != MessageBoxResult.Yes) return;

        _busy = true;
        SetBusy(true);
        StatusText.Text = "Revogando QR...";
        try
        {
            await _api.RevokeAsync(_state.Type, _user.Id);
            _localStore.Clear();
            _state = null;
            RenderState();
            StatusText.Text = "QR revogado.";
        }
        catch (Exception ex)
        {
            StatusText.Text = Friendly(ex.Message);
        }
        finally
        {
            _busy = false;
            SetBusy(false);
        }
    }

    private void CopyButton_Click(object sender, RoutedEventArgs e)
    {
        if (_state is null || string.IsNullOrWhiteSpace(_state.Payload)) return;
        try
        {
            Clipboard.SetText(_state.Payload);
            StatusText.Text = "Código copiado.";
        }
        catch
        {
            StatusText.Text = "Não foi possível copiar o código.";
        }
    }

    private void SetBusy(bool busy)
    {
        GenerateButton.IsEnabled = !busy;
        CopyButton.IsEnabled = !busy;
        RevokeButton.IsEnabled = !busy;
    }

    private static string RoleLabel(string role) => role.ToLowerInvariant() switch
    {
        "delivery" => "Entregador",
        "cashier" => "Caixa",
        "attendant" => "Balconista",
        "kitchen" => "Cozinha",
        "waiter" => "Garçom",
        "manager" => "Gerente",
        "admin" => "Administrador",
        _ => string.IsNullOrWhiteSpace(role) ? "Funcionário" : role
    };

    private static string Friendly(string message)
    {
        var lower = message.ToLowerInvariant();
        return lower.Contains("sqlstate") || lower.Contains("exception") || lower.Contains("stack trace")
            ? "Não foi possível concluir esta operação. Tente novamente."
            : message;
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
