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
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.CancellationViewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.ManagerActionsViewModel
import br.com.eventmenu.go.data.CancellationRequest
import br.com.eventmenu.go.data.DiscountRequest
import br.com.eventmenu.go.data.ManagerAlert
import br.com.eventmenu.go.data.ManagerDeliveryShift
import br.com.eventmenu.go.data.ManagerDetails
import br.com.eventmenu.go.data.ManagerOverview
import br.com.eventmenu.go.data.ManagerProblemOrder

@Composable
fun ManagerScreen(
    overview: ManagerOverview?,
    details: ManagerDetails?,
    pendingDiscounts: List<DiscountRequest>,
    loading: Boolean,
    canTransferDelivery: Boolean,
    canCancelOrder: Boolean,
    canApproveDiscount: Boolean,
    onTransferDelivery: (Int, Int) -> Unit,
    onCancelOrder: (Int) -> Unit,
    onApproveDiscount: (Int) -> Unit,
    onRejectDiscount: (Int, String) -> Unit,
    onRefresh: () -> Unit,
) {
    val app = LocalContext.current.applicationContext as EventMenuGoApplication
    val cancellationViewModel: CancellationViewModel = viewModel(factory = CancellationViewModel.Factory(app.cancellationRepository))
    val cancellationState by cancellationViewModel.state.collectAsState()
    val managerActionsViewModel: ManagerActionsViewModel = viewModel(factory = ManagerActionsViewModel.Factory(app.managerOperationsRepository))
    val managerActionState by managerActionsViewModel.state.collectAsState()
    var transferOrder by remember { mutableStateOf<ManagerProblemOrder?>(null) }
    var rejectingDiscount by remember { mutableStateOf<DiscountRequest?>(null) }
    var rejectingCancellation by remember { mutableStateOf<CancellationRequest?>(null) }

    LaunchedEffect(Unit) {
        cancellationViewModel.loadPending()
        managerActionsViewModel.refreshReopenCandidates()
    }
    LaunchedEffect(cancellationState.changeVersion) { if (cancellationState.changeVersion > 0) onRefresh() }
    LaunchedEffect(managerActionState.changeVersion) { if (managerActionState.changeVersion > 0) onRefresh() }

    LazyColumn(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("Gestão da operação", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Veja o que precisa da sua atenção agora.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (loading || cancellationState.loading || managerActionState.loading) CircularProgressIndicator(Modifier.padding(top = 8.dp))
        }

        if (canApproveDiscount && pendingDiscounts.isNotEmpty()) {
            item { SectionTitle("Descontos para aprovar", pendingDiscounts.size) }
            items(pendingDiscounts, key = { "discount-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(managerMoney(request.requestedCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        Text("Solicitado por ${request.requesterName.ifBlank { "funcionário" }}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(request.reason)
                        if (request.totalCents > 0) Text("Total do pedido: ${managerMoney(request.totalCents)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { onApproveDiscount(request.id) }, modifier = Modifier.weight(1f)) { Text("Aprovar") }
                            OutlinedButton(onClick = { rejectingDiscount = request }, modifier = Modifier.weight(1f)) { Text("Recusar") }
                        }
                    }
                }
            }
        }

        if (cancellationState.error?.contains("Acesso negado", ignoreCase = true) != true && cancellationState.pending.isNotEmpty()) {
            item { SectionTitle("Cancelamentos para analisar", cancellationState.pending.size) }
            items(cancellationState.pending, key = { "cancel-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(managerMoney(request.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        request.customerName.takeIf(::isUsefulText)?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        Text("${channelLabel(request.channel)} · ${statusLabel(request.orderStatus)} · ${paymentLabel(request.paymentStatus)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text("Motivo: ${request.reason}")
                        Text("Solicitado por ${request.requesterName.ifBlank { "funcionário" }}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { cancellationViewModel.approve(request.id) }, modifier = Modifier.weight(1f)) { Text("Autorizar") }
                            OutlinedButton(onClick = { rejectingCancellation = request }, modifier = Modifier.weight(1f)) { Text("Recusar") }
                        }
                    }
                }
            }
        }

        if (managerActionState.error?.contains("Acesso negado", ignoreCase = true) != true && managerActionState.reopenCandidates.isNotEmpty()) {
            item {
                HorizontalDivider()
                ManagerReopenPanel(managerActionState.reopenCandidates, managerActionsViewModel::reopenOrder)
            }
        }

        overview?.let { data ->
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Pedidos ativos", data.ordersNow.toString(), Modifier.weight(1f)); ManagerMetric("Prontos", data.readyOrders.toString(), Modifier.weight(1f)) } }
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("Cozinha atrasada", data.kitchenDelayed.toString(), Modifier.weight(1f)); ManagerMetric("Sem entregador", data.unassignedDelivery.toString(), Modifier.weight(1f)) } }
            item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { ManagerMetric("A receber", data.pendingPayments.toString(), Modifier.weight(1f)); ManagerMetric("Vendas hoje", managerMoney(data.revenueTodayCents), Modifier.weight(1f)) } }
            if (data.alerts.isNotEmpty()) {
                item { Text("Atenção", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                items(data.alerts) { alert -> ManagerAlertCard(alert) }
            }
        }

        details?.let { data ->
            if (data.problemOrders.isNotEmpty()) {
                item { Text("Pedidos que precisam de ação", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                items(data.problemOrders, key = { "problem-${it.id}" }) { order ->
                    ManagerProblemCard(
                        order,
                        canTransferDelivery && order.channel == "delivery" && data.deliveryShifts.isNotEmpty(),
                    ) { transferOrder = order }
                }
            }

            if (data.deliveryShifts.isNotEmpty()) {
                item { Text("Equipe de entrega", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                items(data.deliveryShifts, key = { "delivery-${it.shiftId}" }) { delivery ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Column {
                                Text(delivery.userName, fontWeight = FontWeight.Bold)
                                Text("Em turno desde ${friendlyTime(delivery.startedAt)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            Text("${delivery.activeOrders} em andamento", fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }

            if (data.cashSessions.isNotEmpty()) {
                item { Text("Caixas abertos", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                items(data.cashSessions, key = { "cash-${it.id}" }) { cash ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                            Column { Text(cash.userName, fontWeight = FontWeight.Bold); Text("Aberto às ${friendlyTime(cash.openedAt)}", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            Text(managerMoney(cash.openingCashCents), fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }

        cancellationState.error?.takeIf { !it.contains("Acesso negado", ignoreCase = true) }?.let { error ->
            item { FriendlyError("Não foi possível atualizar os cancelamentos.", error) }
        }
        managerActionState.error?.takeIf { !it.contains("Acesso negado", ignoreCase = true) }?.let { error ->
            item { FriendlyError("Não foi possível atualizar a gestão.", error) }
        }
        managerActionState.message?.let { message -> item { Text(message, color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.SemiBold) } }
        item {
            OutlinedButton(
                onClick = { cancellationViewModel.loadPending(); managerActionsViewModel.refreshReopenCandidates(); onRefresh() },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Atualizar") }
        }
    }

    transferOrder?.let { order -> DeliveryTransferDialog(order, details?.deliveryShifts.orEmpty(), { transferOrder = null }) { userId -> transferOrder = null; onTransferDelivery(order.id, userId) } }
    rejectingDiscount?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingDiscount = null },
            title = { Text("Recusar desconto do pedido #${request.orderId}") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Desconto solicitado: ${managerMoney(request.requestedCents)}"); OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo") }, modifier = Modifier.fillMaxWidth()) } },
            confirmButton = { Button(onClick = { rejectingDiscount = null; onRejectDiscount(request.id, reason) }) { Text("Recusar") } },
            dismissButton = { TextButton(onClick = { rejectingDiscount = null }) { Text("Voltar") } },
        )
    }
    rejectingCancellation?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingCancellation = null },
            title = { Text("Recusar cancelamento do pedido #${request.orderId}") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Motivo informado: ${request.reason}"); OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo da recusa") }, modifier = Modifier.fillMaxWidth()) } },
            confirmButton = { Button(onClick = { rejectingCancellation = null; cancellationViewModel.reject(request.id, reason) }) { Text("Recusar") } },
            dismissButton = { TextButton(onClick = { rejectingCancellation = null }) { Text("Voltar") } },
        )
    }

    @Suppress("UNUSED_VARIABLE")
    val legacyDirectCancelRemoved = canCancelOrder to onCancelOrder
}

@Composable
private fun SectionTitle(title: String, count: Int) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.small) {
            Text(count.toString(), modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp), fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun ManagerMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(value, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun ManagerAlertCard(alert: ManagerAlert) {
    val color = when (alert.level) {
        "critical" -> MaterialTheme.colorScheme.error
        "warning" -> MaterialTheme.colorScheme.tertiary
        "ok" -> MaterialTheme.colorScheme.secondary
        else -> MaterialTheme.colorScheme.primary
    }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(alert.title, fontWeight = FontWeight.Bold, color = color)
            Text(alert.message)
        }
    }
}

@Composable
private fun ManagerProblemCard(order: ManagerProblemOrder, canTransfer: Boolean, onTransfer: () -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text("Pedido #${order.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text(problemLabel(order.problemType), color = problemColor(order.problemType), fontWeight = FontWeight.SemiBold)
                }
                Text(managerMoney(order.totalCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            }

            val identity = buildList {
                if (isUsefulText(order.customerName)) add(order.customerName.trim())
                add(channelLabel(order.channel))
            }.joinToString(" · ")
            Text(identity, style = MaterialTheme.typography.bodyLarge)

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FriendlyPill(statusLabel(order.status), order.status in setOf("ready", "completed", "served"))
                FriendlyPill(paymentLabel(order.paymentStatus), order.paymentStatus == "paid")
            }

            if (order.channel == "delivery") {
                when {
                    isUsefulText(order.deliveryName) -> Text("Entrega com ${order.deliveryName.trim()}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    order.problemType == "delivery_unassigned" -> Text("Ainda sem entregador", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold)
                }
            }
            friendlyTime(order.updatedAt).takeIf { it.isNotBlank() }?.let { Text("Atualizado às $it", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium) }
            if (canTransfer) OutlinedButton(onClick = onTransfer, modifier = Modifier.fillMaxWidth()) { Text(if (order.deliveryUserId == null) "Escolher entregador" else "Trocar entregador") }
        }
    }
}

@Composable
private fun FriendlyPill(text: String, positive: Boolean) {
    Surface(
        color = if (positive) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            text,
            modifier = Modifier.padding(horizontal = 9.dp, vertical = 5.dp),
            color = if (positive) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onPrimaryContainer,
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

@Composable
private fun FriendlyError(message: String, detail: String) {
    val useful = detail.takeIf { it.isNotBlank() && !it.contains("Não foi possível concluir esta operação", ignoreCase = true) }
    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(message, color = MaterialTheme.colorScheme.onErrorContainer, fontWeight = FontWeight.Bold)
            useful?.let { Text(it, color = MaterialTheme.colorScheme.onErrorContainer, style = MaterialTheme.typography.bodyMedium) }
        }
    }
}

@Composable
private fun DeliveryTransferDialog(order: ManagerProblemOrder, delivery: List<ManagerDeliveryShift>, onDismiss: () -> Unit, onSelect: (Int) -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Escolher entregador · Pedido #${order.id}") },
        text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { delivery.forEach { worker -> OutlinedButton(onClick = { onSelect(worker.userId) }, modifier = Modifier.fillMaxWidth()) { Text("${worker.userName} · ${worker.activeOrders} em andamento") } } } },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Fechar") } },
    )
}

private fun problemLabel(value: String) = when (value) {
    "kitchen_delay" -> "Preparo demorando"
    "delivery_unassigned" -> "Precisa de entregador"
    "payment_pending" -> "Aguardando pagamento"
    else -> "Precisa de atenção"
}

@Composable
private fun problemColor(value: String) = when (value) {
    "payment_pending" -> MaterialTheme.colorScheme.tertiary
    "delivery_unassigned", "kitchen_delay" -> MaterialTheme.colorScheme.error
    else -> MaterialTheme.colorScheme.primary
}

private fun statusLabel(value: String) = when (value.lowercase()) {
    "pending" -> "Novo"
    "confirmed" -> "Confirmado"
    "preparing" -> "Em preparo"
    "ready" -> "Pronto"
    "out_for_delivery" -> "Em rota"
    "served" -> "Entregue"
    "completed" -> "Concluído"
    "cancelled" -> "Cancelado"
    else -> "Em andamento"
}

private fun paymentLabel(value: String) = when (value.lowercase()) {
    "paid" -> "Pago"
    "pending" -> "Processando pagamento"
    "refunded" -> "Estornado"
    "partially_refunded" -> "Estorno parcial"
    else -> "A receber"
}

private fun channelLabel(value: String) = when (value.lowercase()) {
    "delivery" -> "Delivery"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "counter" -> "Balcão"
    "event", "event_bar" -> "Evento"
    else -> "Pedido"
}

private fun isUsefulText(value: String): Boolean {
    val clean = value.trim()
    return clean.isNotBlank() && !clean.equals("null", true) && !clean.equals("undefined", true) && !clean.equals("consumidor", true)
}

private fun friendlyTime(value: String): String {
    val clean = value.trim()
    if (clean.isBlank() || clean.equals("null", true)) return ""
    val normalized = clean.replace('T', ' ')
    val time = normalized.substringAfter(' ', "").take(5)
    return if (Regex("^\\d{2}:\\d{2}$").matches(time)) time else clean.take(16)
}

private fun managerMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
