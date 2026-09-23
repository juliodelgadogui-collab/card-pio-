package br.com.eventmenu.connect

import android.content.Context
import org.json.JSONObject
import java.security.SecureRandom
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

data class PendingOutboundAck(
    val id: Int,
    val claimToken: String,
    val externalMessageId: String,
    val savedAt: Long,
)

class SessionStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences("eventmenu_connect", Context.MODE_PRIVATE)
    private val secure = SecureValueStore(context)

    fun deviceId(): String {
        val current = prefs.getString("device_id", "").orEmpty()
        if (current.length >= 8) return current
        val created = "android-connect-" + UUID.randomUUID().toString()
        prefs.edit().putString("device_id", created).apply()
        return created
    }

    fun engineSecret(): String {
        val current = secure.get("engine_secret")
        if (current.length >= 48) return current
        val bytes = ByteArray(32)
        SecureRandom().nextBytes(bytes)
        val created = bytes.joinToString("") { "%02x".format(it) }
        check(secure.put("engine_secret", created)) { "Não foi possível proteger o segredo do motor local." }
        return created
    }

    fun enginePort(): Int = 21567

    fun saveAuth(token: String, refresh: String, user: JSONObject) {
        check(secure.put("token", token) && secure.put("refresh_token", refresh)) {
            "Não foi possível proteger a sessão EventMenu."
        }
        prefs.edit()
            .putInt("tenant_id", user.optInt("tenant_id", 0).coerceAtLeast(0))
            .putString("user_name", user.optString("name"))
            .putString("tenant_name", user.optString("tenant_name"))
            .apply()
    }

    fun updateTokens(token: String, refresh: String, user: JSONObject? = null) {
        check(secure.put("token", token) && secure.put("refresh_token", refresh)) {
            "Não foi possível atualizar a sessão EventMenu protegida."
        }
        if (user != null) {
            val editor = prefs.edit()
            val tenantId = user.optInt("tenant_id", tenantId()).coerceAtLeast(0)
            editor.putInt("tenant_id", tenantId)
            if (user.has("name")) editor.putString("user_name", user.optString("name"))
            if (user.has("tenant_name")) editor.putString("tenant_name", user.optString("tenant_name"))
            editor.apply()
        }
    }

    fun token(): String = secure.get("token")
    fun refreshToken(): String = secure.get("refresh_token")
    fun tenantId(): Int = prefs.getInt("tenant_id", 0).coerceAtLeast(0)
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
        secure.remove("token")
        secure.remove("refresh_token")
        secure.remove("runtime_pairing_code")
        secure.remove("runtime_qr")
        secure.remove("pending_outbound_acks")
        prefs.edit()
            .remove("tenant_id")
            .remove("user_name")
            .remove("tenant_name")
            .remove("runtime_status")
            .remove("runtime_pending")
            .remove("runtime_error")
            .remove("runtime_last_sync")
            .remove("runtime_phone")
            .apply()
    }

    @Synchronized
    fun savePendingOutboundAck(id: Int, claimToken: String, externalMessageId: String): Boolean {
        if (id < 1 || claimToken.isBlank()) return false
        val root = pendingAckJson()
        root.put(
            id.toString(),
            JSONObject()
                .put("id", id)
                .put("claim_token", claimToken.take(128))
                .put("external_message_id", externalMessageId.take(190))
                .put("saved_at", System.currentTimeMillis())
        )
        prunePendingAcks(root)
        return secure.put("pending_outbound_acks", root.toString())
    }

    @Synchronized
    fun pendingOutboundAcks(): List<PendingOutboundAck> {
        val root = pendingAckJson()
        val rows = mutableListOf<PendingOutboundAck>()
        val keys = root.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            val row = root.optJSONObject(key) ?: continue
            val id = row.optInt("id", key.toIntOrNull() ?: 0)
            val token = row.optString("claim_token").trim()
            if (id < 1 || token.isBlank()) continue
            rows += PendingOutboundAck(
                id = id,
                claimToken = token,
                externalMessageId = row.optString("external_message_id").take(190),
                savedAt = row.optLong("saved_at", 0L),
            )
        }
        return rows.sortedBy { it.savedAt }
    }

    @Synchronized
    fun removePendingOutboundAck(id: Int) {
        if (id < 1) return
        val root = pendingAckJson()
        root.remove(id.toString())
        secure.put("pending_outbound_acks", root.toString())
    }

    private fun pendingAckJson(): JSONObject {
        val raw = secure.get("pending_outbound_acks")
        return runCatching { if (raw.isBlank()) JSONObject() else JSONObject(raw) }.getOrElse { JSONObject() }
    }

    private fun prunePendingAcks(root: JSONObject) {
        if (root.length() <= 100) return
        val rows = mutableListOf<Pair<String, Long>>()
        val keys = root.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            rows += key to (root.optJSONObject(key)?.optLong("saved_at", 0L) ?: 0L)
        }
        rows.sortedBy { it.second }.take((root.length() - 100).coerceAtLeast(0)).forEach { root.remove(it.first) }
    }

    fun setRuntime(
        status: String,
        pending: Int = runtimePending(),
        error: String = "",
        phone: String = runtimePhone(),
        pairingCode: String = runtimePairingCode(),
        qr: String = runtimeQr(),
    ) {
        secure.put("runtime_pairing_code", pairingCode)
        secure.put("runtime_qr", qr)
        prefs.edit()
            .putString("runtime_status", status)
            .putInt("runtime_pending", pending)
            .putString("runtime_error", error)
            .putString("runtime_phone", phone)
            .putString("runtime_last_sync", SimpleDateFormat("HH:mm:ss", Locale("pt", "BR")).format(Date()))
            .apply()
    }

    fun runtimeStatus(): String = prefs.getString("runtime_status", "disconnected").orEmpty()
    fun runtimePending(): Int = prefs.getInt("runtime_pending", 0)
    fun runtimeError(): String = prefs.getString("runtime_error", "").orEmpty()
    fun runtimeLastSync(): String = prefs.getString("runtime_last_sync", "").orEmpty()
    fun runtimePhone(): String = prefs.getString("runtime_phone", "").orEmpty()
    fun runtimePairingCode(): String = secure.get("runtime_pairing_code")
    fun runtimeQr(): String = secure.get("runtime_qr")
}
