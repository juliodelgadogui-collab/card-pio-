package br.com.eventmenu.delivery

import android.app.Application
import br.com.eventmenu.delivery.data.DeliveryApi
import br.com.eventmenu.delivery.data.SecureSessionStore
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class DeliveryApplication : Application() {
    lateinit var sessionStore: SecureSessionStore
        private set
    lateinit var pushCoordinator: DeliveryPushCoordinator
        private set

    override fun onCreate() {
        super.onCreate()
        sessionStore = SecureSessionStore(this)
        DeliveryNotifications.createChannel(this)
        pushCoordinator = DeliveryPushCoordinator(this, sessionStore)
        pushCoordinator.initialize()
    }
}

class DeliveryPushCoordinator(
    private val app: Application,
    private val session: SecureSessionStore,
) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val api by lazy { DeliveryApi { session.accessToken } }

    fun initialize() {
        if (!BuildConfig.FIREBASE_ENABLED) return
        runCatching {
            if (FirebaseApp.getApps(app).isEmpty()) {
                val options = FirebaseOptions.Builder()
                    .setProjectId(BuildConfig.FIREBASE_PROJECT_ID)
                    .setApplicationId(BuildConfig.FIREBASE_APP_ID)
                    .setApiKey(BuildConfig.FIREBASE_API_KEY)
                    .setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID)
                    .build()
                FirebaseApp.initializeApp(app, options)
            }
            FirebaseMessaging.getInstance().token.addOnSuccessListener(::registerToken)
        }
    }

    fun registerToken(token: String) {
        if (!BuildConfig.FIREBASE_ENABLED || token.isBlank() || session.accessToken.isNullOrBlank()) return
        scope.launch {
            runCatching {
                api.registerPush(token)
            }
        }
    }

    fun syncAfterLogin() {
        if (!BuildConfig.FIREBASE_ENABLED || session.accessToken.isNullOrBlank()) return
        runCatching { FirebaseMessaging.getInstance().token.addOnSuccessListener(::registerToken) }
    }
}
