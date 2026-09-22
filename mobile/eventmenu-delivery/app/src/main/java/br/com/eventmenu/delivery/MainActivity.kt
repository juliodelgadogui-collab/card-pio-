package br.com.eventmenu.delivery

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import br.com.eventmenu.delivery.ui.DelyvreModule3Root

class MainActivity : ComponentActivity() {
    private val viewModel: DeliveryViewModel by viewModels()
    override fun onCreate(savedInstanceState: Bundle?) { super.onCreate(savedInstanceState);enableEdgeToEdge();handleIntent(intent);requestNotificationPermissionWhenNeeded();setContent{DelyvreModule3Root(viewModel)} }
    override fun onNewIntent(intent:Intent){super.onNewIntent(intent);setIntent(intent);handleIntent(intent)}
    private fun handleIntent(intent:Intent?){intent?.data?.let{uri->when(uri.host){"email-confirmed"->viewModel.emailConfirmed();"login"->viewModel.navigate(Screen.Login)}};intent?.getIntExtra("order_id",0)?.takeIf{it>0}?.let(viewModel::openOrder)}
    private fun requestNotificationPermissionWhenNeeded(){if(Build.VERSION.SDK_INT<33||!BuildConfig.FIREBASE_ENABLED)return;if(ContextCompat.checkSelfPermission(this,Manifest.permission.POST_NOTIFICATIONS)==PackageManager.PERMISSION_GRANTED)return;ActivityCompat.requestPermissions(this,arrayOf(Manifest.permission.POST_NOTIFICATIONS),7001)}
}
