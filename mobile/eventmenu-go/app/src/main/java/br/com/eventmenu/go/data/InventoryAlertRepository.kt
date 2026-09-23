package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** Consulta resumida usada pela Gestão. Falha de permissão ou rede não bloqueia o painel gerencial. */
class InventoryAlertRepository(
    private val baseUrl: String,
    private val deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    data class Summary(val controlled: Int, val low: Int, val zero: Int)

    suspend fun summary(): Summary = withContext(Dispatchers.IO) {
        val token = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
        val connection = URL(baseUrl.trimEnd('/') + "/api-go-inventory.php?action=snapshot&low_only=1").openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "GET"
            connection.connectTimeout = 7_000
            connection.readTimeout = 10_000
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Authorization", "Bearer $token")
            connection.setRequestProperty("X-Device-Id", deviceId)
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val root = runCatching { JSONObject(text) }.getOrNull()
            if (status !in 200..299 || root?.optBoolean("ok") != true) throw ApiException("Estoque indisponível.", status)
            val summary = root.optJSONObject("summary") ?: JSONObject()
            Summary(summary.optInt("controlled"), summary.optInt("low"), summary.optInt("zero"))
        } finally { connection.disconnect() }
    }
}
