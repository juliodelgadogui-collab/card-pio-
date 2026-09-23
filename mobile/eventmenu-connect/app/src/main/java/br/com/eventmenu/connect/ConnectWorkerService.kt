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
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
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
import kotlin.math.min

class ConnectWorkerService : Service() {
    companion object {
        const val ACTION_START = "br.com.eventmenu.connect.START"
        const val ACTION_PAIR = "br.com.eventmenu.connect.PAIR"
        const val ACTION_QR = "br.com.eventmenu.connect.QR"
        const val ACTION_LOGOUT_WHATSAPP = "br.com.eventmenu.connect.LOGOUT_WHATSAPP"
        const val EXTRA_PHONE = "phone"
        const val EXTRA_COUNTRY = "country"
        private const val CHANNEL_ID = "eventmenu_connect"
        private const val NOTIFICATION_ID = 1010
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var worker: Job? = null
    private lateinit var store: SessionStore
    private lateinit var api: EventMenuApi
    private lateinit var engine: EmbeddedWhatsAppEngine
    private var lastHeartbeat = 0L
    private var consecutiveFailures = 0

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
                val country = intent.getStringExtra(EXTRA_COUNTRY).orEmpty().ifBlank { store.pairingCountryRegion() }
                scope.launch { pair(phone, country) }
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

            if (!networkAvailable()) {
                promote("EventMenu Connect • aguardando internet")
                if (store.runtimeStatus() != "connected") {
                    store.setRuntime(store.runtimeStatus(), error = "Sem internet. O Connect reconecta automaticamente quando a rede voltar.")
                }
                delay(8_000)
                continue
            }

            try {
                engine.ensureStarted()
                val local = engine.state()
                syncLocalState(local)

                val now = System.currentTimeMillis()
                if (now - lastHeartbeat > 30_000) {
                    val inboundDiagnostic = when {
                        local.inboundUnresolved > 0 -> "${local.inboundUnresolved} mensagem(ns) recebida(s) aguardando resolução segura do número WhatsApp."
                        local.lastInboundError.isNotBlank() -> local.lastInboundError
                        else -> local.error
                    }
                    val heartbeat = api.heartbeat(local.status, local.phone, inboundDiagnostic)
                    store.setRuntime(
                        status = local.status,
                        pending = heartbeat.optInt("pending", store.runtimePending()),
                        error = inboundDiagnostic,
                        phone = local.phone,
                        pairingCode = local.pairingCode,
                        qr = local.qr,
                    )
                    lastHeartbeat = now
                }

                consecutiveFailures = 0
                if (local.status.equals("connected", ignoreCase = true)) {
                    val received = processInbound()
                    val acknowledged = flushPendingAcks()
                    val hasPendingAck = store.pendingOutboundAcks().isNotEmpty()
                    val sent = if (hasPendingAck) 0 else processQueue()
                    val processed = received + acknowledged + sent
                    promote(
                        when {
                            local.inboundUnresolved > 0 -> "WhatsApp conectado • ${local.inboundUnresolved} recebida(s) aguardando identificação"
                            local.inboundPending > 0 -> "WhatsApp conectado • enviando recebidas ao servidor"
                            hasPendingAck -> "WhatsApp conectado • confirmando envio com o servidor"
                            local.phone.isBlank() -> "WhatsApp conectado • EventMenu ativo"
                            else -> "WhatsApp ${formatPhone(local.phone)} • EventMenu ativo"
                        }
                    )
                    delay(if (processed > 0) 1_500 else if (hasPendingAck) 3_000 else 8_000)
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
                    delay(if (local.status.equals("pairing", true) || local.status.equals("qr", true)) 1_500 else 5_000)
                }
            } catch (e: Exception) {
                consecutiveFailures = min(consecutiveFailures + 1, 6)
                val message = e.message ?: "Falha no mecanismo interno do WhatsApp"
                store.setRuntime("error", error = message)
                promote("EventMenu Connect tentando recuperar a conexão")
                runCatching { api.heartbeat("error", error = message) }
                val backoff = min(60_000L, 4_000L * (1L shl (consecutiveFailures - 1)))
                delay(backoff)
            }
        }
    }

    private fun processInbound(): Int {
        val messages = engine.inbound(10)
        if (messages.length() == 0) return 0
        var processed = 0
        for (index in 0 until messages.length()) {
            val message = messages.optJSONObject(index) ?: continue
            val providerMessageId = message.optString("provider_message_id").trim()
            if (providerMessageId.isBlank()) continue
            try {
                api.inbound(message)
                engine.acknowledgeInbound(providerMessageId)
                processed++
                store.setRuntime("connected", error = "")
            } catch (_: Exception) {
                store.setRuntime("connected", error = "O servidor ainda não confirmou uma mensagem recebida. Ela continuará na fila local.")
                break
            }
        }
        return processed
    }

    private fun flushPendingAcks(): Int {
        val receipts = store.pendingOutboundAcks()
        if (receipts.isEmpty()) return 0
        var confirmed = 0
        for (receipt in receipts) {
            try {
                api.ack(receipt.id, receipt.claimToken, receipt.externalMessageId)
                store.removePendingOutboundAck(receipt.id)
                confirmed++
                store.setRuntime(
                    "connected",
                    pending = (store.runtimePending() - 1).coerceAtLeast(0),
                    error = "",
                )
            } catch (e: ApiException) {
                if (e.status == 422) {
                    // O lease antigo expirou ou foi reaberto no servidor. Liberamos o recibo
                    // local para que a mesma outbox seja reivindicada novamente. O runtime
                    // Node guarda a idempotência por empresa + ID da outbox e não reenviará.
                    store.removePendingOutboundAck(receipt.id)
                    store.setRuntime("connected", error = "Confirmação antiga expirou. O Connect reconciliará o envio sem duplicar a mensagem.")
                    continue
                }
                store.setRuntime("connected", error = "Mensagem já enviada ao WhatsApp e aguardando confirmação do servidor. Ela não será reenviada.")
                break
            } catch (_: Exception) {
                store.setRuntime("connected", error = "Mensagem já enviada ao WhatsApp e aguardando confirmação do servidor. Ela não será reenviada.")
                break
            }
        }
        return confirmed
    }

    private fun processQueue(): Int {
        // Uma reserva por vez evita que PDFs/imagens lentos consumam o lease das mensagens seguintes.
        val messages = api.claim(1)
        if (messages.length() == 0) return 0
        var processed = 0
        for (index in 0 until messages.length()) {
            val message = messages.getJSONObject(index)
            val id = message.optInt("id")
            val claimToken = message.optString("claim_token")
            val recipient = message.optString("recipient")
            val text = message.optString("message_text")
            val mediaType = message.optString("media_type").trim().lowercase()
            val mediaUrl = message.optString("media_url").trim()
            val mediaFilename = message.optString("media_filename").trim()
            val mediaMime = message.optString("media_mime").trim()
            val idempotencyKey = "eventmenu-tenant-${store.tenantId()}-outbox-$id"

            val sent = try {
                when {
                    mediaType.isBlank() && mediaUrl.isBlank() -> engine.send(recipient, text, idempotencyKey)
                    mediaUrl.isBlank() -> throw IllegalArgumentException("A mídia da mensagem está sem endereço para download.")
                    mediaType == "image" -> engine.sendMedia(recipient, text, "image", mediaUrl, mediaFilename, mediaMime, idempotencyKey)
                    mediaType == "document" || mediaType == "pdf" -> engine.sendMedia(recipient, text, "document", mediaUrl, mediaFilename, mediaMime, idempotencyKey)
                    else -> throw IllegalArgumentException("Tipo de mídia não suportado: $mediaType")
                }
            } catch (e: Exception) {
                runCatching { api.fail(id, claimToken, e.message ?: "Falha no envio local") }
                store.setRuntime("connected", error = "Uma mensagem não pôde ser enviada agora. O sistema tentará novamente.")
                continue
            }

            // O envio ao WhatsApp já aconteceu. A partir daqui NUNCA chamamos fail().
            // Persistimos o recibo antes do ACK HTTP para sobreviver a perda de rede/processo.
            store.savePendingOutboundAck(id, claimToken, sent.messageId)
            try {
                api.ack(id, claimToken, sent.messageId)
                store.removePendingOutboundAck(id)
                processed++
                store.setRuntime("connected", pending = (store.runtimePending() - 1).coerceAtLeast(0), error = "")
            } catch (_: Exception) {
                store.setRuntime("connected", error = "Mensagem enviada ao WhatsApp. Falta apenas confirmar com o servidor; ela não será reenviada.")
                break
            }
        }
        return processed
    }

    private fun pair(phone: String, countryCode: String) {
        try {
            val digits = phone.filter(Char::isDigit)
            if (digits.length !in 8..15) throw IllegalArgumentException("Informe um número de WhatsApp válido.")
            val region = countryCode.trim().uppercase()
            if (!Regex("^[A-Z]{2}$").matches(region)) throw IllegalArgumentException("País inválido.")
            store.savePairingCountry(region)
            store.clearRuntimeError()
            promote("Gerando código de conexão")
            val state = engine.pair(digits, region)
            syncLocalState(state)
            runCatching { api.heartbeat("qr", state.phone, state.error) }
        } catch (e: Exception) {
            store.setRuntime("error", pairingCode = "", qr = "", error = e.message ?: "Não foi possível gerar o código.")
        }
    }

    private fun startQr() {
        try {
            store.clearRuntimeError()
            promote("Gerando QR Code")
            val state = engine.startQr()
            syncLocalState(state)
            runCatching { api.heartbeat("qr", state.phone, state.error) }
        } catch (e: Exception) {
            store.setRuntime("error", qr = "", error = e.message ?: "Não foi possível gerar o QR Code.")
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

    private fun networkAvailable(): Boolean {
        val manager = getSystemService(ConnectivityManager::class.java) ?: return true
        val network = manager.activeNetwork ?: return false
        val caps = manager.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
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
        if (Build.VERSION.SDK_INT >= 34) {
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

fun startConnectService(
    context: Context,
    action: String = ConnectWorkerService.ACTION_START,
    phone: String = "",
    countryCode: String = "",
) {
    val intent = Intent(context, ConnectWorkerService::class.java).setAction(action)
    if (phone.isNotBlank()) intent.putExtra(ConnectWorkerService.EXTRA_PHONE, phone)
    if (countryCode.isNotBlank()) intent.putExtra(ConnectWorkerService.EXTRA_COUNTRY, countryCode)
    ContextCompat.startForegroundService(context, intent)
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action == Intent.ACTION_BOOT_COMPLETED && SessionStore(context).hasSession()) {
            runCatching { startConnectService(context) }
        }
    }
}
