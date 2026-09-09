package br.com.eventmenu.go.location

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
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
import br.com.eventmenu.go.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class DeliveryLocationService : Service(), LocationListener {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var manager: LocationManager
    private var lastSentAt = 0L

    override fun onCreate() {
        super.onCreate(); manager=getSystemService(LOCATION_SERVICE) as LocationManager
        createChannel(); startForeground(NOTIFICATION_ID, notification())
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if(intent?.action==ACTION_STOP){stopSelf();return START_NOT_STICKY}
        if(!hasPermission()){stopSelf();return START_NOT_STICKY}
        try {
            if(manager.isProviderEnabled(LocationManager.GPS_PROVIDER)) manager.requestLocationUpdates(LocationManager.GPS_PROVIDER,10_000L,8f,this)
            if(manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) manager.requestLocationUpdates(LocationManager.NETWORK_PROVIDER,15_000L,15f,this)
        } catch(_:SecurityException){stopSelf()}
        return START_STICKY
    }

    override fun onLocationChanged(location: Location) {
        val now=System.currentTimeMillis();if(now-lastSentAt<10_000L)return;lastSentAt=now
        val app=application as EventMenuGoApplication
        scope.launch { runCatching { app.deliveryProgressRepository.sendLocationForActiveRoutes(location.latitude,location.longitude,location.accuracy.toDouble(),if(location.hasSpeed())location.speed.toDouble() else null,if(location.hasBearing())location.bearing.toDouble() else null,location.time) } }
    }
    @Deprecated("legacy") override fun onStatusChanged(provider:String?,status:Int,extras:Bundle?){}
    override fun onProviderEnabled(provider:String){}
    override fun onProviderDisabled(provider:String){}
    override fun onBind(intent:Intent?):IBinder?=null
    override fun onDestroy(){runCatching{manager.removeUpdates(this)};super.onDestroy()}

    private fun hasPermission()=ActivityCompat.checkSelfPermission(this,Manifest.permission.ACCESS_FINE_LOCATION)==PackageManager.PERMISSION_GRANTED||ActivityCompat.checkSelfPermission(this,Manifest.permission.ACCESS_COARSE_LOCATION)==PackageManager.PERMISSION_GRANTED
    private fun createChannel(){if(Build.VERSION.SDK_INT>=26)(getSystemService(NOTIFICATION_SERVICE) as NotificationManager).createNotificationChannel(NotificationChannel(CHANNEL,"Entrega em andamento",NotificationManager.IMPORTANCE_LOW))}
    private fun notification():android.app.Notification{val pi=PendingIntent.getActivity(this,0,Intent(this,MainActivity::class.java),PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT);return NotificationCompat.Builder(this,CHANNEL).setSmallIcon(android.R.drawable.ic_menu_mylocation).setContentTitle("EventMenu GO · entrega em rota").setContentText("Localização ativa para acompanhamento da entrega.").setOngoing(true).setContentIntent(pi).build()}

    companion object {
        private const val CHANNEL="delivery_tracking";private const val NOTIFICATION_ID=2401;private const val ACTION_STOP="br.com.eventmenu.go.STOP_DELIVERY_GPS"
        fun start(context:Context){if(ContextCompat.checkSelfPermission(context,Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED&&ContextCompat.checkSelfPermission(context,Manifest.permission.ACCESS_COARSE_LOCATION)!=PackageManager.PERMISSION_GRANTED)return;val i=Intent(context,DeliveryLocationService::class.java);ContextCompat.startForegroundService(context,i)}
        fun stop(context:Context){context.stopService(Intent(context,DeliveryLocationService::class.java))}
    }
}