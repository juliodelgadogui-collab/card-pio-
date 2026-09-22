package br.com.eventmenu.go.notifications

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.AppNotification
import br.com.eventmenu.go.navigation.AppDeepLinks
import java.util.concurrent.TimeUnit

object OperationNotificationScheduler {
    private const val WORK_NAME = "eventmenu-operation-notifications"
    const val CHANNEL_ID = "eventmenu_operations"

    fun initialize(context: Context) {
        createChannel(context)
        val constraints = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
        val request = PeriodicWorkRequestBuilder<OperationNotificationWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
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

        val intent = AppDeepLinks.intent(context, notification)
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
            .setGroup("eventmenu_${notification.mode.ifBlank { "general" }}")
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
        return try {
            // If login happened while the device was temporarily offline, retry the
            // FCM registration whenever the normal notification poll gets network.
            // The coordinator still refuses registration when no authenticated
            // session exists, so this is safe after logout/session expiry.
            app.pushCoordinator.syncCurrentToken()
            val inbox = app.notificationRepository.inbox(80)
            OperationNotificationScheduler.showUnread(applicationContext, inbox.items)
            Result.success()
        } catch (error: ApiException) {
            if (error.status == 0 || error.status >= 500) Result.retry() else Result.success()
        } catch (_: Throwable) {
            Result.success()
        }
    }
}
