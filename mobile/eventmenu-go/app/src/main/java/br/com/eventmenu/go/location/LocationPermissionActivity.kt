package br.com.eventmenu.go.location

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow

object DeliveryRoutePermissionCoordinator {
    private val _grantedOrders = MutableSharedFlow<Int>(extraBufferCapacity = 1)
    val grantedOrders = _grantedOrders.asSharedFlow()

    fun granted(orderId: Int) {
        if (orderId > 0) _grantedOrders.tryEmit(orderId)
    }
}

class LocationPermissionActivity : Activity() {
    private var orderId: Int = 0

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        orderId = intent.getIntExtra(EXTRA_ORDER_ID, 0)
        if (orderId <= 0) {
            finish()
            return
        }
        if (DeliveryLocationService.hasLocationPermission(this)) {
            DeliveryRoutePermissionCoordinator.granted(orderId)
            finish()
            return
        }
        ActivityCompat.requestPermissions(
            this,
            arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
            REQUEST_LOCATION,
        )
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray,
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == REQUEST_LOCATION && grantResults.any { it == PackageManager.PERMISSION_GRANTED }) {
            DeliveryRoutePermissionCoordinator.granted(orderId)
        }
        finish()
    }

    companion object {
        private const val REQUEST_LOCATION = 42
        private const val EXTRA_ORDER_ID = "order_id"

        fun request(context: Context, orderId: Int) {
            if (orderId <= 0) return
            if (DeliveryLocationService.hasLocationPermission(context)) {
                DeliveryRoutePermissionCoordinator.granted(orderId)
                return
            }
            ContextCompat.startActivity(
                context,
                Intent(context, LocationPermissionActivity::class.java)
                    .putExtra(EXTRA_ORDER_ID, orderId)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                null,
            )
        }
    }
}
