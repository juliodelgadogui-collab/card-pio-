package br.com.eventmenu.go.delivery

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.R
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import java.time.Instant

class DeliveryLocationService : Service(), LocationListener {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var locationManager: LocationManager? = null
    private var activeOrderId: Int = 0
    private var sendJob: Job? = null
    private var lastSentAt = 0L

    override fun onCreate() {
        super.onCreate()
        createChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        activeOrderId = intent?.getIntExtra(EXTRA_ORDER_ID, 0) ?: 0
        if (activeOrderId < 1 || !hasLocationPermission(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        startForeground(NOTIFICATION_ID, notification(activeOrderId))
        requestUpdates()
        return START_REDELIVER_INTENT
    }

    @Suppress("MissingPermission")
    private fun requestUpdates() {
        val manager = (getSystemService(Context.LOCATION_SERVICE) as LocationManager).also { locationManager = it }
        manager.removeUpdates(this)
        if (manager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
            manager.requestLocationUpdates(LocationManager.GPS_PROVIDER, 10_000L, 20f, this)
        }
        if (manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
            manager.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 15_000L, 30f, this)
        }
    }

    override fun onLocationChanged(location: Location) {
        val orderId = activeOrderId
        if (orderId < 1) return
        val now = System.currentTimeMillis()
        if (now - lastSentAt < 8_000L) return
        if (location.accuracy > 500f) return
        lastSentAt = now
        sendJob?.cancel()
        sendJob = scope.launch {
            runCatching {
                val app = applicationContext as EventMenuGoApplication
                app.deliveryProgressRepository.sendLocation(
                    orderId = orderId,
                    latitude = location.latitude,
                    longitude = location.longitude,
                    accuracyM = location.accuracy.toDouble(),
                    speedMps = location.speed.takeIf { location.hasSpeed() }?.toDouble(),
                    headingDegrees = location.bearing.takeIf { location.hasBearing() }?.toDouble(),
                    provider = location.provider.orEmpty(),
                    recordedAt = Instant.ofEpochMilli(location.time.coerceAtLeast(1L)).toString(),
                )
            }.onFailure { error ->
                val message = error.message.orEmpty()
                if (message.contains("rota", ignoreCase = true) || message.contains("turno", ignoreCase = true) || message.contains("atribuído", ignoreCase = true)) {
                    stopSelf()
                }
            }
        }
    }

    override fun onProviderEnabled(provider: String) = Unit
    override fun onProviderDisabled(provider: String) = Unit
    @Deprecated("Deprecated in Java") override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

    override fun onDestroy() {
        locationManager?.removeUpdates(this)
        locationManager = null
        sendJob?.cancel()
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val channel = NotificationChannel(CHANNEL_ID, "Rota de entrega", NotificationManager.IMPORTANCE_LOW).apply {
            description = "Mostra quando o EventMenu está compartilhando a localização durante uma entrega ativa."
            setShowBadge(false)
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    private fun notification(orderId: Int): Notification = NotificationCompat.Builder(this, CHANNEL_ID)
        .setSmallIcon(R.drawable.ic_eventmenu_logo)
        .setContentTitle("Entrega em andamento")
        .setContentText("Compartilhando localização do pedido #$orderId")
        .setOngoing(true)
        .setOnlyAlertOnce(true)
        .setCategory(NotificationCompat.CATEGORY_SERVICE)
        .build()

    companion object {
        private const val CHANNEL_ID = "eventmenu_delivery_route"
        private const val NOTIFICATION_ID = 4302
        private const val ACTION_START = "br.com.eventmenu.go.delivery.START"
        private const val ACTION_STOP = "br.com.eventmenu.go.delivery.STOP"
        private const val EXTRA_ORDER_ID = "order_id"

        fun start(context: Context, orderId: Int) {
            if (orderId < 1 || !hasLocationPermission(context)) return
            val intent = Intent(context, DeliveryLocationService::class.java).setAction(ACTION_START).putExtra(EXTRA_ORDER_ID, orderId)
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            val intent = Intent(context, DeliveryLocationService::class.java).setAction(ACTION_STOP)
            runCatching { context.startService(intent) }
            context.stopService(Intent(context, DeliveryLocationService::class.java))
        }

        fun hasLocationPermission(context: Context): Boolean =
            ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    }
}
