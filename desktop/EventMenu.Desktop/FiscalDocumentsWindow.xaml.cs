using System.Windows;
using System.Windows.Controls;
using EventMenu.Desktop.Models;
using EventMenu.Desktop.Services;

namespace EventMenu.Desktop;

public partial class FiscalDocumentsWindow : Window
{
    private readonly DesktopIntegrationApiClient _api;
    private bool _loading;

    public FiscalDocumentsWindow(DesktopIntegrationApiClient api)
    {
        InitializeComponent();
        _api=api;
        Loaded+=async(_,_)=>await LoadAsync();
    }

    private async Task LoadAsync()
    {
        if(_loading)return;
        _loading=true;
        try
        {
            DetailText.Text="Carregando documentos fiscais...";
            var response=await _api.FiscalDocumentsAsync(200);
            DocumentsGrid.ItemsSource=response.Documents;
            DocumentsGrid.SelectedIndex=response.Documents.Count>0?0:-1;
            DetailText.Text=response.Documents.Count==0
                ?"Nenhum documento fiscal preparado ainda."
                :$"{response.Documents.Count} documento(s). Somente status 'authorized' significa autorização confirmada pela SEFAZ.";
        }
        catch(Exception ex)
        {
            DetailText.Text=Friendly(ex.Message);
        }
        finally{_loading=false;}
    }

    private void DocumentsGrid_SelectionChanged(object sender,SelectionChangedEventArgs e)
    {
        if(DocumentsGrid.SelectedItem is not FiscalDocument document)return;
        var type=document.Model=="65"?"NFC-e":"NF-e";
        DetailText.Text=document.Status switch
        {
            "authorized"=>$"{type} autorizada • chave {document.AccessKey} • protocolo {document.Protocol}",
            "rejected"=>$"{type} rejeitada • {document.RejectionCode} • {document.RejectionMessage}",
            "error"=>$"{type} com erro técnico • {document.RejectionMessage}",
            "processing"=>$"{type} em processamento. Aguarde a confirmação oficial antes de considerar emitida.",
            "queued"=>$"{type} na fila. Ainda não foi autorizada pela SEFAZ.",
            "cancelled"=>$"{type} cancelada.",
            "contingency"=>$"{type} em contingência. Exige tratamento fiscal posterior.",
            _=>$"{type} • status {document.Status}"
        };
    }

    private async void RefreshButton_Click(object sender,RoutedEventArgs e)=>await LoadAsync();
    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();

    private static string Friendly(string message)
    {
        var lower=message.ToLowerInvariant();
        return lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception")
            ?"Não foi possível consultar os documentos fiscais."
            :message;
    }
}
