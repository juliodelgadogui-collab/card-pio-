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

    private async Task LoadAsync(long? selectId=null)
    {
        if(_loading)return;
        _loading=true;
        RetryButton.IsEnabled=false;
        try
        {
            DetailText.Text="Carregando documentos fiscais...";
            var response=await _api.FiscalDocumentsAsync(200);
            DocumentsGrid.ItemsSource=response.Documents;
            var selected=selectId.HasValue?response.Documents.FirstOrDefault(d=>d.Id==selectId.Value):response.Documents.FirstOrDefault();
            DocumentsGrid.SelectedItem=selected;
            if(selected is null)
                DetailText.Text="Nenhum documento fiscal preparado ainda.";
        }
        catch(Exception ex)
        {
            DetailText.Text=Friendly(ex.Message);
        }
        finally{_loading=false;UpdateSelectedState();}
    }

    private void DocumentsGrid_SelectionChanged(object sender,SelectionChangedEventArgs e)=>UpdateSelectedState();

    private void UpdateSelectedState()
    {
        var document=DocumentsGrid.SelectedItem as FiscalDocument;
        RetryButton.IsEnabled=!_loading&&document?.Status=="error";
        if(document is null)return;

        var type=document.Model=="65"?"NFC-e":"NF-e";
        DetailText.Text=document.Status switch
        {
            "authorized"=>$"{type} autorizada • chave {document.AccessKey} • protocolo {document.Protocol}",
            "rejected"=>$"{type} rejeitada pela SEFAZ • {document.RejectionCode} • {document.RejectionMessage}. Corrija a causa fiscal; este documento não será reenfileirado automaticamente.",
            "error"=>$"{type} com erro técnico • {document.RejectionMessage}. O mesmo snapshot e a mesma identidade fiscal serão preservados no reenvio.",
            "processing"=>$"{type} em processamento. Aguarde a confirmação oficial antes de considerar emitida.",
            "queued"=>$"{type} na fila. Ainda não foi autorizada pela SEFAZ.",
            "cancelled"=>$"{type} cancelada.",
            "contingency"=>$"{type} em contingência. Exige tratamento fiscal posterior.",
            _=>$"{type} • status {document.Status}"
        };
    }

    private async void RetryButton_Click(object sender,RoutedEventArgs e)
    {
        if(_loading||DocumentsGrid.SelectedItem is not FiscalDocument document||document.Status!="error")return;
        var answer=MessageBox.Show(
            "Reenfileirar este documento após uma falha técnica?\n\nO EventMenu preservará o snapshot, série, número e identidade fiscal. Rejeições da SEFAZ não usam este fluxo.",
            "Reenvio fiscal",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);
        if(answer!=MessageBoxResult.Yes)return;

        _loading=true;
        RetryButton.IsEnabled=false;
        try
        {
            DetailText.Text="Reenfileirando erro técnico...";
            var response=await _api.RetryFiscalAsync(document.Id);
            var updated=response.Document??throw new InvalidOperationException("O servidor não confirmou o reenvio fiscal.");
            await LoadAfterRetryAsync(updated.Id);
        }
        catch(Exception ex)
        {
            DetailText.Text=Friendly(ex.Message);
        }
        finally
        {
            _loading=false;
            UpdateSelectedState();
        }
    }

    private async Task LoadAfterRetryAsync(long documentId)
    {
        // LoadAsync possui sua própria trava para ações de UI; aqui recarregamos diretamente
        // mantendo o ID selecionado depois de uma resposta válida do servidor.
        var response=await _api.FiscalDocumentsAsync(200);
        DocumentsGrid.ItemsSource=response.Documents;
        DocumentsGrid.SelectedItem=response.Documents.FirstOrDefault(d=>d.Id==documentId);
        DetailText.Text="Documento reenfileirado. Ele continua sem autorização até o transmissor receber e validar a resposta oficial da SEFAZ.";
    }

    private async void RefreshButton_Click(object sender,RoutedEventArgs e)=>await LoadAsync((DocumentsGrid.SelectedItem as FiscalDocument)?.Id);
    private void CloseButton_Click(object sender,RoutedEventArgs e)=>Close();

    private static string Friendly(string message)
    {
        var lower=message.ToLowerInvariant();
        return lower.Contains("sqlstate")||lower.Contains("stack trace")||lower.Contains("exception")
            ?"Não foi possível concluir a operação fiscal."
            :message;
    }
}
