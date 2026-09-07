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
                    PaymentStatePill(detail.paymentStatus)
                    usefulOrderText(detail.customerName)?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                    usefulOrderText(detail.customerPhone)?.let { Text(it) }
                    usefulOrderText(detail.deliveryAddress)?.let { Text(it) }
                    usefulOrderText(detail.notes)?.let { Text("Observações: $it") }
                }
                item { HorizontalDivider() }
                item { Text("Itens", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                items(detail.items, key = { it.id }) { item ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(Modifier.weight(1f)) {
                            Text("${formatQty(item.quantity)}× ${item.name}", fontWeight = FontWeight.Bold)
                            usefulOrderText(item.notes)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        }
                        Text(orderMoney(item.totalCents), fontWeight = FontWeight.Bold)
                    }
                }
                item {
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Subtotal"); Text(orderMoney(detail.subtotalCents)) }
                    if (detail.deliveryFeeCents > 0) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Entrega"); Text(orderMoney(detail.deliveryFeeCents)) }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Total", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Text(orderMoney(detail.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                    }
                }
                item { HorizontalDivider() }
                item { Text("Histórico do pedido", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                if (detail.timeline.isEmpty()) item { Text("Nenhuma atualização registrada.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                items(detail.timeline, key = { it.id }) { entry -> TimelineRow(entry) }
                if (detail.status == "pending" && canAccept) {
                    item {
                        Button(onClick = { onAccept(detail.orderId) }, modifier = Modifier.fillMaxWidth().padding(top = 6.dp)) {
                            Text("Aceitar pedido")
                        }
                    }
                }
                if (detail.status !in setOf("completed", "cancelled") && detail.paymentStatus != "paid") {
                    val cancellation = cancellationState.orderRequest?.takeIf { it.orderId == detail.orderId }
                    item {
                        when (cancellation?.status) {
                            "pending" -> {
                                Text("Cancelamento aguardando aprovação", fontWeight = FontWeight.Bold)
                                usefulOrderText(cancellation.reason)?.let { Text("Motivo: $it") }
                            }
                            "approved" -> Text("Cancelamento autorizado.", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
                            "rejected" -> {
                                Text("A solicitação anterior não foi aprovada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                                OutlinedButton(onClick = { requestCancellation = true }, modifier = Modifier.fillMaxWidth()) { Text("Solicitar novamente") }
                            }
                            else -> OutlinedButton(onClick = { requestCancellation = true }, modifier = Modifier.fillMaxWidth()) { Text("Solicitar cancelamento") }
                        }
                        cancellationState.error?.let { Text("Não foi possível atualizar o cancelamento. Tente novamente.", color = MaterialTheme.colorScheme.error) }
                        cancellationState.message?.let { Text(it, fontWeight = FontWeight.Bold) }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Fechar") } },
    )

    if (requestCancellation) {
        var reason by remember(detail.orderId) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { requestCancellation = false },
            title = { Text("Cancelar pedido #${detail.orderId}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Um responsável precisa aprovar o cancelamento antes que ele seja concluído.")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { requestCancellation = false; cancellationViewModel.request(detail.orderId, reason) }, enabled = reason.isNotBlank()) { Text("Enviar solicitação") } },
            dismissButton = { TextButton(onClick = { requestCancellation = false }) { Text("Voltar") } },
        )
    }
}

@Composable
private fun PaymentStatePill(status: String) {
    val paid = status == "paid"
    Surface(
        color = if (paid) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            when (status.lowercase()) {
                "paid" -> "Pago"
                "pending" -> "Pagamento em processamento"
                "refunded" -> "Estornado"
                else -> "A receber"
            },
            modifier = Modifier.padding(horizontal = 9.dp, vertical = 5.dp),
            color = if (paid) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun TimelineRow(entry: OrderTimelineEntry) {
    Column(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(orderStatusLabel(entry.toStatus), fontWeight = FontWeight.Bold)
        friendlyOrderDateTime(entry.createdAt)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        val actor = usefulOrderText(entry.userName) ?: sourceLabel(entry.source)
        if (actor.isNotBlank()) Text(actor, color = MaterialTheme.colorScheme.onSurfaceVariant)
        usefulOrderText(entry.notes)?.let { Text(it) }
    }
}

private fun orderStatusLabel(status: String) = when (status.lowercase()) {
    "draft" -> "Rascunho"
    "pending" -> "Pedido criado"
    "confirmed" -> "Aceito"
    "preparing" -> "Em preparo"
    "ready" -> "Pronto"
    "served" -> "Servido"
    "out_for_delivery" -> "Em rota"
    "completed" -> "Finalizado"
    "cancelled" -> "Cancelado"
    else -> "Em andamento"
}

private fun orderChannelLabel(channel: String) = when (channel.lowercase()) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "delivery" -> "Delivery"
    "bar", "event_bar" -> "Bar"
    else -> "Pedido"
}

private fun sourceLabel(source: String) = when (source.lowercase()) {
    "public" -> "Cardápio digital"
    "staff" -> "Equipe"
    "event_bar" -> "Bar do evento"
    "accept" -> "Atendimento"
    "kitchen" -> "Cozinha"
    "dispatch" -> "Balcão"
    "delivery" -> "Entrega"
    "cancellation_approved" -> "Gerência"
    "panel" -> "Painel"
    "migration" -> "Histórico"
    else -> "Equipe"
}

private fun usefulOrderText(value: String?): String? {
    val clean = value?.trim().orEmpty()
    return clean.takeIf { it.isNotBlank() && !it.equals("null", true) && !it.equals("undefined", true) }
}

private fun friendlyOrderDateTime(value: String?): String? {
    val clean = usefulOrderText(value) ?: return null
    val normalized = clean.replace('T', ' ')
    val date = normalized.substringBefore(' ')
    val time = normalized.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val formattedDate = if (parts.size == 3) "${parts[2]}/${parts[1]} às $time" else time.ifBlank { date }
    return formattedDate.trimEnd().removeSuffix("às")
}

private fun formatQty(qty: Double): String = if (qty % 1.0 == 0.0) qty.toInt().toString() else qty.toString()
private fun orderMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
