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
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.AppNotification
import kotlinx.coroutines.delay

@Composable
fun NotificationsScreen(
    notifications: List<AppNotification>,
    unreadCount: Int,
    onRead: (Int) -> Unit,
    onReadAll: () -> Unit,
    onRefresh: () -> Unit,
) {
    LaunchedEffect(Unit) {
        while (true) {
            delay(15_000)
            onRefresh()
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Column {
                    Text("Notificações", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    Text(if (unreadCount > 0) "$unreadCount não lida(s)" else "Tudo em dia")
                }
                if (unreadCount > 0) Button(onClick = onReadAll) { Text("LER TODAS") }
            }
        }

        if (notifications.isEmpty()) {
            item { Text("Nenhuma notificação para este modo de trabalho.") }
        } else {
            items(notifications, key = { it.id }) { notification ->
                NotificationCard(notification, onRead)
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR") } }
    }
}

@Composable
private fun NotificationCard(notification: AppNotification, onRead: (Int) -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(
                notificationIcon(notification.priority) + " " + notification.title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = if (notification.unread) FontWeight.Black else FontWeight.Bold,
            )
            Text(notification.message)
            Text(notification.createdAt, style = MaterialTheme.typography.bodySmall)
            if (notification.entityType.isNotBlank() && notification.entityId.isNotBlank()) {
                Text("${notification.entityType} #${notification.entityId}", style = MaterialTheme.typography.bodySmall)
            }
            if (notification.unread) {
                OutlinedButton(onClick = { onRead(notification.id) }, modifier = Modifier.fillMaxWidth()) { Text("MARCAR COMO LIDA") }
            } else {
                Text("✓ Lida", style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

private fun notificationIcon(priority: String) = when (priority) {
    "critical" -> "🚨"
    "warning" -> "⚠️"
    "success" -> "✅"
    else -> "🔔"
}
