package br.com.eventmenu.delivery

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

object DeliveryNotifications {
    private const val CHANNEL_ID = "eventmenu_delivery_orders"

    fun createChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = context.getSystemService(NotificationManager::class.java)
            val channel = NotificationChannel(CHANNEL_ID, "Pedidos e entregas", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "Atualizações do pedido, pagamento e rota de entrega"
            }
            manager.createNotificationChannel(channel)
        }
    }

    fun show(context: Context, orderId: Int, title: String, message: String) {
        if (title.isBlank() || message.isBlank()) return

        if (
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }

        val notificationManager = NotificationManagerCompat.from(context)
        if (!notificationManager.areNotificationsEnabled()) return

        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra("order_id", orderId)
        }
        val pending = PendingIntent.getActivity(
            context,
            orderId.coerceAtLeast(1),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_delivery_notification)
            .setContentTitle(title)
            .setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()

        try {
            notificationManager.notify(50_000 + orderId.coerceAtLeast(1), notification)
        } catch (_: SecurityException) {
            // The permission may be revoked between the explicit check above and notify().
        }
    }
}

class DeliveryFirebaseMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        super.onNewToken(token)
        (application as? DeliveryApplication)?.pushCoordinator?.registerToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        if (data["type"] != "customer.order.status") return
        val orderId = data["order_id"]?.toIntOrNull() ?: return
        val title = data["title"].orEmpty().ifBlank { message.notification?.title.orEmpty() }
        val body = data["message"].orEmpty().ifBlank { message.notification?.body.orEmpty() }
        DeliveryNotifications.show(this, orderId, title, body)
    }
}
