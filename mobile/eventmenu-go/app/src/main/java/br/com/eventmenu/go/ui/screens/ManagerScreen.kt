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
import br.com.eventmenu.go.FinanceViewModel
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
    val financeViewModel: FinanceViewModel = viewModel(factory = FinanceViewModel.Factory(app.financeRepository))
    val financeState by financeViewModel.state.collectAsState()
    var transferOrder by remember { mutableStateOf<ManagerProblemOrder?>(null) }
    var rejectingDiscount by remember { mutableStateOf<DiscountRequest?>(null) }
    var rejectingCancellation by remember { mutableStateOf<CancellationRequest?>(null) }

    LaunchedEffect(Unit) {
        cancellationViewModel.loadPending()
        managerActionsViewModel.refreshReopenCandidates()
        financeViewModel.refresh()
    }
    LaunchedEffect(cancellationState.changeVersion) { if (cancellationState.changeVersion > 0) onRefresh() }
    LaunchedEffect(managerActionState.changeVersion) { if (managerActionState.changeVersion > 0) onRefresh() }

    LazyColumn(
        Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text("Gestão da operação", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                Text("Veja primeiro o que precisa da sua atenção.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (loading || cancellationState.loading || managerActionState.loading || financeState.loading) {
                    CircularProgressIndicator(Modifier.padding(top = 8.dp))
                }
            }
        }

        overview?.let { current ->
            item { ManagerSectionTitle("Agora") }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                    ManagerMetric("Pedidos", current.ordersNow.toString(), Modifier.weight(1f))
                    ManagerMetric("Prontos", current.readyOrders.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                    ManagerMetric("Atrasados", current.kitchenDelayed.toString(), Modifier.weight(1f))
                    ManagerMetric("A receber", current.pendingPayments.toString(), Modifier.weight(1f))
                }
            }
        }

        details?.let { data ->
            if (data.problemOrders.isNotEmpty()) {
                item { ManagerSectionTitle("Precisa de atenção", "${data.problemOrders.size}") }
                items(data.problemOrders, key = { "problem-${it.id}" }) { order ->
                    ManagerProblemCard(
                        order = order,
                        canTransfer = canTransferDelivery && order.channel == "delivery" && data.deliveryShifts.isNotEmpty(),
                        onTransfer = { transferOrder = order },
                    )
                }
            }
        }

        if (canApproveDiscount && pendingDiscounts.isNotEmpty()) {
            item { ManagerSectionTitle("Descontos para aprovar", pendingDiscounts.size.toString()) }
            items(pendingDiscounts, key = { "discount-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                            Text(managerMoney(request.requestedCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        if (request.discountType == "percent" && request.requestedBps > 0) {
                            Text("${request.requestedBps / 100.0}% de desconto", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text("${request.requesterName.ifBlank { "Funcionário" }} solicitou o desconto.")
                        if (request.reason.isNotBlank()) Text("Motivo: ${request.reason}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { onApproveDiscount(request.id) }, modifier = Modifier.weight(1f)) { Text("Aprovar") }
                            OutlinedButton(onClick = { rejectingDiscount = request }, modifier = Modifier.weight(1f)) { Text("Rejeitar") }
                        }
                    }
                }
            }
        }

        if (cancellationState.pending.isNotEmpty()) {
            item { ManagerSectionTitle("Cancelamentos para aprovar", cancellationState.pending.size.toString()) }
            items(cancellationState.pending, key = { "cancel-${it.id}" }) { request ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${request.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                            Text(managerMoney(request.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        }
                        val identity = listOfNotNull(
                            request.customerName.cleanVisible().takeIf { it.isNotBlank() },
                            channelLabel(request.channel).takeIf { it.isNotBlank() },
                        ).joinToString(" · ")
                        if (identity.isNotBlank()) Text(identity)
                        Text("Solicitado por ${request.requesterName.ifBlank { "funcionário" }}")
                        if (request.reason.isNotBlank()) Text("Motivo: ${request.reason}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(
                            "${orderStatusLabel(request.orderStatus)} · ${paymentStatusLabel(request.paymentStatus)}",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { cancellationViewModel.approve(request.id) }, modifier = Modifier.weight(1f)) { Text("Autorizar") }
                            OutlinedButton(onClick = { rejectingCancellation = request }, modifier = Modifier.weight(1f)) { Text("Rejeitar") }
                        }
                    }
                }
            }
        }

        overview?.alerts?.takeIf { it.isNotEmpty() }?.let { alerts ->
            item { ManagerSectionTitle("Alertas") }
            items(alerts) { alert -> ManagerAlertCard(alert) }
        }

        financeState.summary?.let { finance ->
            item { ManagerSectionTitle("Financeiro") }
            item {
                Card(
                    Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = .55f)),
                ) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                            ManagerInlineMetric("Resultado", managerMoney(finance.operatingResultCents), Modifier.weight(1f))
                            ManagerInlineMetric("Caixa", managerMoney(finance.cashChangeCents), Modifier.weight(1f))
                        }
                        HorizontalDivider()
                        Text("Próximos 30 dias", fontWeight = FontWeight.Bold)
                        Text("Entradas previstas: ${managerMoney(finance.forecastIn30dCents)}")
                        Text("Saídas previstas: ${managerMoney(finance.forecastOut30dCents)}")
                        if (finance.overduePayableCents > 0 || finance.overdueReceivableCents > 0) {
                            Text(
                                "Vencidos: ${managerMoney(finance.overduePayableCents)} a pagar · ${managerMoney(finance.overdueReceivableCents)} a receber",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }
        }

        if (financeState.summary == null && financeState.error != null && !financeState.error!!.contains("Acesso negado", ignoreCase = true)) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text("Resumo financeiro indisponível", fontWeight = FontWeight.Bold)
                        Text("Atualize o painel para tentar novamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }

        details?.let { data ->
            if (data.cashSessions.isNotEmpty() || data.deliveryShifts.isNotEmpty()) {
                item { ManagerSectionTitle("Equipe em operação") }
            }
            items(data.cashSessions, key = { "cash-${it.id}" }) { cash ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(15.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(cash.userName.cleanVisible().ifBlank { "Caixa" }, fontWeight = FontWeight.Bold)
                            Text("Caixa aberto${managerShortTime(cash.openedAt)?.let { " desde $it" } ?: ""}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text(managerMoney(cash.openingCashCents), fontWeight = FontWeight.Bold)
                    }
                }
            }
            items(data.deliveryShifts, key = { "delivery-${it.shiftId}" }) { delivery ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(15.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(delivery.userName.cleanVisible().ifBlank { "Entregador" }, fontWeight = FontWeight.Bold)
                            Text("Em turno", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text("${delivery.activeOrders} entrega(s)", fontWeight = FontWeight.Bold)
                    }
                }
            }
        }

        if (managerActionState.error?.contains("Acesso negado", ignoreCase = true) != true) {
            item {
                ManagerReopenPanel(managerActionState.reopenCandidates, managerActionsViewModel::reopenOrder)
            }
        }

        cancellationState.error?.takeIf { !it.contains("Acesso negado", ignoreCase = true) }?.let {
            item { ManagerFriendlyError("Não foi possível atualizar os cancelamentos agora.") }
        }
        managerActionState.error?.takeIf { !it.contains("Acesso negado", ignoreCase = true) }?.let {
            item { ManagerFriendlyError("Algumas informações da operação não puderam ser atualizadas.") }
        }
        managerActionState.message?.let { message -> item { Text("✓ $message", color = MaterialTheme.colorScheme.primary) } }

        item {
            OutlinedButton(
                onClick = {
                    cancellationViewModel.loadPending()
                    managerActionsViewModel.refreshReopenCandidates()
                    financeViewModel.refresh()
                    onRefresh()
                },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Atualizar painel") }
        }
    }

    transferOrder?.let { order ->
        DeliveryTransferDialog(order, details?.deliveryShifts.orEmpty(), { transferOrder = null }) { userId ->
            transferOrder = null
            onTransferDelivery(order.id, userId)
        }
    }

    rejectingDiscount?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingDiscount = null },
            title = { Text("Rejeitar desconto · Pedido #${request.orderId}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Desconto solicitado: ${managerMoney(request.requestedCents)}")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo da rejeição") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { rejectingDiscount = null; onRejectDiscount(request.id, reason) }) { Text("Rejeitar") } },
            dismissButton = { TextButton(onClick = { rejectingDiscount = null }) { Text("Voltar") } },
        )
    }

    rejectingCancellation?.let { request ->
        var reason by remember(request.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { rejectingCancellation = null },
            title = { Text("Rejeitar cancelamento · Pedido #${request.orderId}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Motivo informado: ${request.reason}")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo da rejeição") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { rejectingCancellation = null; cancellationViewModel.reject(request.id, reason) }) { Text("Rejeitar") } },
            dismissButton = { TextButton(onClick = { rejectingCancellation = null }) { Text("Voltar") } },
        )
    }

    @Suppress("UNUSED_VARIABLE")
    val legacyDirectCancelRemoved = canCancelOrder to onCancelOrder
}

@Composable
private fun ManagerSectionTitle(title: String, count: String? = null) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        count?.let { Text(it, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold) }
    }
}

@Composable
private fun ManagerMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(value, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun ManagerInlineMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
    }
}

@Composable
private fun ManagerAlertCard(alert: ManagerAlert) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(
                when (alert.level) {
                    "warning" -> "⚠ ${alert.title}"
                    "critical" -> "● ${alert.title}"
                    "ok" -> "✓ ${alert.title}"
                    else -> alert.title
                },
                fontWeight = FontWeight.Bold,
            )
            if (alert.message.isNotBlank()) Text(alert.message, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun ManagerProblemCard(order: ManagerProblemOrder, canTransfer: Boolean, onTransfer: () -> Unit) {
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = .55f)),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("Pedido #${order.id}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                    Text(problemLabel(order.problemType), color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold)
                }
                Text(managerMoney(order.totalCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            }

            val identity = listOfNotNull(
                order.customerName.cleanVisible().takeIf { it.isNotBlank() },
                channelLabel(order.channel).takeIf { it.isNotBlank() },
            ).joinToString(" · ")
            if (identity.isNotBlank()) Text(identity)

            val stateText = listOf(
                orderStatusLabel(order.status),
                paymentStatusLabel(order.paymentStatus),
            ).filter { it.isNotBlank() }.joinToString(" · ")
            if (stateText.isNotBlank()) Text(stateText, color = MaterialTheme.colorScheme.onSurfaceVariant)

            if (order.channel == "delivery" && order.deliveryName.cleanVisible().isNotBlank()) {
                Text("Entregador: ${order.deliveryName.cleanVisible()}", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            if (canTransfer) {
                OutlinedButton(onClick = onTransfer, modifier = Modifier.fillMaxWidth()) {
                    Text(if (order.deliveryUserId == null) "Escolher entregador" else "Trocar entregador")
                }
            }
        }
    }
}

@Composable
private fun DeliveryTransferDialog(
    order: ManagerProblemOrder,
    delivery: List<ManagerDeliveryShift>,
    onDismiss: () -> Unit,
    onSelect: (Int) -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Entregador para o pedido #${order.id}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                delivery.forEach { worker ->
                    OutlinedButton(onClick = { onSelect(worker.userId) }, modifier = Modifier.fillMaxWidth()) {
                        Text("${worker.userName.cleanVisible().ifBlank { "Entregador" }} · ${worker.activeOrders} entrega(s)")
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Fechar") } },
    )
}

@Composable
private fun ManagerFriendlyError(text: String) {
    Card(Modifier.fillMaxWidth()) {
        Text(text, modifier = Modifier.padding(14.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

private fun problemLabel(value: String) = when (value) {
    "kitchen_delay" -> "Preparo atrasado"
    "delivery_unassigned" -> "Aguardando entregador"
    "payment_pending" -> "Pagamento pendente"
    else -> "Requer atenção"
}

private fun channelLabel(value: String) = when (value.cleanVisible().lowercase()) {
    "delivery" -> "Delivery"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "counter" -> "Balcão"
    "event" -> "Evento"
    "" -> ""
    else -> value.cleanVisible().replaceFirstChar { it.uppercase() }
}

private fun orderStatusLabel(value: String) = when (value.cleanVisible().lowercase()) {
    "pending" -> "Novo pedido"
    "confirmed" -> "Pedido confirmado"
    "preparing" -> "Em preparo"
    "ready" -> "Pronto para entrega"
    "served" -> "Servido"
    "out_for_delivery" -> "Em rota"
    "completed" -> "Finalizado"
    "cancelled" -> "Cancelado"
    "" -> ""
    else -> "Em andamento"
}

private fun paymentStatusLabel(value: String) = when (value.cleanVisible().lowercase()) {
    "unpaid" -> "Aguardando pagamento"
    "pending", "created" -> "Pagamento em processamento"
    "authorized" -> "Pagamento autorizado"
    "paid" -> "Pago"
    "refunded" -> "Estornado"
    "partially_refunded" -> "Estorno parcial"
    "cancelled" -> "Cobrança cancelada"
    "" -> ""
    else -> "Pagamento pendente"
}

private fun String.cleanVisible(): String {
    val clean = trim()
    return if (clean.isBlank() || clean.equals("null", ignoreCase = true)) "" else clean
}

private fun managerShortTime(value: String): String? {
    val clean = value.cleanVisible().replace('T', ' ')
    if (clean.length < 16) return null
    return clean.substring(11, 16)
}

private fun managerMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
