package br.com.eventmenu.go.data

import android.content.Context
import br.com.eventmenu.go.security.SecureSessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * Identidade visual da empresa usada pelo app. Valores inválidos recebidos do
 * servidor caem nos padrões EventMenu para nunca quebrar a interface.
 */
data class TenantBrand(
    val displayName: String = "EventMenu",
    val tagline: String = "",
    val logoUrl: String = "",
    val primaryColor: String = "#5b34d6",
    val secondaryColor: String = "#159b63",
    val backgroundColor: String = "#f6f7fb",
    val surfaceColor: String = "#ffffff",
    val textColor: String = "#1e1b2b",
    val applyApp: Boolean = true,
    val showEventMenuBrand: Boolean = true,
) {
    companion object {
        fun from(json: JSONObject?): TenantBrand {
            if (json == null) return TenantBrand()
            return TenantBrand(
                displayName = json.optString("display_name", "EventMenu").clean("EventMenu"),
                tagline = json.optString("tagline").clean(""),
                logoUrl = json.optString("logo_url").clean(""),
                primaryColor = json.optString("primary_color").validHex("#5b34d6"),
                secondaryColor = json.optString("secondary_color").validHex("#159b63"),
                backgroundColor = json.optString("background_color").validHex("#f6f7fb"),
                surfaceColor = json.optString("surface_color").validHex("#ffffff"),
                textColor = json.optString("text_color").validHex("#1e1b2b"),
                applyApp = json.optBoolean("apply_app", true),
                showEventMenuBrand = json.optBoolean("show_eventmenu_brand", true),
            )
        }

        private fun String.clean(fallback: String): String {
            val value = trim()
            return if (value.isBlank() || value.equals("null", true)) fallback else value
        }

        private fun String.validHex(fallback: String): String {
            val value = trim().lowercase()
            return if (Regex("^#[0-9a-f]{6}$").matches(value)) value else fallback
        }
    }
}

class TenantBrandRepository(
    context: Context,
    private val baseUrl: String,
    private val deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val prefs = context.getSharedPreferences("eventmenu_go_brand", Context.MODE_PRIVATE)

    fun cached(): TenantBrand? {
        val raw = prefs.getString("brand_json", null) ?: return null
        return runCatching { TenantBrand.from(JSONObject(raw)) }.getOrNull()
    }

    suspend fun load(): TenantBrand = withContext(Dispatchers.IO) {
        val token = sessionStore.token() ?: return@withContext cached() ?: TenantBrand()
        val connection = URL(baseUrl.trimEnd('/') + "/api-go-brand.php").openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "GET"
            connection.connectTimeout = 8_000
            connection.readTimeout = 12_000
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Authorization", "Bearer $token")
            connection.setRequestProperty("X-Device-Id", deviceId)
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val root = runCatching { JSONObject(body) }.getOrNull()
            if (status !in 200..299 || root?.optBoolean("ok") != true) return@withContext cached() ?: TenantBrand()
            val rawBrand = root.optJSONObject("brand") ?: JSONObject()
            prefs.edit().putString("brand_json", rawBrand.toString()).apply()
            TenantBrand.from(rawBrand)
        } finally {
            connection.disconnect()
        }
    }
}
