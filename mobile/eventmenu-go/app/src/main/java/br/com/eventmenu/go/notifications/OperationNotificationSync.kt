package br.com.eventmenu.go.notifications

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
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.Constraints
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.MainActivity
import br.com.eventmenu.go.data.AppNotification
import java.util.concurrent.TimeUnit

object OperationNotificationScheduler {
    private const val WORK_NAME = "eventmenu-operation-notifications"
    const val CHANNEL_ID = "eventmenu_operations"

    fun initialize(context: Context) {
        createChannel(context)
        val constraints = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
        val request = PeriodicWorkRequestBuilder<OperationNotificationWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    fun show(context: Context, notification: AppNotification) {
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val store = context.getSharedPreferences("eventmenu_notification_display", Context.MODE_PRIVATE)
        val key = "shown_${notification.id}"
        if (store.getBoolean(key, false)) return

        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra("notification_id", notification.id)
            putExtra("entity_type", notification.entityType)
            putExtra("entity_id", notification.entityId)
        }
        val pendingIntent = PendingIntent.getActivity(
            context,
            notification.id,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(notification.title.ifBlank { "EventMenu GO" })
            .setContentText(notification.message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(notification.message))
            .setPriority(if (notification.priority in setOf("warning", "critical")) NotificationCompat.PRIORITY_HIGH else NotificationCompat.PRIORITY_DEFAULT)
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)

        NotificationManagerCompat.from(context).notify(notification.id, builder.build())
        store.edit().putBoolean(key, true).apply()
    }

    fun showUnread(context: Context, items: List<AppNotification>) {
        items.filter { it.readAt.isBlank() }.sortedBy { it.id }.takeLast(20).forEach { show(context, it) }
    }

    private fun createChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java)
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Operação EventMenu",
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = "Pedidos, pagamentos, cozinha, entregas e eventos do turno."
            enableVibration(true)
        }
        manager.createNotificationChannel(channel)
    }
}

class OperationNotificationWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext as? EventMenuGoApplication ?: return Result.success()
        return runCatching {
            val inbox = app.notificationRepository.inbox(80)
            OperationNotificationScheduler.showUnread(applicationContext, inbox.items)
            Result.success()
        }.getOrElse { Result.success() }
    }
}
