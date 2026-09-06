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
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.OrderOperationalDetail
import br.com.eventmenu.go.data.OrderTimelineEntry

@Composable
fun OrderDetailDialog(
    detail: OrderOperationalDetail,
    canAccept: Boolean,
    onAccept: (Int) -> Unit,
    onDismiss: () -> Unit,
) {
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
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )
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
