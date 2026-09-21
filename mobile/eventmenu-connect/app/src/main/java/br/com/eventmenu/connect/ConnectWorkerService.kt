package br.com.eventmenu.connect

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

class ConnectWorkerService : Service() {
    companion object {
        const val ACTION_START = "br.com.eventmenu.connect.START"
        const val ACTION_PAIR = "br.com.eventmenu.connect.PAIR"
        const val ACTION_QR = "br.com.eventmenu.connect.QR"
        const val ACTION_LOGOUT_WHATSAPP = "br.com.eventmenu.connect.LOGOUT_WHATSAPP"
        const val EXTRA_PHONE = "phone"
        private const val CHANNEL_ID = "eventmenu_connect"
        private const val NOTIFICATION_ID = 1010
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var worker: Job? = null
    private lateinit var store: SessionStore
    private lateinit var api: EventMenuApi
    private lateinit var engine: EmbeddedWhatsAppEngine
    private var lastHeartbeat = 0L

    override fun onCreate() {
        super.onCreate()
        store = SessionStore(this)
        api = EventMenuApi(this)
        engine = EmbeddedWhatsAppEngine(this)
        createNotificationChannel()
        promote("Preparando conexão do WhatsApp")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (!store.hasSession()) {
            stopSelf()
            return START_NOT_STICKY
        }

        if (worker?.isActive != true) worker = scope.launch { loop() }

        when (intent?.action) {
            ACTION_PAIR -> {
                val phone = intent.getStringExtra(EXTRA_PHONE).orEmpty()
                scope.launch { pair(phone) }
            }
            ACTION_QR -> scope.launch { startQr() }
            ACTION_LOGOUT_WHATSAPP -> scope.launch { logoutWhatsApp() }
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    private suspend fun loop() {
        while (scope.isActive) {
            if (!store.hasSession()) {
                stopSelf()
                return
            }
            try {
                engine.ensureStarted()
                val local = engine.state()
                syncLocalState(local)

                val now = System.currentTimeMillis()
                if (now - lastHeartbeat > 20_000) {
                    val heartbeat = api.heartbeat(local.status, local.phone, local.error)
                    store.setRuntime(
                        status = local.status,
                        pending = heartbeat.optInt("pending", store.runtimePending()),
                        error = local.error,
                        phone = local.phone,
                        pairingCode = local.pairingCode,
                        qr = local.qr,
                    )
                    lastHeartbeat = now
                }

                if (local.status.equals("connected", ignoreCase = true)) {
                    processQueue()
                    promote(if (local.phone.isBlank()) "WhatsApp conectado • EventMenu ativo" else "WhatsApp ${formatPhone(local.phone)} • EventMenu ativo")
                    delay(2_500)
                } else {
                    promote(
                        when (local.status.lowercase()) {
                            "pairing" -> "Aguardando confirmação do código no WhatsApp"
                            "qr" -> "Aguardando leitura do QR Code"
                            "reconnecting" -> "Reconectando ao WhatsApp"
                            "starting" -> "Iniciando WhatsApp em segundo plano"
                            "error" -> "Falha na conexão do WhatsApp"
                            else -> "EventMenu Connect ativo • WhatsApp desconectado"
                        }
                    )
                    delay(3_500)
                }
            } catch (e: Exception) {
                store.setRuntime("error", error = e.message ?: "Falha no mecanismo interno do WhatsApp")
                promote("EventMenu Connect tentando recuperar a conexão")
                runCatching { api.heartbeat("error", error = e.message.orEmpty()) }
                delay(7_000)
            }
        }
    }

    private fun processQueue() {
        val messages = api.claim(3)
        if (messages.length() == 0) return
        for (index in 0 until messages.length()) {
            val message = messages.getJSONObject(index)
            val id = message.optInt("id")
            val claimToken = message.optString("claim_token")
            val recipient = message.optString("recipient")
            val text = message.optString("message_text")
            try {
                val sent = engine.send(recipient, text)
                api.ack(id, claimToken, sent.messageId)
                store.setRuntime("connected", pending = (store.runtimePending() - 1).coerceAtLeast(0), error = "")
            } catch (e: Exception) {
                runCatching { api.fail(id, claimToken, e.message ?: "Falha no envio local") }
                store.setRuntime("connected", error = "Uma mensagem não pôde ser enviada agora. O sistema tentará novamente.")
            }
        }
    }

    private fun pair(phone: String) {
        try {
            if (phone.filter(Char::isDigit).length < 10) throw IllegalArgumentException("Informe o número do WhatsApp com DDD.")
            promote("Gerando código de conexão")
            val state = engine.pair(phone)
            syncLocalState(state)
            runCatching { api.heartbeat("qr", state.phone, state.error) }
        } catch (e: Exception) {
            store.setRuntime("error", error = e.message ?: "Não foi possível gerar o código.")
        }
    }

    private fun startQr() {
        try {
            promote("Gerando QR Code")
            val state = engine.startQr()
            syncLocalState(state)
            runCatching { api.heartbeat("qr", state.phone, state.error) }
        } catch (e: Exception) {
            store.setRuntime("error", error = e.message ?: "Não foi possível gerar o QR Code.")
        }
    }

    private fun logoutWhatsApp() {
        try {
            val state = engine.logout()
            syncLocalState(state)
            runCatching { api.heartbeat("disconnected") }
            promote("EventMenu Connect ativo • WhatsApp desconectado")
        } catch (e: Exception) {
            store.setRuntime("error", error = e.message ?: "Não foi possível desconectar o WhatsApp.")
        }
    }

    private fun syncLocalState(state: EmbeddedWhatsAppState) {
        store.setRuntime(
            status = state.status,
            error = state.error,
            phone = state.phone,
            pairingCode = state.pairingCode,
            qr = state.qr,
        )
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            getSystemService(NotificationManager::class.java).createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "EventMenu Connect", NotificationManager.IMPORTANCE_LOW).apply {
                    description = "Mantém o WhatsApp do estabelecimento conectado ao EventMenu."
                    setShowBadge(false)
                }
            )
        }
    }

    private fun promote(text: String) {
        val notification = notification(text)
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun notification(text: String): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_eventmenu_connect)
            .setContentTitle("EventMenu Connect")
            .setContentText(text)
            .setOngoing(true)
            .setSilent(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(pendingIntent)
            .build()
    }

    private fun formatPhone(value: String): String {
        val digits = value.filter(Char::isDigit)
        if (digits.startsWith("55") && digits.length >= 12) {
            val ddd = digits.substring(2, 4)
            val number = digits.substring(4)
            return if (number.length == 9) "+55 ($ddd) ${number.take(5)}-${number.drop(5)}" else "+55 ($ddd) $number"
        }
        return if (digits.isBlank()) "" else "+$digits"
    }
}

fun startConnectService(context: Context, action: String = ConnectWorkerService.ACTION_START, phone: String = "") {
    val intent = Intent(context, ConnectWorkerService::class.java).setAction(action)
    if (phone.isNotBlank()) intent.putExtra(ConnectWorkerService.EXTRA_PHONE, phone)
    ContextCompat.startForegroundService(context, intent)
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action == Intent.ACTION_BOOT_COMPLETED && SessionStore(context).hasSession()) {
            runCatching { startConnectService(context) }
        }
    }
}
