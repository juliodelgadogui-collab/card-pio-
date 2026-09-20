package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.ui.theme.EventMenuUi
import br.com.eventmenu.go.ui.theme.LocalTenantBrand

private data class DashboardShortcutItem(val icon: ImageVector,val label: String,val caption: String,val screen: AppScreen)

@Composable
fun RoleDashboardScreen(state:GoState,onNavigate:(AppScreen)->Unit,onScan:()->Unit,onRefresh:()->Unit){
 val session=state.session?:return;val permissions=session.permissions;val mode=state.mode?:return;val shift=state.workShift;val brand=LocalTenantBrand.current
 val tenant=brand?.displayName?.takeIf{it.isNotBlank()}?:session.user.tenantName.ifBlank{"Sua empresa"};val unit=dashboardUnitName(shift?.unitName);val shiftTime=shift?.startedAt?.takeIf{it.isNotBlank()}?.let(::dashboardTime)
 // Eventos precisa entrar antes do limite visual. Antes ele era o 9º item para administradores
 // e era descartado pelo take(8), exatamente o caso visto em produção.
 val shortcuts=buildList{
  if("cancellation_approve" in permissions||"discount_approve" in permissions||"reports" in permissions)add(DashboardShortcutItem(Icons.Default.Assessment,"Gestão","Indicadores e aprovações",AppScreen.MANAGER))
  if(mode==AppMode.EVENTS||"events" in permissions||"tickets" in permissions)add(DashboardShortcutItem(Icons.Default.ConfirmationNumber,"Eventos","Ingressos e venda presencial",AppScreen.EVENTS))
  if("orders_view" in permissions||"orders_manage" in permissions)add(DashboardShortcutItem(Icons.Default.ReceiptLong,"Pedidos","Fila e detalhes",AppScreen.ORDERS))
  if("tables" in permissions)add(DashboardShortcutItem(Icons.Default.TableRestaurant,"Mesas","Contas e consumo",AppScreen.TABLES))
  if("orders_create" in permissions)add(DashboardShortcutItem(Icons.Default.PointOfSale,"Nova venda","Abrir PDV",AppScreen.POS))
  if("cash" in permissions)add(DashboardShortcutItem(Icons.Default.AccountBalanceWallet,"Caixa","Movimentos e saldo",AppScreen.CASH))
  if("orders_kitchen" in permissions)add(DashboardShortcutItem(Icons.Default.Restaurant,"Cozinha","Produção agora",AppScreen.KITCHEN))
  if("orders_dispatch" in permissions||"delivery_assign" in permissions)add(DashboardShortcutItem(Icons.Default.LocalShipping,"Saída","Separar e despachar",AppScreen.DISPATCH))
  if(mode==AppMode.DELIVERY||"orders_delivery" in permissions)add(DashboardShortcutItem(Icons.Default.DeliveryDining,"Entregas","Minhas rotas",AppScreen.DELIVERY))
  add(DashboardShortcutItem(Icons.Default.MoreHoriz,"Mais","Perfil e dispositivo",AppScreen.PROFILE))
 }.distinctBy{it.label}.take(8)
 LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(horizontal=EventMenuUi.SpaceMd,vertical=EventMenuUi.SpaceMd),verticalArrangement=Arrangement.spacedBy(EventMenuUi.SpaceMd)){
  item{OperationalHero(firstName(session.user.name),tenant,unit,mode,shiftTime)}
  item{SectionTitle("Ações rápidas",modeHint(mode))}
  shortcuts.chunked(2).forEach{row->item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(EventMenuUi.SpaceSm)){row.forEach{s->DashboardShortcut(s,Modifier.weight(1f)){onNavigate(s.screen)}};if(row.size==1)Spacer(Modifier.weight(1f))}}}
  item{PairingCard{onNavigate(AppScreen.PROFILE)}}
  item{SectionTitle("Agora",summaryHint(mode))}
  when(mode){
   AppMode.DELIVERY->{val d=state.orders.filter{it.channel=="delivery"&&it.status !in setOf("completed","cancelled")};item{MetricRow("Para retirar",d.count{it.status=="ready"}.toString(),"Em rota",d.count{it.status=="out_for_delivery"}.toString())};item{MetricRow("Recebido",dashboardMoney(d.filter{it.paymentStatus=="paid"}.sumOf{it.totalCents}),"A repassar",dashboardMoney(state.deliveryCash?.outstandingCents?:0),true,true)};item{PrimaryOperationButton(Icons.Default.DeliveryDining,"Abrir minhas entregas"){onNavigate(AppScreen.DELIVERY)}}}
   AppMode.EVENTS->{val e=state.events.firstOrNull{it.id==state.selectedEventId}?:state.events.firstOrNull();if(e==null)item{DashboardCard("Nenhum evento disponível","Atualize a operação ou confirme seu acesso.")}else{item{DashboardCard(e.name,e.venue.ifBlank{"Evento selecionado"})};item{MetricRow("Ingressos",e.ticketsPaid.toString(),"Check-ins",e.ticketsCheckedIn.toString())};item{MetricRow("Convidados",e.guestsPending.toString(),"Entraram",e.guestsCheckedIn.toString())};item{MetricRow("Ingressos",dashboardMoney(e.ticketRevenueCents),"Bar",dashboardMoney(e.barRevenueCents),true,true)}};if("tickets" in permissions||"guests" in permissions)item{PrimaryOperationButton(Icons.Default.QrCodeScanner,"Ler ingresso ou convidado",onScan)}}
   AppMode.PAY->{val c=state.cashSummary;item{DashboardStatusCard("Caixa",if(state.cashOpen)"Aberto" else "Fechado",state.cashOpen)};item{MetricRow("Saldo esperado",dashboardMoney(c?.expectedCashCents?:0),"Movimentos",(c?.movements?.size?:0).toString(),true)};if("cash" in permissions)item{PrimaryOperationButton(Icons.Default.AccountBalanceWallet,"Abrir caixa"){onNavigate(AppScreen.CASH)}}}
   AppMode.OPERATION->{val m=state.managerOverview;val revenue=m?.revenueTodayCents?:state.orders.filter{it.paymentStatus=="paid"}.sumOf{it.totalCents};val active=m?.ordersNow?:state.orders.count{it.status !in setOf("completed","cancelled")};item{MetricRow("Vendas",dashboardMoney(revenue),"Pedidos",active.toString(),true)};when{ "orders_create" in permissions->item{PrimaryOperationButton(Icons.Default.PointOfSale,"Nova venda"){onNavigate(AppScreen.POS)}};"orders_kitchen" in permissions->item{PrimaryOperationButton(Icons.Default.Restaurant,"Abrir cozinha"){onNavigate(AppScreen.KITCHEN)}}}}
  }
  item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(EventMenuUi.SpaceSm)){OutlinedButton(onClick=onScan,modifier=Modifier.weight(1f).heightIn(min=EventMenuUi.ActionHeight)){Icon(Icons.Default.QrCodeScanner,null);Spacer(Modifier.width(8.dp));Text("Ler QR")};OutlinedButton(onClick=onRefresh,modifier=Modifier.weight(1f).heightIn(min=EventMenuUi.ActionHeight)){Icon(Icons.Default.Refresh,null);Spacer(Modifier.width(8.dp));Text("Atualizar")}}}
 }
}
@Composable private fun OperationalHero(userName:String,tenant:String,unit:String,mode:AppMode,shiftTime:String?){Card(Modifier.fillMaxWidth(),colors=CardDefaults.cardColors(containerColor=MaterialTheme.colorScheme.primaryContainer)){Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){Text("Olá, $userName",style=MaterialTheme.typography.headlineMedium);Text(tenant,style=MaterialTheme.typography.titleMedium,color=MaterialTheme.colorScheme.primary);HorizontalDivider();Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(18.dp)){InfoLine(Icons.Default.Storefront,"Unidade",unit,Modifier.weight(1f));InfoLine(Icons.Default.Schedule,"Turno",shiftTime?.let{"Desde $it"}?:"Em andamento",Modifier.weight(1f))}}}}
@Composable private fun InfoLine(icon:ImageVector,label:String,value:String,modifier:Modifier=Modifier){Row(modifier,horizontalArrangement=Arrangement.spacedBy(8.dp),verticalAlignment=Alignment.CenterVertically){Icon(icon,null,tint=MaterialTheme.colorScheme.primary);Column{Text(label,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(value,style=MaterialTheme.typography.titleMedium)}}}
@Composable private fun SectionTitle(title:String,subtitle:String){Column{Text(title,style=MaterialTheme.typography.titleLarge);Text(subtitle,color=MaterialTheme.colorScheme.onSurfaceVariant)}}
@Composable private fun PairingCard(onClick:()->Unit){Card(onClick=onClick,modifier=Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(16.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(13.dp)){Icon(Icons.Default.Computer,null,tint=MaterialTheme.colorScheme.primary);Column(Modifier.weight(1f)){Text("Conectar ao EventMenu Desktop",fontWeight=FontWeight.Bold);Text("Pareie este celular pelo Perfil usando o QR Code.",color=MaterialTheme.colorScheme.onSurfaceVariant)};Icon(Icons.Default.ChevronRight,null)}}}
@Composable private fun DashboardShortcut(item:DashboardShortcutItem,modifier:Modifier=Modifier,onClick:()->Unit){Card(onClick=onClick,modifier=modifier.heightIn(min=116.dp)){Column(Modifier.fillMaxSize().padding(15.dp),verticalArrangement=Arrangement.SpaceBetween){Icon(item.icon,null,tint=MaterialTheme.colorScheme.primary);Column{Text(item.label,fontWeight=FontWeight.Bold);Text(item.caption,color=MaterialTheme.colorScheme.onSurfaceVariant)}}}}
@Composable private fun PrimaryOperationButton(icon:ImageVector,label:String,onClick:()->Unit){Button(onClick=onClick,modifier=Modifier.fillMaxWidth().heightIn(min=EventMenuUi.ActionHeight)){Icon(icon,null);Spacer(Modifier.width(9.dp));Text(label)}}
@Composable private fun MetricRow(labelA:String,valueA:String,labelB:String,valueB:String,emphasizeA:Boolean=false,emphasizeB:Boolean=false){Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(EventMenuUi.SpaceSm)){DashboardMetric(labelA,valueA,Modifier.weight(1f),emphasizeA);DashboardMetric(labelB,valueB,Modifier.weight(1f),emphasizeB)}}
@Composable private fun DashboardMetric(label:String,value:String,modifier:Modifier=Modifier,emphasized:Boolean=false){Card(modifier){Column(Modifier.padding(15.dp)){Text(label,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(value,style=MaterialTheme.typography.titleLarge,color=if(emphasized)MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurface)}}}
@Composable private fun DashboardCard(title:String,text:String){Card(Modifier.fillMaxWidth()){Column(Modifier.padding(17.dp)){Text(title,style=MaterialTheme.typography.titleLarge);Text(text,color=MaterialTheme.colorScheme.onSurfaceVariant)}}}
@Composable private fun DashboardStatusCard(title:String,status:String,active:Boolean){Card(Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(17.dp),horizontalArrangement=Arrangement.SpaceBetween){Text(title);Text(status,color=if(active)MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error)}}}
private fun firstName(name:String)=name.trim().substringBefore(' ').ifBlank{"Operador"}
private fun dashboardUnitName(name:String?)=name?.takeIf{it.isNotBlank()}?:"Unidade atual"
private fun dashboardTime(value:String)=value.takeLast(8).take(5)
private fun dashboardMoney(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
private fun modeHint(mode:AppMode)=when(mode){AppMode.DELIVERY->"Prioridade para rota e entrega.";AppMode.EVENTS->"Entrada, ingressos e evento.";AppMode.PAY->"Recebimentos e caixa.";AppMode.OPERATION->"Só aparecem funções liberadas para o seu perfil."}
private fun summaryHint(mode:AppMode)=when(mode){AppMode.DELIVERY->"O que precisa sair agora.";AppMode.EVENTS->"Movimento do evento selecionado.";AppMode.PAY->"Resumo financeiro do turno.";AppMode.OPERATION->"Resumo do turno."}