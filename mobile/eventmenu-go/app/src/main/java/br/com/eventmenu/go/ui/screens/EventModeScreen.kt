package br.com.eventmenu.go.ui.screens

import android.app.Activity
import android.content.Intent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.EventOrderPickupViewModel
import br.com.eventmenu.go.TicketSalesActivity
import br.com.eventmenu.go.data.EventEntry
import br.com.eventmenu.go.data.EventOverview
import br.com.eventmenu.go.data.EventPickupOrder
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning

@Composable
fun EventModeScreen(events:List<EventOverview>,selectedEventId:Int?,entries:List<EventEntry>,canScanTicket:Boolean,canScanGuest:Boolean,canUseBar:Boolean,onSelect:(Int)->Unit,onOpenBar:(Int)->Unit,onRefresh:()->Unit,onScan:()->Unit){
 val selected=events.firstOrNull{it.id==selectedEventId}?:events.firstOrNull();val context=LocalContext.current;val app=context.applicationContext as EventMenuGoApplication
 val pickupViewModel:EventOrderPickupViewModel=viewModel(factory=EventOrderPickupViewModel.Factory(app.eventRepository));val pickupState by pickupViewModel.state.collectAsState()
 fun scanOrder(eventId:Int){val activity=context as? Activity?:return;val options=GmsBarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_QR_CODE,Barcode.FORMAT_AZTEC,Barcode.FORMAT_CODE_128).enableAutoZoom().build();GmsBarcodeScanning.getClient(activity,options).startScan().addOnSuccessListener{b->b.rawValue?.takeIf{it.isNotBlank()}?.let{pickupViewModel.resolve(eventId,it)}}}
 LaunchedEffect(selected?.id){pickupViewModel.dismiss()};LaunchedEffect(pickupState.completedVersion){if(pickupState.completedVersion>0)onRefresh()}
 LazyColumn(Modifier.fillMaxSize().padding(14.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
  item{Text("Modo Evento",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black);Text("Entrada, bilheteria, pedidos e operação do evento em um só lugar.")}
  item{Button(onClick={context.startActivity(Intent(context,TicketSalesActivity::class.java))},modifier=Modifier.fillMaxWidth().heightIn(min=56.dp)){Text("🎟 VENDER INGRESSO")}}
  if(events.isNotEmpty())item{LazyRow(horizontalArrangement=Arrangement.spacedBy(8.dp)){items(events,key={it.id}){e->FilterChip(selected=e.id==selected?.id,onClick={onSelect(e.id)},label={Text(e.name)})}}}
  if(selected==null)item{Text("Nenhum evento disponível para operação neste turno.")}else{
   item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(18.dp),verticalArrangement=Arrangement.spacedBy(6.dp)){Text(selected.name,style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);if(selected.venue.isNotBlank())Text(selected.venue);if(selected.address.isNotBlank())Text(selected.address);Text("Início: ${friendlyEventDate(selected.startsAt)}");Text(eventStatus(selected.status))}}}
   item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp)){EventMetric("Ingressos",selected.ticketsPaid.toString(),Modifier.weight(1f));EventMetric("Check-ins",selected.ticketsCheckedIn.toString(),Modifier.weight(1f))}}
   item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp)){EventMetric("Convidados",selected.guestsPending.toString(),Modifier.weight(1f));EventMetric("Entraram",selected.guestsCheckedIn.toString(),Modifier.weight(1f))}}
   item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp)){EventMetric("Ingressos R$",eventMoney(selected.ticketRevenueCents),Modifier.weight(1f));EventMetric("Bar R$",eventMoney(selected.barRevenueCents),Modifier.weight(1f))}}
   item{EventMetric("Receita confirmada",eventMoney(selected.revenueCents),Modifier.fillMaxWidth())}
   if(selected.ticketsReserved>0)item{Text("${selected.ticketsReserved} ingresso(s) aguardando confirmação.")}
   if(canScanTicket||canScanGuest)item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){Text("Entrada",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("Leia o QR do ingresso ou da lista de convidados.",color=MaterialTheme.colorScheme.onSurfaceVariant);Button(onClick=onScan,modifier=Modifier.fillMaxWidth()){Text(if(canScanTicket&&canScanGuest)"🎟 LER INGRESSO / CONVIDADO" else if(canScanTicket)"🎟 LER INGRESSO" else "👤 LER CONVIDADO")}}}}
   if(canUseBar&&selected.status=="published")item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){Text("Pedidos e bar",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("Venda no balcão ou leia o QR de um pedido para liberar a retirada.",color=MaterialTheme.colorScheme.onSurfaceVariant);Button(onClick={scanOrder(selected.id)},modifier=Modifier.fillMaxWidth()){Text("📷 LER QR DO PEDIDO")};OutlinedButton(onClick={onOpenBar(selected.id)},modifier=Modifier.fillMaxWidth()){Text("🍹 ABRIR BAR / NOVA VENDA")};if(pickupState.loading)CircularProgressIndicator();pickupState.error?.let{Text(it,color=MaterialTheme.colorScheme.error,fontWeight=FontWeight.SemiBold)}}}}
   item{Text("Últimos acessos",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)}
   if(entries.isEmpty())item{Text("Nenhum check-in recente para este evento.")}else items(entries,key={"${it.type}-${it.id}-${it.checkedInAt}"}){entry->EventEntryCard(entry)}
  }
  item{OutlinedButton(onClick=onRefresh,modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR EVENTO")}}
 }
 pickupState.order?.let{order->EventOrderPickupDialog(order,pickupState.loading,pickupViewModel::deliver,pickupViewModel::dismiss)}
}

@Composable private fun EventOrderPickupDialog(order:EventPickupOrder,loading:Boolean,onDeliver:()->Unit,onDismiss:()->Unit){AlertDialog(onDismissRequest=onDismiss,title={Text("Pedido #${order.id}")},text={LazyColumn(Modifier.fillMaxWidth().heightIn(max=520.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){item{Text(order.eventName,fontWeight=FontWeight.Bold);order.customerName.takeIf{it.isNotBlank()}?.let{Text(it)};Text("${eventPaymentLabel(order.paymentStatus)} · ${eventOrderStatus(order.status)}",fontWeight=FontWeight.SemiBold)};if(order.alreadyDelivered)item{Text("⚠️ Este pedido já foi entregue.",color=MaterialTheme.colorScheme.error)}else if(order.paymentStatus!="paid"&&order.totalCents>0)item{Text("Pagamento ainda não confirmado.",color=MaterialTheme.colorScheme.error)};item{HorizontalDivider();Text("Itens",fontWeight=FontWeight.Black)};items(order.items,key={it.id}){i->Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("${eventQty(i.quantity)}× ${i.name}");Text(eventMoney(i.totalCents),fontWeight=FontWeight.Bold)}}}},confirmButton={if(order.canDeliver&&!order.alreadyDelivered)Button(onClick=onDeliver,enabled=!loading){Text(if(loading)"LIBERANDO..." else "CONFIRMAR RETIRADA")}else TextButton(onClick=onDismiss){Text("OK")}},dismissButton={if(order.canDeliver&&!order.alreadyDelivered)TextButton(onClick=onDismiss){Text("CANCELAR")}})}

@Composable private fun EventMetric(label:String,value:String,modifier:Modifier=Modifier){Card(modifier){Column(Modifier.padding(13.dp)){Text(label,style=MaterialTheme.typography.labelMedium,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(value,style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)}}}
private fun friendlyEventDate(v:String)=v.replace('T',' ').take(16).ifBlank{"—"}
private fun eventStatus(v:String)=when(v){"published"->"Publicado";"draft"->"Rascunho";"cancelled"->"Cancelado";else->v}
private fun eventPaymentLabel(v:String)=when(v){"paid"->"Pago";"pending"->"Pendente";else->v}
private fun eventOrderStatus(v:String)=when(v){"ready"->"Pronto";"completed"->"Concluído";else->v}
private fun eventQty(v:Double)=if(v%1.0==0.0)v.toInt().toString() else v.toString()
private fun eventMoney(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')