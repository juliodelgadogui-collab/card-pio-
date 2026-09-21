package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Restaurant
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.KitchenTicket
import br.com.eventmenu.go.ui.theme.EventMenuUi
import kotlinx.coroutines.delay
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
fun KitchenScreen(tickets: List<KitchenTicket>, onRefresh: () -> Unit, onStatus: (Int, String) -> Unit) {
    LaunchedEffect(Unit) {
        while (true) {
            delay(15_000)
            onRefresh()
        }
    }
    val ordered = tickets.sortedWith(compareBy<KitchenTicket> { if (it.status == "preparing") 0 else 1 }.thenBy { it.id })
    val newCount = tickets.count { it.status == "confirmed" }
    val preparingCount = tickets.count { it.status == "preparing" }
    val delayedCount = tickets.count { elapsedMinutes(it.createdAt) >= 20 }

    Column(Modifier.fillMaxSize().padding(EventMenuUi.SpaceMd), verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text("Cozinha", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                Text("Produção em tempo real", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            OutlinedButton(onClick = onRefresh, modifier = Modifier.heightIn(min = EventMenuUi.TouchTarget)) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.width(7.dp))
                Text("Atualizar")
            }
        }

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
            KitchenMetric("Novos", newCount, Modifier.weight(1f))
            KitchenMetric("Preparando", preparingCount, Modifier.weight(1f))
            KitchenMetric("Atrasados", delayedCount, Modifier.weight(1f), warn = delayedCount > 0)
        }

        if (ordered.isEmpty()) {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Icon(Icons.Default.Restaurant, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text("Produção em dia", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Text("Os próximos pedidos confirmados aparecerão aqui automaticamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        } else {
            LazyVerticalGrid(
                columns = GridCells.Adaptive(290.dp),
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(bottom = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm),
                verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm),
            ) {
                items(ordered, key = { it.id }) { ticket ->
                    KitchenTicketCard(ticket, onStatus)
                }
            }
        }
    }
}

@Composable
private fun KitchenMetric(label: String, value: Int, modifier: Modifier = Modifier, warn: Boolean = false) {
    Surface(
        modifier = modifier,
        color = if (warn) MaterialTheme.colorScheme.tertiaryContainer else MaterialTheme.colorScheme.surface,
        shape = MaterialTheme.shapes.medium,
    ) {
        Column(Modifier.padding(horizontal = 10.dp, vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, color = if (warn) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSurface)
            Text(label, style = MaterialTheme.typography.bodyMedium, color = if (warn) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun KitchenTicketCard(ticket: KitchenTicket, onStatus: (Int, String) -> Unit) {
    val minutes = elapsedMinutes(ticket.createdAt)
    val delayed = minutes >= 20
    val preparing = ticket.status == "preparing"
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("Pedido #${ticket.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    val origin = ticket.tableName.ifBlank { ticket.customerName.ifBlank { channelLabel(ticket.channel) } }
                    Text(origin, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                }
                KitchenTimePill(elapsed(ticket.createdAt), delayed)
            }

            Surface(
                color = if (preparing) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant,
                shape = MaterialTheme.shapes.small,
            ) {
                Text(
                    if (preparing) "Em preparo" else "Novo pedido",
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                    color = if (preparing) MaterialTheme.colorScheme.onPrimaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
                    fontWeight = FontWeight.Bold,
                )
            }

            ticket.items.forEach { item ->
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("${formatQty(item.quantity)}× ${item.name}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    if (item.notes.isNotBlank()) {
                        Surface(color = MaterialTheme.colorScheme.tertiaryContainer, shape = MaterialTheme.shapes.small) {
                            Text("Obs.: ${item.notes}", modifier = Modifier.padding(8.dp), color = MaterialTheme.colorScheme.onTertiaryContainer, style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
            }

            if (ticket.notes.isNotBlank()) {
                Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth()) {
                    Text("Pedido: ${ticket.notes}", modifier = Modifier.padding(9.dp), fontWeight = FontWeight.SemiBold)
                }
            }

            Button(
                onClick = { onStatus(ticket.id, if (ticket.status == "confirmed") "preparing" else "ready") },
                modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
            ) {
                Text(if (ticket.status == "confirmed") "Iniciar preparo" else "Marcar como pronto")
            }
        }
    }
}

@Composable
private fun KitchenTimePill(value: String, delayed: Boolean) {
    Surface(
        color = if (delayed) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.secondaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            value,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (delayed) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSecondaryContainer,
            fontWeight = FontWeight.Black,
        )
    }
}

private fun channelLabel(value: String): String = when (value.lowercase()) {
    "table" -> "Mesa"
    "delivery" -> "Entrega"
    "pickup" -> "Retirada"
    "counter" -> "Balcão"
    "event_bar" -> "Evento / bar"
    else -> "Pedido"
}

private fun elapsed(value: String): String {
    val seconds = elapsedSeconds(value) ?: return "—"
    val min = seconds / 60
    val sec = seconds % 60
    return "%02d:%02d".format(min, sec)
}

private fun elapsedMinutes(value: String): Long = (elapsedSeconds(value) ?: 0L) / 60

private fun elapsedSeconds(value: String): Long? {
    val formats = listOf("yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss")
    val created = formats.firstNotNullOfOrNull { pattern ->
        runCatching { SimpleDateFormat(pattern, Locale.US).parse(value)?.time }.getOrNull()
    } ?: return null
    return ((Date().time - created) / 1000).coerceAtLeast(0)
}

private fun formatQty(q: Double) = if (q % 1.0 == 0.0) q.toInt().toString() else q.toString()
