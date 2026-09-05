package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.EventEntry
import br.com.eventmenu.go.data.EventOverview

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

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("Modo Evento", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Check-in, lista, bar e operação rápida. Cadastro e configurações continuam no painel web.")
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
                        Text("Início: ${selected.startsAt}")
                        Text("Status: ${eventStatus(selected.status)}")
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
            item { EventMetric("Receita total confirmada", eventMoney(selected.revenueCents), Modifier.fillMaxWidth()) }
            if (selected.ticketsReserved > 0) item { Text("${selected.ticketsReserved} ingresso(s) ainda reservado(s)/aguardando definição.") }

            if (canUseBar && selected.status == "published") {
                item {
                    Button(onClick = { onOpenBar(selected.id) }, modifier = Modifier.fillMaxWidth()) { Text("🍹 ABRIR BAR") }
                }
            }

            if (canScanTicket || canScanGuest) {
                item {
                    Button(onClick = onScan, modifier = Modifier.fillMaxWidth()) {
                        Text(
                            when {
                                canScanTicket && canScanGuest -> "LER INGRESSO / CONVIDADO"
                                canScanTicket -> "LER INGRESSO"
                                else -> "LER CONVIDADO"
                            }
                        )
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
            Text("Entrada: ${entry.checkedInAt}")
            if (entry.operatorName.isNotBlank()) Text("Operador: ${entry.operatorName}")
        }
    }
}

private fun eventStatus(status: String) = when (status) {
    "published" -> "Publicado / liberado"
    "draft" -> "Rascunho"
    "closed" -> "Encerrado"
    "cancelled" -> "Cancelado"
    else -> status
}

private fun eventMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
