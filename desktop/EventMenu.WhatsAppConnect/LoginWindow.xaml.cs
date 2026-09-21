using System.Windows;
using EventMenu.WhatsAppConnect.Services;

namespace EventMenu.WhatsAppConnect;

public partial class LoginWindow : Window
{
    private readonly WhatsAppCloudClient _cloud;

    public LoginWindow(WhatsAppCloudClient cloud)
    {
        InitializeComponent();
        _cloud=cloud;
        Loaded+=(_,_)=>EmailBox.Focus();
    }

    private async void LoginButton_Click(object sender,RoutedEventArgs e)=>await LoginAsync();

    private async void PasswordBox_KeyDown(object sender,System.Windows.Input.KeyEventArgs e)
    {
        if(e.Key==System.Windows.Input.Key.Enter)await LoginAsync();
    }

    private async Task LoginAsync()
    {
        var email=EmailBox.Text.Trim();
        var password=PasswordBox.Password;
        if(string.IsNullOrWhiteSpace(email)||string.IsNullOrWhiteSpace(password))
        {
            StatusText.Text="Informe e-mail e senha.";
            return;
        }

        LoginButton.IsEnabled=false;
        StatusText.Text="Entrando...";
        try
        {
            await _cloud.LoginAsync(email,password);
            PasswordBox.Clear();
            DialogResult=true;
            Close();
        }
        catch(Exception ex)
        {
            StatusText.Text=ex.Message;
        }
        finally
        {
            LoginButton.IsEnabled=true;
        }
    }

    private void CancelButton_Click(object sender,RoutedEventArgs e)
    {
        DialogResult=false;
        Close();
    }
}
