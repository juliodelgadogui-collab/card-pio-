package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.OperationalText
import br.com.eventmenu.go.data.AppNotification
import br.com.eventmenu.go.navigation.AppDeepLinks
import kotlinx.coroutines.delay

@Composable
fun NotificationsScreen(
    notifications: List<AppNotification>,
    unreadCount: Int,
    onRead: (Int) -> Unit,
    onReadAll: () -> Unit,
    onRefresh: () -> Unit,
) {
    val context = LocalContext.current
    val ordered = notifications.sortedWith(
        compareByDescending<AppNotification> { it.unread }
            .thenBy { priorityRank(it.priority) }
            .thenByDescending { it.id }
    )
    val hasUrgentUnread = ordered.any { it.unread && it.priority.lowercase() in setOf("critical", "warning") }

    LaunchedEffect(unreadCount, hasUrgentUnread) {
        while (true) {
            // Enquanto há algo urgente, a tela acompanha mais de perto. Em estado normal,
            // evita requisições desnecessárias e deixa o push/background fazer o trabalho principal.
            delay(if (hasUrgentUnread) 8_000 else 30_000)
            onRefresh()
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Column {
                    Text("Avisos", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    Text(if (unreadCount > 0) "$unreadCount aviso${if (unreadCount == 1) "" else "s"} para você" else "Tudo em dia", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                if (unreadCount > 0) Button(onClick = onReadAll) { Text("Marcar todos") }
            }
        }

        if (hasUrgentUnread) {
            item {
                Surface(color = MaterialTheme.colorScheme.errorContainer, shape = MaterialTheme.shapes.medium) {
                    Text(
                        "Há um aviso que precisa da sua atenção.",
                        modifier = Modifier.fillMaxWidth().padding(14.dp),
                        color = MaterialTheme.colorScheme.onErrorContainer,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
        }

        if (ordered.isEmpty()) {
            item { Text("Nenhum aviso novo.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
        } else {
            items(ordered, key = { it.id }) { notification ->
                NotificationCard(
                    notification = notification,
                    onRead = onRead,
                    onOpen = {
                        if (notification.unread) onRead(notification.id)
                        context.startActivity(AppDeepLinks.intent(context, notification))
                    },
                )
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar avisos") } }
    }
}

@Composable
private fun NotificationCard(
    notification: AppNotification,
    onRead: (Int) -> Unit,
    onOpen: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text(
                    OperationalText.useful(notification.title) ?: "Aviso do EventMenu",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = if (notification.unread) FontWeight.Black else FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
                NotificationPriorityPill(notification.priority)
            }
            OperationalText.useful(notification.message)?.let { Text(it) }
            notificationFriendlyTime(notification.createdAt)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall) }
            Button(onClick = onOpen, modifier = Modifier.fillMaxWidth()) { Text(notificationActionLabel(notification)) }
            if (notification.unread) {
                OutlinedButton(onClick = { onRead(notification.id) }, modifier = Modifier.fillMaxWidth()) { Text("Marcar como lido") }
            }
        }
    }
}

@Composable
private fun NotificationPriorityPill(priority: String) {
    val label = when (priority.lowercase()) {
        "critical" -> "Urgente"
        "warning" -> "Atenção"
        "success" -> "Concluído"
        else -> "Novo"
    }
    val critical = priority.equals("critical", true)
    Surface(
        color = if (critical) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
            color = if (critical) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onPrimaryContainer,
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

private fun priorityRank(priority: String): Int = when (priority.lowercase()) {
    "critical" -> 0
    "warning" -> 1
    "success" -> 2
    else -> 3
}

private fun notificationActionLabel(notification: AppNotification): String {
    val type = (notification.type + " " + notification.entityType).lowercase()
    return when {
        "delivery" in type -> "Ver entrega"
        "payment" in type || "pix" in type -> "Ver pagamento"
        "order" in type || "kitchen" in type -> "Ver pedido"
        "event" in type || "ticket" in type -> "Ver evento"
        "cash" in type -> "Ver caixa"
        else -> "Ver detalhes"
    }
}

private fun notificationFriendlyTime(value: String?): String? {
    val clean = OperationalText.useful(value) ?: return null
    val normalized = clean.replace('T', ' ')
    val date = normalized.substringBefore(' ')
    val time = normalized.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val dateBr = if (parts.size == 3) "${parts[2]}/${parts[1]}" else date
    return listOf(dateBr, time).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { null }
}
