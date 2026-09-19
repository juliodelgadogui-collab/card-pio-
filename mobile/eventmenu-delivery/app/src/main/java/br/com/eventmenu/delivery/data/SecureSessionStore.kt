package br.com.eventmenu.delivery.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class SecureSessionStore(context: Context) {
    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "eventmenu_delivery_secure",
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var accessToken: String?
        get() = prefs.getString("access_token", null)
        set(value) {
            if (value.isNullOrBlank()) prefs.edit().remove("access_token").apply()
            else prefs.edit().putString("access_token", value).apply()
        }

    var pushToken: String?
        get() = prefs.getString("push_token", null)
        set(value) {
            if (value.isNullOrBlank()) prefs.edit().remove("push_token").apply()
            else prefs.edit().putString("push_token", value).apply()
        }

    fun clear() { prefs.edit().clear().apply() }
}
