package br.com.eventmenu.go.security

import android.content.Context
import android.provider.Settings
import java.security.MessageDigest

object DeviceIdentity {
    fun id(context: Context): String {
        val androidId = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID).orEmpty()
        val raw = "${context.packageName}|$androidId"
        return MessageDigest.getInstance("SHA-256")
            .digest(raw.toByteArray())
            .joinToString("") { "%02x".format(it) }
    }
}
