package br.com.eventmenu.go.notifications

import android.content.Context
import br.com.eventmenu.go.BuildConfig
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.data.AppNotification
import br.com.eventmenu.go.data.NotificationRepository
import br.com.eventmenu.go.security.SecureSessionStore
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

object FirebasePushConfig {
    @Volatile
    private var initialized = false

    fun initialize(context: Context): Boolean {
        if (initialized) return true
        if (!isConfigured()) return false
        return synchronized(this) {
            if (initialized) return@synchronized true
            runCatching {
                if (FirebaseApp.getApps(context).isEmpty()) {
                    val options = FirebaseOptions.Builder()
                        .setProjectId(BuildConfig.FIREBASE_PROJECT_ID)
                        .setApplicationId(BuildConfig.FIREBASE_APP_ID)
                        .setApiKey(BuildConfig.FIREBASE_API_KEY)
                        .setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID)
                        .build()
                    FirebaseApp.initializeApp(context, options)
                }
                initialized = FirebaseApp.getApps(context).isNotEmpty()
                initialized
            }.getOrDefault(false)
        }
    }

    fun isConfigured(): Boolean = BuildConfig.FIREBASE_ENABLED
}

class FirebasePushCoordinator(
    context: Context,
    private val repository: NotificationRepository,
    private val sessionStore: SecureSessionStore,
) {
    private val appContext = context.applicationContext
    private val prefs = appContext.getSharedPreferences("eventmenu_push_session", Context.MODE_PRIVATE)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val configured = FirebasePushConfig.initialize(appContext)

    fun updateSession(tenantId: Int, userId: Int) {
        if (tenantId < 1 || userId < 1) return
        prefs.edit()
            .putInt(KEY_TENANT_ID, tenantId)
            .putInt(KEY_USER_ID, userId)
            .apply()
        syncCurrentToken()
    }

    fun syncCurrentToken() {
        if (!configured || sessionStore.token().isNullOrBlank()) return
        runCatching { FirebaseMessaging.getInstance() }
            .getOrNull()
            ?.token
            ?.addOnSuccessListener(::registerToken)
    }

    fun registerToken(token: String) {
        if (!configured || token.isBlank() || sessionStore.token().isNullOrBlank()) return
        scope.launch {
            runCatching { repository.registerPushToken(token) }
        }
    }

    fun accepts(tenantId: Int, userId: Int): Boolean {
        if (sessionStore.token().isNullOrBlank()) return false
        if (tenantId < 1 || userId < 1) return false
        return prefs.getInt(KEY_TENANT_ID, 0) == tenantId && prefs.getInt(KEY_USER_ID, 0) == userId
    }

    companion object {
        private const val KEY_TENANT_ID = "tenant_id"
        private const val KEY_USER_ID = "user_id"
    }
}

class EventMenuFirebaseMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val app = application as? EventMenuGoApplication ?: return
        app.pushCoordinator.registerToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val app = application as? EventMenuGoApplication ?: return
        val data = message.data
        val notificationId = data["notification_id"]?.toIntOrNull()?.takeIf { it > 0 } ?: return
        val tenantId = data["tenant_id"]?.toIntOrNull() ?: return
        val userId = data["user_id"]?.toIntOrNull() ?: return
        if (!app.pushCoordinator.accepts(tenantId, userId)) return

        val title = data["title"].orEmpty().ifBlank { message.notification?.title.orEmpty() }
        val body = data["message"].orEmpty().ifBlank { message.notification?.body.orEmpty() }
        if (title.isBlank() || body.isBlank()) return

        OperationNotificationScheduler.show(
            this,
            AppNotification(
                id = notificationId,
                mode = data["notification_mode"].orEmpty().ifBlank { data["mode"].orEmpty() },
                type = data["notification_type"].orEmpty().ifBlank { data["type"].orEmpty() },
                priority = data["priority"].orEmpty().ifBlank { "info" },
                title = title,
                message = body,
                entityType = data["entity_type"].orEmpty(),
                entityId = data["entity_id"].orEmpty(),
                readAt = "",
                expiresAt = data["expires_at"].orEmpty(),
                createdAt = "",
            ),
        )
    }
}
