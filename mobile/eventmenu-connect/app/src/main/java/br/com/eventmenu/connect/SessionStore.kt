package br.com.eventmenu.connect

import android.content.Context
import org.json.JSONObject
import java.security.SecureRandom
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

class SessionStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences("eventmenu_connect", Context.MODE_PRIVATE)

    fun deviceId(): String {
        val current = prefs.getString("device_id", "").orEmpty()
        if (current.length >= 8) return current
        val created = "android-connect-" + UUID.randomUUID().toString()
        prefs.edit().putString("device_id", created).apply()
        return created
    }

    fun engineSecret(): String {
        val current = prefs.getString("engine_secret", "").orEmpty()
        if (current.length >= 48) return current
        val bytes = ByteArray(32)
        SecureRandom().nextBytes(bytes)
        val created = bytes.joinToString("") { "%02x".format(it) }
        prefs.edit().putString("engine_secret", created).apply()
        return created
    }

    fun enginePort(): Int = 21567

    fun saveAuth(token: String, refresh: String, user: JSONObject) {
        prefs.edit()
            .putString("token", token)
            .putString("refresh_token", refresh)
            .putString("user_name", user.optString("name"))
            .putString("tenant_name", user.optString("tenant_name"))
            .apply()
    }

    fun updateTokens(token: String, refresh: String) {
        prefs.edit().putString("token", token).putString("refresh_token", refresh).apply()
    }

    fun token(): String = prefs.getString("token", "").orEmpty()
    fun refreshToken(): String = prefs.getString("refresh_token", "").orEmpty()
    fun tenantName(): String = prefs.getString("tenant_name", "").orEmpty()
    fun hasSession(): Boolean = token().length >= 32 && refreshToken().length >= 32

    fun savePairingCountry(regionCode: String) {
        prefs.edit().putString("pairing_country", regionCode.uppercase(Locale.US)).apply()
    }

    fun pairingCountryRegion(): String = prefs.getString("pairing_country", "BR").orEmpty().ifBlank { "BR" }

    fun clearRuntimeError() {
        prefs.edit().putString("runtime_error", "").apply()
    }

    fun clearAuth() {
        prefs.edit()
            .remove("token")
            .remove("refresh_token")
            .remove("user_name")
            .remove("tenant_name")
            .remove("runtime_status")
            .remove("runtime_pending")
            .remove("runtime_error")
            .remove("runtime_last_sync")
            .remove("runtime_phone")
            .remove("runtime_pairing_code")
            .remove("runtime_qr")
            .apply()
    }

    fun setRuntime(
        status: String,
        pending: Int = runtimePending(),
        error: String = "",
        phone: String = runtimePhone(),
        pairingCode: String = runtimePairingCode(),
        qr: String = runtimeQr(),
    ) {
        prefs.edit()
            .putString("runtime_status", status)
            .putInt("runtime_pending", pending)
            .putString("runtime_error", error)
            .putString("runtime_phone", phone)
            .putString("runtime_pairing_code", pairingCode)
            .putString("runtime_qr", qr)
            .putString("runtime_last_sync", SimpleDateFormat("HH:mm:ss", Locale("pt", "BR")).format(Date()))
            .apply()
    }

    fun runtimeStatus(): String = prefs.getString("runtime_status", "disconnected").orEmpty()
    fun runtimePending(): Int = prefs.getInt("runtime_pending", 0)
    fun runtimeError(): String = prefs.getString("runtime_error", "").orEmpty()
    fun runtimeLastSync(): String = prefs.getString("runtime_last_sync", "").orEmpty()
    fun runtimePhone(): String = prefs.getString("runtime_phone", "").orEmpty()
    fun runtimePairingCode(): String = prefs.getString("runtime_pairing_code", "").orEmpty()
    fun runtimeQr(): String = prefs.getString("runtime_qr", "").orEmpty()
}
