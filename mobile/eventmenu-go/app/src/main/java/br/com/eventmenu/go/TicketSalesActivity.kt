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

class TicketSalesActivity:FragmentActivity(){
 override fun onCreate(savedInstanceState:Bundle?){super.onCreate(savedInstanceState);val app=application as EventMenuGoApplication;val repo=TicketSalesRepository(BuildConfig.API_BASE_URL,DeviceIdentity.id(this),SecureSessionStore(this));setContent{var brand by remember{mutableStateOf(app.brandRepository.cached())};LaunchedEffect(Unit){brand=runCatching{app.brandRepository.load()}.getOrNull()?:brand};EventMenuTheme(brand){TicketSalesPage(repo,{finish()},{url->startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(url)))},{text->startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply{type="text/plain";putExtra(Intent.EXTRA_TEXT,text)},"Compartilhar ingresso"))})}}}
}

@Composable private fun TicketSalesPage(repo:TicketSalesRepository,onBack:()->Unit,onOpen:(String)->Unit,onShare:(String)->Unit){
 val scope=rememberCoroutineScope();var events by remember{mutableStateOf<List<TicketSaleEvent>>(emptyList())};var catalog by remember{mutableStateOf<TicketSaleCatalog?>(null)};var eventId by remember{mutableStateOf<Int?>(null)};var batchId by remember{mutableStateOf<Int?>(null)};var qty by remember{mutableIntStateOf(1)};var method by remember{mutableStateOf("cash")};var name by remember{mutableStateOf("")};var phone by remember{mutableStateOf("")};var email by remember{mutableStateOf("")};var result by remember{mutableStateOf<TicketSaleResult?>(null)};var loading by remember{mutableStateOf(false)};var error by remember{mutableStateOf<String?>(null)}
 fun loadCatalog(id:Int){scope.launch{loading=true;error=null;runCatching{repo.catalog(id)}.onSuccess{catalog=it;batchId=it.batches.firstOrNull{b->b.available>0}?.id}.onFailure{error=it.message};loading=false}}
 LaunchedEffect(Unit){loading=true;runCatching{repo.events()}.onSuccess{events=it;it.firstOrNull()?.let{e->eventId=e.id;loadCatalog(e.id)}}.onFailure{error=it.message};loading=false}
 Scaffold(topBar={TopAppBar(title={Text("Vender ingresso")},navigationIcon={TextButton(onClick=onBack){Text("Voltar")}})}){pad->LazyColumn(Modifier.fillMaxSize().padding(pad).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
  item{Text("Bilheteria presencial",style=MaterialTheme.typography.headlineSmall);Text("Venda avulsa sem dados obrigatórios. O servidor controla capacidade e gera um QR único para cada ingresso.",color=MaterialTheme.colorScheme.onSurfaceVariant)}
  error?.let{item{Text(it,color=MaterialTheme.colorScheme.error)}}
  item{var open by remember{mutableStateOf(false)};ExposedDropdownMenuBox(open,{open=it}){OutlinedTextField(events.firstOrNull{it.id==eventId}?.name.orEmpty(),{},readOnly=true,label={Text("Evento")},trailingIcon={ExposedDropdownMenuDefaults.TrailingIcon(open)},modifier=Modifier.menuAnchor().fillMaxWidth());ExposedDropdownMenu(open,{open=false}){events.forEach{e->DropdownMenuItem(text={Text(e.name)},onClick={eventId=e.id;open=false;result=null;loadCatalog(e.id)})}}}}
  catalog?.let{c->item{var open by remember{mutableStateOf(false)};val selected=c.batches.firstOrNull{it.id==batchId};ExposedDropdownMenuBox(open,{open=it}){OutlinedTextField(selected?.let{"${it.typeName.takeIf(String::isNotBlank)?.plus(" · ").orEmpty()}${it.name} · ${money(it.priceCents)} · ${it.available} disp."}.orEmpty(),{},readOnly=true,label={Text("Lote / tipo")},trailingIcon={ExposedDropdownMenuDefaults.TrailingIcon(open)},modifier=Modifier.menuAnchor().fillMaxWidth());ExposedDropdownMenu(open,{open=false}){c.batches.forEach{b->DropdownMenuItem(text={Text("${b.typeName.takeIf(String::isNotBlank)?.plus(" · ").orEmpty()}${b.name} · ${money(b.priceCents)} · ${b.available}")},enabled=b.available>0,onClick={batchId=b.id;open=false})}}}}
  item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick={if(qty>1)qty--}){Text("−")};Text("$qty ingresso(s)",modifier=Modifier.padding(top=12.dp));OutlinedButton(onClick={if(qty<20)qty++}){Text("+")}}}
  item{Text("Pagamento",style=MaterialTheme.typography.titleMedium);Row(horizontalArrangement=Arrangement.spacedBy(6.dp)){listOf("cash" to "Dinheiro","pix" to "Pix","card_pos" to "Cartão/POS").forEach{(v,l)->FilterChip(selected=method==v,onClick={method=v},label={Text(l)})}};if(catalog?.canCourtesy==true)FilterChip(selected=method=="courtesy",onClick={method="courtesy"},label={Text("Cortesia")})}
  item{Text("Comprador (opcional)",style=MaterialTheme.typography.titleMedium);Text("Deixe vazio para venda avulsa. Nenhum cliente fictício será criado.",color=MaterialTheme.colorScheme.onSurfaceVariant);OutlinedTextField(name,{name=it},label={Text("Nome")},modifier=Modifier.fillMaxWidth());OutlinedTextField(phone,{phone=it},label={Text("Telefone")},modifier=Modifier.fillMaxWidth());OutlinedTextField(email,{email=it},label={Text("E-mail")},modifier=Modifier.fillMaxWidth())}
  item{Button(onClick={val e=eventId;val b=batchId;if(e!=null&&b!=null)scope.launch{loading=true;error=null;runCatching{repo.sell(e,b,qty,method,name,phone,email)}.onSuccess{result=it;loadCatalog(e)}.onFailure{error=it.message};loading=false}},enabled=!loading&&eventId!=null&&batchId!=null,modifier=Modifier.fillMaxWidth()){Text(if(loading)"Processando..." else "Concluir venda")}}
  result?.let{s->item{Card{Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text("Venda #${s.orderId}",style=MaterialTheme.typography.titleLarge);Text(if(s.anonymous)"Comprador não identificado" else "Venda identificada");Text("${s.tickets.size} ingresso(s) · ${money(s.totalCents)} · ${if(s.paymentStatus=="paid")"Pago" else "Pagamento pendente"}");if(s.paymentStatus!="paid")Button(onClick={onOpen(BuildConfig.API_BASE_URL.trimEnd('/')+"/pedido.php?t="+s.publicToken)},modifier=Modifier.fillMaxWidth()){Text("Abrir pagamento / Pix")}}}}
  items(s.tickets){t->Card{Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text(t.code,style=MaterialTheme.typography.titleMedium);val url=BuildConfig.API_BASE_URL.trimEnd('/')+"/ingresso.php?t="+t.qrToken;Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick={onOpen(url)}){Text("Ver ingresso")};OutlinedButton(onClick={onShare(url)}){Text("Compartilhar")}}}}}}
 }}
}
private fun money(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
