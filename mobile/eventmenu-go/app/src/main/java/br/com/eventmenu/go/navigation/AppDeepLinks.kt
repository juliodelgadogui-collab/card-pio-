package br.com.eventmenu.go.navigation

import android.content.Context
import android.content.Intent
import android.net.Uri
import br.com.eventmenu.go.MainActivity
import br.com.eventmenu.go.data.AppNotification

data class AppDeepLinkTarget(
    val notificationId: Int? = null,
    val notificationType: String = "",
    val mode: String = "",
    val entityType: String = "",
    val entityId: String = "",
)

object AppDeepLinks {
    const val SCHEME = "eventmenugo"
    const val HOST = "open"

    const val EXTRA_NOTIFICATION_ID = "notification_id"
    const val EXTRA_NOTIFICATION_TYPE = "notification_type"
    const val EXTRA_MODE = "notification_mode"
    const val EXTRA_ENTITY_TYPE = "entity_type"
    const val EXTRA_ENTITY_ID = "entity_id"

    fun target(notification: AppNotification): AppDeepLinkTarget = AppDeepLinkTarget(
        notificationId = notification.id,
        notificationType = notification.type,
        mode = notification.mode,
        entityType = notification.entityType.ifBlank { "notification" },
        entityId = notification.entityId.ifBlank { notification.id.toString() },
    )

    fun uri(notification: AppNotification): Uri = uri(target(notification))

    fun uri(target: AppDeepLinkTarget): Uri = Uri.Builder()
        .scheme(SCHEME)
        .authority(HOST)
        .appendPath(target.entityType.ifBlank { "notification" })
        .appendPath(target.entityId.ifBlank { target.notificationId?.toString().orEmpty() })
        .apply {
            target.notificationId?.let { appendQueryParameter(EXTRA_NOTIFICATION_ID, it.toString()) }
            if (target.notificationType.isNotBlank()) appendQueryParameter(EXTRA_NOTIFICATION_TYPE, target.notificationType)
            if (target.mode.isNotBlank()) appendQueryParameter(EXTRA_MODE, target.mode)
        }
        .build()

    fun intent(context: Context, notification: AppNotification): Intent = intent(context, target(notification))

    fun intent(context: Context, target: AppDeepLinkTarget): Intent = Intent(
        Intent.ACTION_VIEW,
        uri(target),
        context,
        MainActivity::class.java,
    ).apply {
        flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
        target.notificationId?.let { putExtra(EXTRA_NOTIFICATION_ID, it) }
        putExtra(EXTRA_NOTIFICATION_TYPE, target.notificationType)
        putExtra(EXTRA_MODE, target.mode)
        putExtra(EXTRA_ENTITY_TYPE, target.entityType)
        putExtra(EXTRA_ENTITY_ID, target.entityId)
    }

    fun parse(intent: Intent?): AppDeepLinkTarget? {
        if (intent == null) return null
        val data = intent.data
        if (data != null && data.scheme.equals(SCHEME, ignoreCase = true) && data.host.equals(HOST, ignoreCase = true)) {
            val entityType = data.pathSegments.getOrNull(0).orEmpty()
            val entityId = data.pathSegments.getOrNull(1).orEmpty()
            val notificationId = data.getQueryParameter(EXTRA_NOTIFICATION_ID)?.toIntOrNull()
            if (notificationId == null && entityType.isBlank() && entityId.isBlank()) return null
            return AppDeepLinkTarget(
                notificationId = notificationId,
                notificationType = data.getQueryParameter(EXTRA_NOTIFICATION_TYPE).orEmpty(),
                mode = data.getQueryParameter(EXTRA_MODE).orEmpty(),
                entityType = entityType,
                entityId = entityId,
            )
        }

        val notificationId = if (intent.hasExtra(EXTRA_NOTIFICATION_ID)) intent.getIntExtra(EXTRA_NOTIFICATION_ID, 0).takeIf { it > 0 } else null
        val notificationType = intent.getStringExtra(EXTRA_NOTIFICATION_TYPE).orEmpty()
        val mode = intent.getStringExtra(EXTRA_MODE).orEmpty()
        val entityType = intent.getStringExtra(EXTRA_ENTITY_TYPE).orEmpty()
        val entityId = intent.getStringExtra(EXTRA_ENTITY_ID).orEmpty()
        if (notificationId == null && entityType.isBlank() && entityId.isBlank()) return null
        return AppDeepLinkTarget(notificationId, notificationType, mode, entityType, entityId)
    }
}
