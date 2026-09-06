package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
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
import br.com.eventmenu.go.data.OrderOperationalDetail
import br.com.eventmenu.go.data.OrderTimelineEntry

@Composable
fun OrderDetailDialog(
    detail: OrderOperationalDetail,
    canAccept: Boolean,
    onAccept: (Int) -> Unit,
    onDismiss: () -> Unit,
) {
    val app = LocalContext.current.applicationContext as EventMenuGoApplication
    val cancellationViewModel: CancellationViewModel = viewModel(factory = CancellationViewModel.Factory(app.cancellationRepository))
    val cancellationState by cancellationViewModel.state.collectAsState()
    var requestCancellation by remember(detail.orderId) { mutableStateOf(false) }

    LaunchedEffect(detail.orderId) { cancellationViewModel.watchOrder(detail.orderId) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Pedido #${detail.orderId}") },
        text = {
            LazyColumn(
                modifier = Modifier.fillMaxWidth().heightIn(max = 580.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                item {
                    Text("${orderChannelLabel(detail.channel)} · ${orderStatusLabel(detail.status)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                    Text(if (detail.paymentStatus == "paid") "✅ Pagamento confirmado" else "Pagamento: ${detail.paymentStatus}")
                    if (detail.customerName.isNotBlank()) Text(detail.customerName)
                    if (detail.customerPhone.isNotBlank()) Text(detail.customerPhone)
                    if (detail.deliveryAddress.isNotBlank()) Text(detail.deliveryAddress)
                    if (detail.notes.isNotBlank()) Text("Observações: ${detail.notes}")
                }
                item { HorizontalDivider() }
                item { Text("Itens", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                items(detail.items, key = { it.id }) { item ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(Modifier.weight(1f)) {
                            Text("${formatQty(item.quantity)}× ${item.name}", fontWeight = FontWeight.Bold)
                            if (item.notes.isNotBlank()) Text(item.notes)
                        }
                        Text(orderMoney(item.totalCents), fontWeight = FontWeight.Bold)
                    }
                }
                item {
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Subtotal"); Text(orderMoney(detail.subtotalCents)) }
                    if (detail.deliveryFeeCents > 0) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Entrega"); Text(orderMoney(detail.deliveryFeeCents)) }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("TOTAL", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Text(orderMoney(detail.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                    }
                }
                item { HorizontalDivider() }
                item { Text("Histórico", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                if (detail.timeline.isEmpty()) item { Text("Nenhuma movimentação registrada.") }
                items(detail.timeline, key = { it.id }) { entry -> TimelineRow(entry) }
                if (detail.status == "pending" && canAccept) {
                    item {
                        Button(onClick = { onAccept(detail.orderId) }, modifier = Modifier.fillMaxWidth().padding(top = 6.dp)) {
                            Text("ACEITAR PEDIDO")
                        }
                    }
                }
                if (detail.status !in setOf("completed", "cancelled") && detail.paymentStatus != "paid") {
                    val cancellation = cancellationState.orderRequest?.takeIf { it.orderId == detail.orderId }
                    item {
                        when (cancellation?.status) {
                            "pending" -> {
                                Text("⏳ Cancelamento aguardando autorização", fontWeight = FontWeight.Bold)
                                if (cancellation.reason.isNotBlank()) Text("Motivo: ${cancellation.reason}")
                            }
                            "approved" -> Text("✅ Cancelamento autorizado pelo servidor.", fontWeight = FontWeight.Bold)
                            "rejected" -> {
                                Text("⚠️ Solicitação anterior não foi aprovada.", fontWeight = FontWeight.Bold)
                                OutlinedButton(onClick = { requestCancellation = true }, modifier = Modifier.fillMaxWidth()) { Text("SOLICITAR NOVAMENTE") }
                            }
                            else -> OutlinedButton(onClick = { requestCancellation = true }, modifier = Modifier.fillMaxWidth()) { Text("SOLICITAR CANCELAMENTO") }
                        }
                        cancellationState.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                        cancellationState.message?.let { Text(it, fontWeight = FontWeight.Bold) }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )

    if (requestCancellation) {
        var reason by remember(detail.orderId) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { requestCancellation = false },
            title = { Text("Solicitar cancelamento · Pedido #${detail.orderId}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("O pedido só será cancelado depois de autorização do Gerente/ADM no servidor.")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo *") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { requestCancellation = false; cancellationViewModel.request(detail.orderId, reason) }, enabled = reason.isNotBlank()) { Text("ENVIAR") } },
            dismissButton = { TextButton(onClick = { requestCancellation = false }) { Text("VOLTAR") } },
        )
    }
}

@Composable
private fun TimelineRow(entry: OrderTimelineEntry) {
    Column(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text("${timelineIcon(entry.toStatus)} ${orderStatusLabel(entry.toStatus)}", fontWeight = FontWeight.Bold)
        Text(entry.createdAt)
        val actor = entry.userName.ifBlank { sourceLabel(entry.source) }
        if (actor.isNotBlank()) Text(actor)
        if (entry.notes.isNotBlank()) Text(entry.notes)
    }
}

private fun orderStatusLabel(status: String) = when (status) {
    "draft" -> "Rascunho"
    "pending" -> "Pedido criado"
    "confirmed" -> "Aceito"
    "preparing" -> "Preparação iniciada"
    "ready" -> "Pronto"
    "served" -> "Servido"
    "out_for_delivery" -> "Em rota"
    "completed" -> "Finalizado"
    "cancelled" -> "Cancelado"
    else -> status
}

private fun orderChannelLabel(channel: String) = when (channel) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "delivery" -> "Delivery"
    "bar" -> "Bar"
    else -> channel
}

private fun sourceLabel(source: String) = when (source) {
    "public" -> "Cardápio digital"
    "staff" -> "Equipe"
    "event_bar" -> "Bar do evento"
    "accept" -> "Atendimento"
    "kitchen" -> "Cozinha"
    "dispatch" -> "Balcão"
    "delivery" -> "Delivery"
    "cancellation_approved" -> "Gerência"
    "panel" -> "Painel"
    "migration" -> "Histórico importado"
    else -> source
}

private fun timelineIcon(status: String) = when (status) {
    "pending" -> "🧾"
    "confirmed" -> "✅"
    "preparing" -> "🍳"
    "ready" -> "🔔"
    "served" -> "🍽"
    "out_for_delivery" -> "🛵"
    "completed" -> "🏁"
    "cancelled" -> "⛔"
    else -> "•"
}

private fun formatQty(qty: Double): String = if (qty % 1.0 == 0.0) qty.toInt().toString() else qty.toString()
private fun orderMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
