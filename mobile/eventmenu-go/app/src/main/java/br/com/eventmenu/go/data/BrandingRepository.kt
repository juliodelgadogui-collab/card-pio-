package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

data class AppBranding(
    val displayName: String = "EventMenu GO",
    val logoUrl: String = "",
    val primaryColor: String = "#5B34D6",
    val secondaryColor: String = "#159B63",
    val backgroundColor: String = "#F6F7FB",
    val surfaceColor: String = "#FFFFFF",
    val textColor: String = "#1E1B2B",
    val applyApp: Boolean = false,
)

class BrandingRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun current(): AppBranding {
        val root = api.getBranding(token())
        val b = root.optJSONObject("branding") ?: JSONObject()
        return AppBranding(
            displayName = clean(b.optString("display_name")).ifBlank { "EventMenu GO" },
            logoUrl = clean(b.optString("logo_url")),
            primaryColor = color(b.optString("primary_color"), "#5B34D6"),
            secondaryColor = color(b.optString("secondary_color"), "#159B63"),
            backgroundColor = color(b.optString("background_color"), "#F6F7FB"),
            surfaceColor = color(b.optString("surface_color"), "#FFFFFF"),
            textColor = color(b.optString("text_color"), "#1E1B2B"),
            applyApp = b.optBoolean("apply_app", true),
        )
    }

    private fun token() = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
    private fun clean(value: String) = value.takeUnless { it.equals("null", true) }?.trim().orEmpty()
    private fun color(value: String, fallback: String): String = if (Regex("^#[0-9A-Fa-f]{6}$").matches(value.trim())) value.uppercase() else fallback
}
