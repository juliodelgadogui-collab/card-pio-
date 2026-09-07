package br.com.eventmenu.go.ui.screens

import android.app.Activity
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.EventOrderPickupViewModel
import br.com.eventmenu.go.data.EventEntry
import br.com.eventmenu.go.data.EventOverview
import br.com.eventmenu.go.data.EventPickupOrder
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning

@Composable
fun EventModeScreen(
    events: List<EventOverview>,
    selectedEventId: Int?,
    entries: List<EventEntry>,
    canScanTicket: Boolean,
    canScanGuest: Boolean,
    canUseBar: Boolean,
    onSelect: (Int) -> Unit,
    onOpenBar: (Int) -> Unit,
    onRefresh: () -> Unit,
    onScan: () -> Unit,
) {
    val selected = events.firstOrNull { it.id == selectedEventId } ?: events.firstOrNull()
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val pickupViewModel: EventOrderPickupViewModel = viewModel(factory = EventOrderPickupViewModel.Factory(app.eventRepository))
    val pickupState by pickupViewModel.state.collectAsState()

    fun scanOrder(eventId: Int) {
        val activity = context as? Activity ?: return
        val options = GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE, Barcode.FORMAT_AZTEC, Barcode.FORMAT_CODE_128)
            .enableAutoZoom()
            .build()
        GmsBarcodeScanning.getClient(activity, options).startScan()
            .addOnSuccessListener { barcode ->
                barcode.rawValue?.takeIf { it.isNotBlank() }?.let { pickupViewModel.resolve(eventId, it) }
            }
    }

    LaunchedEffect(selected?.id) { pickupViewModel.dismiss() }
    LaunchedEffect(pickupState.completedVersion) { if (pickupState.completedVersion > 0) onRefresh() }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("Modo Evento", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Entrada, pedidos, bar e operação do evento em um só lugar.")
        }

        if (events.isNotEmpty()) {
            item {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(events, key = { it.id }) { event ->
                        FilterChip(
                            selected = event.id == selected?.id,
                            onClick = { onSelect(event.id) },
                            label = { Text(event.name) },
                        )
                    }
                }
            }
        }

        if (selected == null) {
            item { Text("Nenhum evento disponível para operação neste turno.") }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(selected.name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        if (selected.venue.isNotBlank()) Text(selected.venue)
                        if (selected.address.isNotBlank()) Text(selected.address)
                        Text("Início: ${friendlyEventDate(selected.startsAt)}")
                        Text(eventStatus(selected.status))
                    }
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    EventMetric("Ingressos", selected.ticketsPaid.toString(), Modifier.weight(1f))
                    EventMetric("Check-ins", selected.ticketsCheckedIn.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    EventMetric("Convidados", selected.guestsPending.toString(), Modifier.weight(1f))
                    EventMetric("Entraram", selected.guestsCheckedIn.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    EventMetric("Ingressos R$", eventMoney(selected.ticketRevenueCents), Modifier.weight(1f))
                    EventMetric("Bar R$", eventMoney(selected.barRevenueCents), Modifier.weight(1f))
                }
            }
            item { EventMetric("Receita confirmada", eventMoney(selected.revenueCents), Modifier.fillMaxWidth()) }
            if (selected.ticketsReserved > 0) item { Text("${selected.ticketsReserved} ingresso(s) aguardando confirmação.") }

            if (canScanTicket || canScanGuest) {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                            Text("Entrada", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            Text("Leia o QR do ingresso ou da lista de convidados.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Button(onClick = onScan, modifier = Modifier.fillMaxWidth()) {
                                Text(
                                    when {
                                        canScanTicket && canScanGuest -> "🎟 LER INGRESSO / CONVIDADO"
                                        canScanTicket -> "🎟 LER INGRESSO"
                                        else -> "👤 LER CONVIDADO"
                                    }
                                )
                            }
                        }
                    }
                }
            }

            if (canUseBar && selected.status == "published") {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                            Text("Pedidos e bar", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            Text("Venda no balcão ou leia o QR de um pedido para liberar a retirada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Button(onClick = { scanOrder(selected.id) }, modifier = Modifier.fillMaxWidth()) {
                                Text("📷 LER QR DO PEDIDO")
                            }
                            OutlinedButton(onClick = { onOpenBar(selected.id) }, modifier = Modifier.fillMaxWidth()) {
                                Text("🍹 ABRIR BAR / NOVA VENDA")
                            }
                            if (pickupState.loading) CircularProgressIndicator()
                            pickupState.error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold) }
                        }
                    }
                }
            }

            item { Text("Últimos acessos", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            if (entries.isEmpty()) {
                item { Text("Nenhum check-in recente para este evento.") }
            } else {
                items(entries, key = { "${it.type}-${it.id}-${it.checkedInAt}" }) { entry -> EventEntryCard(entry) }
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR EVENTO") } }
    }

    pickupState.order?.let { order ->
        EventOrderPickupDialog(
            order = order,
            loading = pickupState.loading,
            onDeliver = pickupViewModel::deliver,
            onDismiss = pickupViewModel::dismiss,
        )
    }
}

@Composable
private fun EventOrderPickupDialog(
    order: EventPickupOrder,
    loading: Boolean,
    onDeliver: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Pedido #${order.id}") },
        text = {
            LazyColumn(
                modifier = Modifier.fillMaxWidth().heightIn(max = 520.dp),
                verticalArrangement = Arrangement.spacedBy(9.dp),
            ) {
                item {
                    Text(order.eventName, fontWeight = FontWeight.Bold)
                    order.customerName.takeIf { it.isNotBlank() }?.let { Text(it) }
                    Text("${eventPaymentLabel(order.paymentStatus)} · ${eventOrderStatus(order.status)}", fontWeight = FontWeight.SemiBold)
                }
                if (order.alreadyDelivered) {
                    item { Text("⚠️ Este pedido já foi entregue. Não entregue novamente.", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Black) }
                } else if (order.paymentStatus != "paid" && order.totalCents > 0) {
                    item { Text("Pagamento ainda não confirmado. A retirada está bloqueada.", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold) }
                } else if (!order.canDeliver) {
                    item { Text("O pedido foi identificado, mas ainda não está liberado para retirada.", fontWeight = FontWeight.Bold) }
                }
                item { HorizontalDivider() }
                item { Text("Itens", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                items(order.items, key = { it.id }) { item ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("${eventQty(item.quantity)}× ${item.name}", modifier = Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                        Text(eventMoney(item.totalCents), fontWeight = FontWeight.Bold)
                    }
                }
                item {
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("TOTAL", fontWeight = FontWeight.Black)
                        Text(eventMoney(order.totalCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                    }
                }
                if (loading) item { CircularProgressIndicator() }
            }
        },
        confirmButton = {
            if (order.canDeliver && !order.alreadyDelivered) {
                Button(onClick = onDeliver, enabled = !loading) { Text("CONFIRMAR ENTREGA") }
            } else {
                TextButton(onClick = onDismiss) { Text("FECHAR") }
            }
        },
        dismissButton = {
            if (order.canDeliver && !order.alreadyDelivered) TextButton(onClick = onDismiss) { Text("VOLTAR") }
        },
    )
}

@Composable
private fun EventMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier) {
        Column(Modifier.padding(16.dp)) {
            Text(label)
            Text(value, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun EventEntryCard(entry: EventEntry) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(if (entry.type == "ticket") "🎟 ${entry.personName}" else "👤 ${entry.personName}", fontWeight = FontWeight.Bold)
            if (entry.detail.isNotBlank()) Text(if (entry.type == "ticket") entry.detail else "Acompanhantes: ${entry.detail}")
            Text("Entrada: ${friendlyEventDate(entry.checkedInAt)}")
            if (entry.operatorName.isNotBlank()) Text("Operador: ${entry.operatorName}")
        }
    }
}

private fun eventStatus(status: String) = when (status) {
    "published" -> "Evento liberado"
    "draft" -> "Rascunho"
    "closed" -> "Encerrado"
    "cancelled" -> "Cancelado"
    else -> "Evento"
}

private fun eventPaymentLabel(status: String) = when (status.lowercase()) {
    "paid" -> "✅ Pago"
    "refunded" -> "Estornado"
    else -> "Pagamento pendente"
}

private fun eventOrderStatus(status: String) = when (status.lowercase()) {
    "ready" -> "Pronto para retirada"
    "completed" -> "Já entregue"
    "cancelled" -> "Cancelado"
    "preparing" -> "Em preparo"
    "confirmed" -> "Confirmado"
    else -> "Em andamento"
}

private fun friendlyEventDate(value: String): String {
    val clean = value.trim().replace('T', ' ')
    if (clean.isBlank()) return "Horário não informado"
    val date = clean.substringBefore(' ')
    val time = clean.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val dateBr = if (parts.size == 3) "${parts[2]}/${parts[1]}/${parts[0]}" else date
    return listOf(dateBr, time).filter { it.isNotBlank() }.joinToString(" · ")
}

private fun eventQty(qty: Double): String = if (qty % 1.0 == 0.0) qty.toInt().toString() else qty.toString().replace('.', ',')
private fun eventMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
