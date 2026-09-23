package br.com.eventmenu.connect

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class PackageUpdatedReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action == Intent.ACTION_MY_PACKAGE_REPLACED && SessionStore(context).hasSession()) {
            runCatching { startConnectService(context) }
        }
    }
}
