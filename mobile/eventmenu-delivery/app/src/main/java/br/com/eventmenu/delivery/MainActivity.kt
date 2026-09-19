package br.com.eventmenu.delivery

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import br.com.eventmenu.delivery.ui.EventMenuDeliveryApp

class MainActivity : ComponentActivity() {
    private val viewModel: DeliveryViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        handleDeepLink(intent)
        setContent { EventMenuDeliveryApp(viewModel) }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleDeepLink(intent)
    }

    private fun handleDeepLink(intent: Intent?) {
        val uri = intent?.data ?: return
        when (uri.host) {
            "email-confirmed" -> viewModel.emailConfirmed()
            "login" -> viewModel.navigate(Screen.Login)
        }
    }
}
