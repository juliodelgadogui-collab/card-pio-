package br.com.eventmenu.go

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import br.com.eventmenu.go.data.*
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore
import br.com.eventmenu.go.ui.theme.EventMenuTheme
import kotlinx.coroutines.launch

class TicketSalesActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as EventMenuGoApplication
        val repo = TicketSalesRepository(BuildConfig.API_BASE_URL, DeviceIdentity.id(this), SecureSessionStore(this))
        setContent {
            var brand by remember { mutableStateOf(app.brandRepository.cached()) }
            LaunchedEffect(Unit) { brand = runCatching { app.brandRepository.load() }.getOrNull() ?: brand }
            EventMenuTheme(brand) {
                TicketSalesPage(repo, { finish() }, { url -> startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }, { text ->
                    startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply { type="text/plain"; putExtra(Intent.EXTRA_TEXT,text) }, "Compartilhar ingresso"))
                }, { url ->
                    // The printable ticket remains server-rendered so the QR/code is exactly the
                    // same ticket already issued. Android's print-capable browser handles 58/80mm
                    // printer plugins as well as standard system print services.
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url + if (url.contains('?')) "&print=1" else "?print=1")))
                })
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TicketSalesPage(repo: TicketSalesRepository,onBack:()->Unit,onOpen:(String)->Unit,onShare:(String)->Unit,onPrint:(String)->Unit) {
    val scope=rememberCoroutineScope(); var events by remember{mutableStateOf<List<TicketSaleEvent>>(emptyList())}; var catalog by remember{mutableStateOf<TicketSaleCatalog?>(null)}
    var eventId by remember{mutableStateOf<Int?>(null)}; var batchId by remember{mutableStateOf<Int?>(null)}; var qty by remember{mutableIntStateOf(1)}; var method by remember{mutableStateOf("cash")}
    var name by remember{mutableStateOf("")}; var phone by remember{mutableStateOf("")}; var email by remember{mutableStateOf("")}; var result by remember{mutableStateOf<TicketSaleResult?>(null)}
    var loading by remember{mutableStateOf(false)}; var error by remember{mutableStateOf<String?>(null)}
    fun loadCatalog(id:Int){scope.launch{loading=true;error=null;runCatching{repo.catalog(id)}.onSuccess{catalog=it;batchId=it.batches.firstOrNull{b->b.available>0}?.id;qty=1}.onFailure{error=it.message};loading=false}}
    LaunchedEffect(Unit){loading=true;runCatching{repo.events()}.onSuccess{events=it;it.firstOrNull()?.let{e->eventId=e.id;loadCatalog(e.id)}}.onFailure{error=it.message};loading=false}
    val selectedBatch=catalog?.batches?.firstOrNull{it.id==batchId}; val maxQty=(selectedBatch?.available?:0).coerceAtMost(20); val total=(selectedBatch?.priceCents?:0)*qty
    Scaffold(topBar={TopAppBar(title={Text("Vender ingresso")},navigationIcon={TextButton(onClick=onBack){Text("Voltar")}})}){padding->
        LazyColumn(Modifier.fillMaxSize().padding(padding).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
            item{Text("Bilheteria presencial",style=MaterialTheme.typography.headlineSmall);Text("Venda avulsa sem dados obrigatórios. Cada ingresso recebe QR único.",color=MaterialTheme.colorScheme.onSurfaceVariant)}
            error?.let{m->item{Text(m,color=MaterialTheme.colorScheme.error)}}
            item{var open by remember{mutableStateOf(false)};ExposedDropdownMenuBox(open,{open=it}){OutlinedTextField(events.firstOrNull{it.id==eventId}?.name.orEmpty(),{},readOnly=true,label={Text("Evento")},trailingIcon={ExposedDropdownMenuDefaults.TrailingIcon(open)},modifier=Modifier.menuAnchor().fillMaxWidth());ExposedDropdownMenu(open,{open=false}){events.forEach{e->DropdownMenuItem({Text(e.name)},{eventId=e.id;open=false;result=null;loadCatalog(e.id)})}}}}
            catalog?.let{c->item{var open by remember{mutableStateOf(false)};ExposedDropdownMenuBox(open,{open=it}){OutlinedTextField(selectedBatch?.name.orEmpty(),{},readOnly=true,label={Text("Lote")},trailingIcon={ExposedDropdownMenuDefaults.TrailingIcon(open)},modifier=Modifier.menuAnchor().fillMaxWidth());ExposedDropdownMenu(open,{open=false}){c.batches.forEach{b->DropdownMenuItem({Text("${b.name} · ${formatMoney(b.priceCents)} · ${b.available} disponíveis")},enabled=b.available>0,onClick={batchId=b.id;qty=1;open=false})}}}}}
            selectedBatch?.let{b->item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(5.dp)){Text("Ingresso",style=MaterialTheme.typography.labelLarge);Text(b.typeName.ifBlank{"Ingresso geral"},style=MaterialTheme.typography.titleLarge);Text("Lote: ${b.name}");Text("Valor unitário: ${formatMoney(b.priceCents)}");Text("Disponíveis: ${b.available}")}}}}
            item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton({if(qty>1)qty--}){Text("−")};Text("$qty ingresso(s)",modifier=Modifier.padding(top=12.dp));OutlinedButton({if(qty<maxQty)qty++},enabled=qty<maxQty){Text("+")}}}
            if(selectedBatch!=null)item{Card(Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(16.dp),horizontalArrangement=Arrangement.SpaceBetween){Text("Total",style=MaterialTheme.typography.titleMedium);Text(formatMoney(total),style=MaterialTheme.typography.titleLarge)}}}
            item{Text("Pagamento",style=MaterialTheme.typography.titleMedium);Row(horizontalArrangement=Arrangement.spacedBy(6.dp)){listOf("cash" to "Dinheiro","pix" to "Pix","card_pos" to "Cartão/POS").forEach{(v,l)->FilterChip(method==v,{method=v},{Text(l)})}};if(catalog?.canCourtesy==true)FilterChip(method=="courtesy",{method="courtesy"},{Text("Cortesia")})}
            item{Text("Comprador (opcional)",style=MaterialTheme.typography.titleMedium);Text("Deixe vazio para venda avulsa. Nenhum cliente fictício será criado.",color=MaterialTheme.colorScheme.onSurfaceVariant);OutlinedTextField(name,{name=it},label={Text("Nome")},modifier=Modifier.fillMaxWidth());OutlinedTextField(phone,{phone=it},label={Text("Telefone")},modifier=Modifier.fillMaxWidth());OutlinedTextField(email,{email=it},label={Text("E-mail")},modifier=Modifier.fillMaxWidth())}
            item{Button(onClick={val e=eventId;val b=batchId;if(e!=null&&b!=null)scope.launch{loading=true;error=null;runCatching{repo.sell(e,b,qty,method,name,phone,email)}.onSuccess{result=it;loadCatalog(e)}.onFailure{error=it.message};loading=false}},enabled=!loading&&eventId!=null&&batchId!=null&&selectedBatch!=null&&qty<=maxQty,modifier=Modifier.fillMaxWidth()){Text(if(loading)"Processando..." else "Concluir venda • ${formatMoney(total)}")}}
            result?.let{sale->
                item{Card{Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text("Venda #${sale.orderId}",style=MaterialTheme.typography.titleLarge);Text(if(sale.anonymous)"Comprador não identificado" else "Venda identificada");Text("${sale.tickets.size} ingresso(s) · ${formatMoney(sale.totalCents)} · ${if(sale.paymentStatus=="paid")"Pago" else "Pagamento pendente"}");if(sale.paymentStatus!="paid")Button({onOpen(BuildConfig.API_BASE_URL.trimEnd('/')+"/pedido.php?t="+sale.publicToken)},Modifier.fillMaxWidth()){Text("Abrir pagamento / Pix")};if(sale.paymentStatus=="paid"&&sale.tickets.size==1){val t=sale.tickets.first();val url=BuildConfig.API_BASE_URL.trimEnd('/')+"/ingresso.php?t="+t.qrToken;Button({onPrint(url)},Modifier.fillMaxWidth()){Text("Imprimir ingresso")}};if(sale.paymentStatus=="paid"&&sale.tickets.size>1)Text("Ingressos prontos para impressão individual abaixo.",color=MaterialTheme.colorScheme.onSurfaceVariant)}}}
                items(sale.tickets){t->Card{Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text(t.code,style=MaterialTheme.typography.titleMedium);val url=BuildConfig.API_BASE_URL.trimEnd('/')+"/ingresso.php?t="+t.qrToken;Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton({onOpen(url)}){Text("Ver")};OutlinedButton({onShare(url)}){Text("Compartilhar")};Button({onPrint(url)}){Text("Imprimir")}}}}}
            }
        }
    }
}
private fun formatMoney(cents:Int):String="R$ %.2f".format(cents/100.0).replace('.',',')
