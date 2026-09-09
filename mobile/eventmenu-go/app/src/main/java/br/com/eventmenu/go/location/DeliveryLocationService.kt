package br.com.eventmenu.go.location

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import androidx.core.location.LocationCompat
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.MainActivity
import br.com.eventmenu.go.data.DeliveryLocationSample
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

class DeliveryLocationService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var fused: FusedLocationProviderClient
    private lateinit var buffer: DeliveryLocationBuffer
    private var lastAccepted: Location? = null
    private var lastBufferedAt = 0L
    @Volatile private var uploading = false

    private val callback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.locations.forEach(::handleLocation)
        }
    }

    override fun onCreate() {
        super.onCreate()
        fused = LocationServices.getFusedLocationProviderClient(this)
        buffer = DeliveryLocationBuffer(this)
        createChannel()
        startForeground(NOTIFICATION_ID, notification("Preparando localização da entrega…"))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        if (!hasPermission()) {
            stopSelf()
            return START_NOT_STICKY
        }

        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 8_000L)
            .setMinUpdateIntervalMillis(5_000L)
            .setMaxUpdateDelayMillis(15_000L)
            .setMinUpdateDistanceMeters(8f)
            .build()

        try {
            fused.removeLocationUpdates(callback)
            fused.requestLocationUpdates(request, callback, Looper.getMainLooper())
            updateNotification("GPS ativo · compartilhando somente durante a rota")
            flushBuffer()
        } catch (_: SecurityException) {
            stopSelf()
            return START_NOT_STICKY
        }
        return START_STICKY
    }

    private fun handleLocation(location: Location) {
        val now = System.currentTimeMillis()
        val moved = lastAccepted?.distanceTo(location) ?: Float.MAX_VALUE
        val heartbeat = now - lastBufferedAt >= HEARTBEAT_MS
        val accurateEnough = !location.hasAccuracy() || location.accuracy <= MAX_CAPTURE_ACCURACY_M
        if (!accurateEnough && !heartbeat) return
        if (moved < MIN_MOVEMENT_M && !heartbeat) return

        lastAccepted = Location(location)
        lastBufferedAt = now
        val sample = DeliveryLocationSample(
            latitude = location.latitude,
            longitude = location.longitude,
            accuracyM = location.accuracy.takeIf { location.hasAccuracy() }?.toDouble(),
            speedMps = location.speed.takeIf { location.hasSpeed() }?.toDouble(),
            bearingDeg = location.bearing.takeIf { location.hasBearing() }?.toDouble(),
            capturedAtMs = location.time.takeIf { it > 0L } ?: now,
            batteryPct = batteryPercent(),
            provider = location.provider ?: "fused",
            isMock = LocationCompat.isMock(location),
        )
        buffer.add(sample)
        flushBuffer()
    }

    private fun flushBuffer() {
        if (uploading) return
        val pending = buffer.all()
        if (pending.isEmpty()) return
        uploading = true
        scope.launch {
            try {
                val app = application as EventMenuGoApplication
                val result = app.deliveryProgressRepository.sendLocationBatch(pending)
                buffer.dropFirst(pending.size)
                if (result.activeOrders <= 0) {
                    stopSelf()
                } else {
                    updateNotification(
                        if (result.activeOrders == 1) "GPS ao vivo · 1 entrega em rota"
                        else "GPS ao vivo · ${result.activeOrders} entregas em rota"
                    )
                }
            } catch (_: Throwable) {
                updateNotification("Sem internet · guardando o trajeto para sincronizar")
            } finally {
                uploading = false
                if (buffer.all().isNotEmpty()) flushBuffer()
            }
        }
    }

    private fun batteryPercent(): Int? {
        val battery = getSystemService(BATTERY_SERVICE) as? BatteryManager ?: return null
        val value = battery.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        return value.takeIf { it in 0..100 }
    }

    private fun hasPermission(): Boolean =
        ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < 26) return
        val manager = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(CHANNEL, "Entrega em andamento", NotificationManager.IMPORTANCE_LOW).apply {
            description = "Mantém o GPS ativo somente enquanto houver entrega em rota."
            setShowBadge(false)
        }
        manager.createNotificationChannel(channel)
    }

    private fun notification(text: String): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentTitle("EventMenu GO · entrega em andamento")
            .setContentText(text)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(pendingIntent)
            .build()
    }

    private fun updateNotification(text: String) {
        val manager = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        manager.notify(NOTIFICATION_ID, notification(text))
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        runCatching { fused.removeLocationUpdates(callback) }
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val CHANNEL = "delivery_tracking"
        private const val NOTIFICATION_ID = 2401
        private const val ACTION_STOP = "br.com.eventmenu.go.STOP_DELIVERY_GPS"
        private const val HEARTBEAT_MS = 20_000L
        private const val MIN_MOVEMENT_M = 8f
        private const val MAX_CAPTURE_ACCURACY_M = 500f

        fun hasLocationPermission(context: Context): Boolean =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

        fun start(context: Context) {
            if (!hasLocationPermission(context)) return
            ContextCompat.startForegroundService(context, Intent(context, DeliveryLocationService::class.java))
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, DeliveryLocationService::class.java))
        }
    }
}
