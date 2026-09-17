package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

/** Consulta resumida usada pela Gestão. Falha de permissão ou rede não bloqueia o painel gerencial. */
class InventoryAlertRepository(
    baseUrl: String,
    deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    data class Summary(val controlled: Int, val low: Int, val zero: Int)

    suspend fun summary(): Summary {
        val root = api.getInventory(
            action = "snapshot",
            token = requireToken(),
            query = mapOf("low_only" to "1"),
        )
        val summary = root.optJSONObject("summary") ?: JSONObject()
        return Summary(
            controlled = summary.optInt("controlled"),
            low = summary.optInt("low"),
            zero = summary.optInt("zero"),
        )
    }

    private fun requireToken(): String = sessionStore.token()?.takeIf { it.isNotBlank() }
        ?: throw ApiException("Faça login novamente.", 401)
}
