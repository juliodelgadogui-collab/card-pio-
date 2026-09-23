package br.com.eventmenu.connect

import android.app.Activity
import android.app.Application
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings

class EventMenuConnectApplication : Application(), Application.ActivityLifecycleCallbacks {
    private var pairingGuard: PowerManager.WakeLock? = null
    private var batteryDialogShownThisProcess = false
    private var resilienceSupervisor: ConnectionResilienceSupervisor? = null

    override fun onCreate() {
        super.onCreate()
        registerActivityLifecycleCallbacks(this)
        resilienceSupervisor = ConnectionResilienceSupervisor(this).also { it.start() }
    }

    override fun onActivityResumed(activity: Activity) {
        holdPairingGuard()
        requestReliableBackgroundExecution(activity)
    }

    private fun holdPairingGuard() {
        val power = getSystemService(PowerManager::class.java) ?: return
        val lock = pairingGuard ?: power.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "$packageName:WhatsAppPairingGuard",
        ).apply {
            setReferenceCounted(false)
            pairingGuard = this
        }

        if (lock.isHeld) runCatching { lock.release() }
        // A troca EventMenu Connect -> WhatsApp acontece justamente durante o
        // pareamento. Mantemos CPU/socket acordados apenas nesta janela curta;
        // não é um wake lock permanente.
        runCatching { lock.acquire(5 * 60 * 1000L) }
    }

    private fun requestReliableBackgroundExecution(activity: Activity) {
        if (batteryDialogShownThisProcess) return
        val power = getSystemService(PowerManager::class.java) ?: return
        if (power.isIgnoringBatteryOptimizations(packageName)) return

        batteryDialogShownThisProcess = true
        runCatching {
            activity.startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                    data = Uri.parse("package:$packageName")
                }
            )
        }
    }

    override fun onTerminate() {
        resilienceSupervisor?.stop()
        resilienceSupervisor = null
        pairingGuard?.let { lock -> if (lock.isHeld) runCatching { lock.release() } }
        pairingGuard = null
        unregisterActivityLifecycleCallbacks(this)
        super.onTerminate()
    }

    override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) = Unit
    override fun onActivityStarted(activity: Activity) = Unit
    override fun onActivityPaused(activity: Activity) = Unit
    override fun onActivityStopped(activity: Activity) = Unit
    override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) = Unit
    override fun onActivityDestroyed(activity: Activity) = Unit
}
