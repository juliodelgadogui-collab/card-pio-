package br.com.eventmenu.connect

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.PowerManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlin.math.min

/**
 * Mantém o Connect resiliente sem expor detalhes técnicos na interface.
 *
 * Responsabilidades:
 * - perceber retorno/troca de rede e estimular a retomada da sessão;
 * - manter CPU/socket acordados apenas durante transições críticas;
 * - detectar estados de reconexão travados e pedir uma retomada segura;
 * - nunca apagar sessão, fila inbound ou recibos de ACK durante autorreparo.
 */
class ConnectionResilienceSupervisor(context: Context) {
    private val appContext = context.applicationContext
    private val store = SessionStore(appContext)
    private val engine = EmbeddedWhatsAppEngine(appContext)
    private val connectivity = appContext.getSystemService(ConnectivityManager::class.java)
    private val power = appContext.getSystemService(PowerManager::class.java)
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private var monitorJob: Job? = null
    private var started = false
    private var networkValidated = false
    private var observedStatus = ""
    private var observedStatusSince = 0L
    private var lastRecoveryAt = 0L
    private var recoveryFailures = 0
    private var transitionWakeLock: PowerManager.WakeLock? = null

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) = scheduleNetworkRecheck()
        override fun onLost(network: Network) = scheduleNetworkRecheck()
        override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) = scheduleNetworkRecheck()
    }

    @Synchronized
    fun start() {
        if (started) return
        started = true
        networkValidated = hasValidatedNetwork()
        runCatching { connectivity?.registerDefaultNetworkCallback(networkCallback) }
        monitorJob = scope.launch { monitorLoop() }
    }

    @Synchronized
    fun stop() {
        if (!started) return
        started = false
        runCatching { connectivity?.unregisterNetworkCallback(networkCallback) }
        monitorJob?.cancel()
        monitorJob = null
        releaseTransitionWakeLock()
        scope.cancel()
    }

    private fun scheduleNetworkRecheck() {
        if (!started) return
        scope.launch {
            delay(500)
            val nowValidated = hasValidatedNetwork()
            val restored = nowValidated && !networkValidated
            networkValidated = nowValidated
            if (restored && store.hasSession()) {
                recoveryFailures = 0
                attemptRecovery(force = true)
            }
        }
    }

    private suspend fun monitorLoop() {
        while (scope.isActive) {
            if (!store.hasSession()) {
                releaseTransitionWakeLock()
                delay(15_000)
                continue
            }

            if (!hasValidatedNetwork()) {
                releaseTransitionWakeLock()
                delay(6_000)
                continue
            }

            val local = runCatching {
                engine.ensureStarted()
                engine.state()
            }.getOrNull()

            if (local == null) {
                attemptRecovery(force = false)
                delay(8_000)
                continue
            }

            noteStatus(local.status)
            val normalized = local.status.lowercase()
            val pairingActive = local.qr.isNotBlank() || local.pairingCode.isNotBlank() || normalized == "pairing" || normalized == "qr"

            when (normalized) {
                "connected" -> {
                    recoveryFailures = 0
                    releaseTransitionWakeLock()
                }
                "pairing", "qr", "reconnecting", "starting" -> holdTransitionWakeLock()
                else -> releaseTransitionWakeLock()
            }

            if (!pairingActive && shouldRecover(local)) {
                attemptRecovery(force = false)
            }

            delay(if (normalized == "connected") 15_000 else 6_000)
        }
    }

    private fun noteStatus(status: String) {
        val normalized = status.lowercase()
        if (normalized != observedStatus) {
            observedStatus = normalized
            observedStatusSince = System.currentTimeMillis()
        } else if (observedStatusSince == 0L) {
            observedStatusSince = System.currentTimeMillis()
        }
    }

    private fun shouldRecover(local: EmbeddedWhatsAppState): Boolean {
        val normalized = local.status.lowercase()
        val now = System.currentTimeMillis()
        val stuckFor = now - observedStatusSince
        val friendlyTerminal = local.error.contains("sessão encerrada", ignoreCase = true) ||
            local.error.contains("recusou", ignoreCase = true) ||
            local.error.contains("novo vínculo", ignoreCase = true)

        if (friendlyTerminal) return false

        return when (normalized) {
            "reconnecting", "starting" -> stuckFor >= 90_000
            "error" -> stuckFor >= 20_000
            "disconnected" -> local.error.isBlank() && stuckFor >= 30_000
            else -> false
        }
    }

    private suspend fun attemptRecovery(force: Boolean) {
        if (!store.hasSession() || !hasValidatedNetwork()) return

        val now = System.currentTimeMillis()
        val cooldown = min(5 * 60_000L, 15_000L * (1L shl recoveryFailures.coerceIn(0, 4)))
        if (!force && now - lastRecoveryAt < cooldown) return
        lastRecoveryAt = now

        holdTransitionWakeLock()
        val recovered = runCatching {
            engine.ensureStarted()
            val current = engine.state()
            val status = current.status.lowercase()
            val activePairing = current.qr.isNotBlank() || current.pairingCode.isNotBlank() || status == "pairing" || status == "qr"
            if (activePairing || status == "connected") return@runCatching true

            // /start reaproveita as credenciais existentes. Não apaga sessão nem filas.
            engine.startQr()
            true
        }.getOrDefault(false)

        if (recovered) {
            recoveryFailures = 0
        } else {
            recoveryFailures = (recoveryFailures + 1).coerceAtMost(6)
        }
    }

    private fun hasValidatedNetwork(): Boolean {
        val manager = connectivity ?: return true
        val network = manager.activeNetwork ?: return false
        val caps = manager.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    private fun holdTransitionWakeLock() {
        val manager = power ?: return
        val lock = transitionWakeLock ?: manager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "${appContext.packageName}:ConnectionTransition",
        ).apply {
            setReferenceCounted(false)
            transitionWakeLock = this
        }

        if (!lock.isHeld) {
            // Curto e renovável: protege pareamento/reconexão sem manter a CPU presa
            // quando a sessão já está estável.
            runCatching { lock.acquire(2 * 60 * 1000L) }
        }
    }

    private fun releaseTransitionWakeLock() {
        transitionWakeLock?.let { lock ->
            if (lock.isHeld) runCatching { lock.release() }
        }
    }
}
