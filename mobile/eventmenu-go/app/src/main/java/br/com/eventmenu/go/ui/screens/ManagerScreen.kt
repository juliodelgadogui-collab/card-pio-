package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.CancellationViewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.data.CancellationRequest
import br.com.eventmenu.go.data.DiscountRequest
import br.com.eventmenu.go.data.ManagerAlert
import br.com.eventmenu.go.data.ManagerDeliveryShift
import br.com.eventmenu.go.data.ManagerDetails
import br.com.eventmenu.go.data.ManagerOverview
import br.com.eventmenu.go.data.ManagerProblemOrder
import br.com.eventmenu.go.data.ManagerReopenCandidate

@Composable
fun ManagerScreen(
    overview: ManagerOverview?,
    details: ManagerDetails?,
    pendingDiscounts: List<DiscountRequest>,
    reopenCandidates: List<ManagerReopenCandidate>,
    loading: Boolean,
    canTransferDelivery: Boolean,
    canApproveDiscount: Boolean,
    canReopenOrder: Boolean,
    onTransferDelivery: (Int, Int) -> Unit,
    onReopenOrder: (Int, String) -> Unit,
    onApproveDiscount: (Int) -> Unit,
    onRejectDiscount: (Int, String) -> Unit,
    onRefresh: () -> Unit,
) {
    val app = LocalContext.current.applicationContext as EventMenuGoApplication
    val cancellationViewModel: CancellationViewModel = viewModel(factory = CancellationViewModel.Factory(app.cancellationRepository))
    val cancellationState by cancellationViewModel.state.collectAsState()
    var transferOrder by remember { mutableStateOf<ManagerProblemOrder?>(null) }
    var rejectingDiscount by remember { mutableStateOf<DiscountRequest?>(null) }
    var rejectingCancellation by remember { mutableStateOf<CancellationRequest?>(null) }

    LaunchedEffect(Unit) { cancellationViewModel.loadPending() }
    LaunchedEffect(cancellationState.changeVersion) { if (cancellationState.changeVersion > 0) onRefresh() }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Painel do gerente", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Ações rápidas da operação. Configurações e relatórios completos continuam no painel web.")
            if (loading || cancellationState.loading) CircularProgressIndicator(Modifier.padding(top = 8.dp))
        }

        if (canApproveDiscount) {
            item {
                HorizontalDivider()
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Descontos aguardando aprovação", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text(pendingDiscounts.size.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                }
            }
            if (pendingDiscounts.isEmpty()) item { Text("Nenhuma solicitação de desconto pendente nesta unidade.") }
            items(pendingDiscounts, key = { "discount-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(managerMoney(request.requestedCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        Text("Solicitado por: ${request.requesterName.ifBlank { "Funcionário" }}")
                        Text("Motivo: ${request.reason}")
                        if (request.totalCents > 0) Text("Total atual: ${managerMoney(request.totalCents)}")
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { onApproveDiscount(request.id) }, modifier = Modifier.weight(1f)) { Text("APROVAR") }
                            OutlinedButton(onClick = { rejectingDiscount = request }, modifier = Modifier.weight(1f)) { Text("REJEITAR") }
                        }
                    }
                }
            }
        }

        if (cancellationState.error?.contains("Acesso negado", ignoreCase = true) != true) {
            item {
                HorizontalDivider()
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Cancelamentos aguardando autorização", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text(cancellationState.pending.size.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                }
            }
            if (cancellationState.pending.isEmpty()) item { Text("Nenhuma solicitação de cancelamento pendente nesta unidade.") }
            items(cancellationState.pending, key = { "cancel-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(managerMoney(request.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        if (request.customerName.isNotBlank()) Text(request.customerName)
                        Text("Solicitado por: ${request.requesterName.ifBlank { "Funcionário" }}")
                        Text("Motivo: ${request.reason}")
                        Text("${channelLabel(request.channel)} · ${request.orderStatus} · pagamento ${request.paymentStatus}")
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { cancellationViewModel.approve(request.id) }, modifier = Modifier.weight(1f)) { Text("AUTORIZAR") }
                            OutlinedButton(onClick = { rejectingCancellation = request }, modifier = Modifier.weight(1f)) { Text("REJEITAR") }
                        }
                    }
                }
            }
        }

        if (canReopenOrder) {
            item {
                HorizontalDivider()
                ManagerReopenPanel(reopenCandidates, onReopenOrder)
            }
        }

        if (overview == null) item { Text("Carregando indicadores...") }
        else {
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Pedidos agora", overview.ordersNow.toString(), Modifier.weight(1f)); ManagerMetric("Cozinha atrasada", overview.kitchenDelayed.toString(), Modifier.weight(1f)) } }
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Prontos", overview.readyOrders.toString(), Modifier.weight(1f)); ManagerMetric("Sem entregador", overview.unassignedDelivery.toString(), Modifier.weight(1f)) } }
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Entregadores online", overview.deliveryOnline.toString(), Modifier.weight(1f)); ManagerMetric("Caixas abertos", overview.cashOpen.toString(), Modifier.weight(1f)) } }
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Pagamentos pendentes", overview.pendingPayments.toString(), Modifier.weight(1f)); ManagerMetric("Faturamento hoje", managerMoney(overview.revenueTodayCents), Modifier.weight(1f)) } }
            item { Text("Alertas operacionais", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            items(overview.alerts) { alert -> ManagerAlertCard(alert) }
        }

        details?.let { data ->
            item { HorizontalDivider(); Text("Caixas abertos", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, modifier = Modifier.padding(top = 8.dp)) }
            if (data.cashSessions.isEmpty()) item { Text("Nenhum caixa financeiro aberto.") }
            items(data.cashSessions, key = { "cash-${it.id}" }) { cash -> Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) { Text("Caixa #${cash.id} · ${cash.userName}", fontWeight = FontWeight.Bold); Text("Aberto: ${cash.openedAt}"); Text("Fundo inicial: ${managerMoney(cash.openingCashCents)}") } } }

            item { Text("Entregadores em turno", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            if (data.deliveryShifts.isEmpty()) item { Text("Nenhum funcionário em turno Delivery.") }
            items(data.deliveryShifts, key = { "delivery-${it.shiftId}" }) { delivery -> Card(Modifier.fillMaxWidth()) { Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) { Column { Text(delivery.userName, fontWeight = FontWeight.Bold); Text("Desde ${delivery.startedAt}") }; Text("${delivery.activeOrders} entrega(s)", fontWeight = FontWeight.Bold) } } }

            item { Text("Pedidos que exigem atenção", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            if (data.problemOrders.isEmpty()) item { Text("Nenhum problema operacional identificado.") }
            items(data.problemOrders, key = { "problem-${it.id}" }) { order ->
                ManagerProblemCard(order, canTransferDelivery && order.channel == "delivery" && data.deliveryShifts.isNotEmpty(), { transferOrder = order })
            }
        }

        cancellationState.error?.takeIf { !it.contains("Acesso negado", ignoreCase = true) }?.let { error -> item { Text("Cancelamentos: $error") } }
        item { OutlinedButton(onClick = { cancellationViewModel.loadPending(); onRefresh() }, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR PAINEL") } }
    }

    transferOrder?.let { order -> DeliveryTransferDialog(order, details?.deliveryShifts.orEmpty(), { transferOrder = null }) { userId -> transferOrder = null; onTransferDelivery(order.id, userId) } }
    rejectingDiscount?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingDiscount = null },
            title = { Text("Rejeitar desconto · Pedido #${request.orderId}") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Desconto solicitado: ${managerMoney(request.requestedCents)}"); OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo da rejeição") }, modifier = Modifier.fillMaxWidth()) } },
            confirmButton = { Button(onClick = { rejectingDiscount = null; onRejectDiscount(request.id, reason) }) { Text("REJEITAR") } },
            dismissButton = { TextButton(onClick = { rejectingDiscount = null }) { Text("VOLTAR") } },
        )
    }
    rejectingCancellation?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingCancellation = null },
            title = { Text("Rejeitar cancelamento · Pedido #${request.orderId}") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Motivo informado: ${request.reason}"); OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo da rejeição") }, modifier = Modifier.fillMaxWidth()) } },
            confirmButton = { Button(onClick = { rejectingCancellation = null; cancellationViewModel.reject(request.id, reason) }) { Text("REJEITAR") } },
            dismissButton = { TextButton(onClick = { rejectingCancellation = null }) { Text("VOLTAR") } },
        )
    }
}

@Composable private fun ManagerMetric(label: String, value: String, modifier: Modifier = Modifier) { Card(modifier) { Column(Modifier.padding(16.dp)) { Text(label); Text(value, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black) } } }
@Composable private fun ManagerAlertCard(alert: ManagerAlert) { Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) { Text(when (alert.level) { "warning" -> "⚠️ ${alert.title}"; "critical" -> "🔴 ${alert.title}"; "ok" -> "✅ ${alert.title}"; else -> "ℹ️ ${alert.title}" }, fontWeight = FontWeight.Bold); Text(alert.message) } } }
@Composable private fun ManagerProblemCard(order: ManagerProblemOrder, canTransfer: Boolean, onTransfer: () -> Unit) { Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("#${order.id} · ${problemLabel(order.problemType)}", fontWeight = FontWeight.Bold); Text(managerMoney(order.totalCents), fontWeight = FontWeight.Black) }; Text("${order.customerName} · ${channelLabel(order.channel)}"); Text("Status: ${order.status} · Pagamento: ${order.paymentStatus}"); if (order.deliveryName.isNotBlank()) Text("Entregador: ${order.deliveryName}"); Text("Atualizado: ${order.updatedAt}"); if (canTransfer) OutlinedButton(onClick = onTransfer, modifier = Modifier.fillMaxWidth()) { Text(if (order.deliveryUserId == null) "ATRIBUIR ENTREGADOR" else "TRANSFERIR ENTREGA") } } } }
@Composable private fun DeliveryTransferDialog(order: ManagerProblemOrder, delivery: List<ManagerDeliveryShift>, onDismiss: () -> Unit, onSelect: (Int) -> Unit) { AlertDialog(onDismissRequest = onDismiss, title = { Text("Entregador para #${order.id}") }, text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { delivery.forEach { worker -> OutlinedButton(onClick = { onSelect(worker.userId) }, modifier = Modifier.fillMaxWidth()) { Text("${worker.userName} · ${worker.activeOrders} entrega(s)") } } } }, confirmButton = {}, dismissButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } }) }
private fun problemLabel(value: String) = when (value) { "kitchen_delay" -> "Cozinha atrasada"; "delivery_unassigned" -> "Sem entregador"; "payment_pending" -> "Pagamento pendente"; else -> "Atenção" }
private fun channelLabel(value: String) = when (value) { "delivery" -> "Delivery"; "pickup" -> "Retirada"; "table" -> "Mesa"; "counter" -> "Balcão"; "event" -> "Evento"; else -> value }
private fun managerMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
