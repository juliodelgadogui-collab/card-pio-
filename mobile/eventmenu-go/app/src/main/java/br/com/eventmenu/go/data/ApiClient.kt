package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class ApiException(message: String, val status: Int = 0) : RuntimeException(message)

class ApiClient(
    private val baseUrl: String,
    private val deviceId: String,
    private val sessionStore: SecureSessionStore? = null,
) {
    suspend fun get(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api.php", "GET", action, token, query, null)
    suspend fun post(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api.php", "POST", action, token, emptyMap(), body)
    suspend fun getGo(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go.php", "GET", action, token, query, null)
    suspend fun postGo(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go.php", "POST", action, token, emptyMap(), body)
    suspend fun getOrderOps(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-orders.php", "GET", action, token, query, null)
    suspend fun postOrderOps(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-orders.php", "POST", action, token, emptyMap(), body)
    suspend fun getDelivery(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-delivery.php", "GET", action, token, query, null)
    suspend fun postDelivery(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-delivery.php", "POST", action, token, emptyMap(), body)
    suspend fun getDiscounts(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-discounts.php", "GET", action, token, query, null)
    suspend fun postDiscounts(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-discounts.php", "POST", action, token, emptyMap(), body)
    suspend fun getEvents(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-events.php", "GET", action, token, query, null)
    suspend fun postEvents(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-events.php", "POST", action, token, emptyMap(), body)
    suspend fun getManager(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-manager.php", "GET", action, token, query, null)
    suspend fun postManager(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-manager.php", "POST", action, token, emptyMap(), body)
    suspend fun getNotifications(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-notifications.php", "GET", action, token, query, null)
    suspend fun postNotifications(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-notifications.php", "POST", action, token, emptyMap(), body)
    suspend fun getReceipt(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-receipts.php", "GET", action, token, query, null)
    suspend fun getDevice(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-device.php", "GET", action, token, query, null)
    suspend fun getQr(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-qr.php", "GET", action, token, query, null)
    suspend fun postQr(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-qr.php", "POST", action, token, emptyMap(), body)
    suspend fun getTabPayments(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-tab-payments.php", "GET", action, token, query, null)
    suspend fun postTabPayments(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-tab-payments.php", "POST", action, token, emptyMap(), body)
    suspend fun getUnits(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-units.php", "GET", action, token, query, null)
    suspend fun postUnits(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-units.php", "POST", action, token, emptyMap(), body)

    private suspend fun request(path: String, method: String, action: String, token: String?, query: Map<String, String>, body: JSONObject?): JSONObject = withContext(Dispatchers.IO) {
        val store = sessionStore ?: sharedSessionStore
        try {
            execute(path, method, action, token, query, body).also { result ->
                if (path == "api.php" && action == "login" && result.has("refresh_token")) saveTokenPair(store, result)
            }
        } catch (error: ApiException) {
            if (token == null || action == "refresh" || !shouldRefresh(error) || store == null) throw@withContext error
            val refreshed = refreshAccessToken(store, token)
            execute(path, method, action, refreshed, query, body)
        }
    }

    private fun execute(path: String, method: String, action: String, token: String?, query: Map<String, String>, body: JSONObject?): JSONObject {
        val params = linkedMapOf("action" to action).apply { putAll(query) }
        val qs = params.entries.joinToString("&") { "${URLEncoder.encode(it.key, "UTF-8") }=${URLEncoder.encode(it.value, "UTF-8")}" }
        val connection = URL(baseUrl.trimEnd('/') + "/$path?$qs").openConnection() as HttpURLConnection
        try {
            connection.requestMethod = method
            connection.connectTimeout = 12_000
            connection.readTimeout = 25_000
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("X-Device-Id", deviceId)
            token?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
            if (body != null) {
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val json = runCatching { JSONObject(text) }.getOrElse { JSONObject().put("ok", false).put("error", "Resposta inválida do servidor.") }
            if (status !in 200..299 || !json.optBoolean("ok", false)) throw ApiException(json.optString("error", "Falha na API."), status)
            return json
        } finally { connection.disconnect() }
    }

    private fun shouldRefresh(error: ApiException): Boolean {
        val message = error.message.orEmpty()
        return error.status == 401 || (error.status == 422 && (message.contains("Sessão do app expirada", ignoreCase = true) || message.contains("Token inválido", ignoreCase = true)))
    }

    private suspend fun refreshAccessToken(store: SecureSessionStore, failedToken: String): String = refreshMutex.withLock {
        val current = store.token()
        if (!current.isNullOrBlank() && current != failedToken) return@withLock current
        val refresh = store.refreshToken() ?: throw ApiException("Faça login novamente.", 401)
        try {
            val root = execute("api.php", "POST", "refresh", null, emptyMap(), JSONObject().put("refresh_token", refresh).put("device_id", deviceId))
            saveTokenPair(store, root)
            root.getString("token")
        } catch (error: Throwable) {
            store.clearSessionTokens()
            throw error
        }
    }

    private fun saveTokenPair(store: SecureSessionStore?, root: JSONObject) {
        if (store == null || !root.has("token") || !root.has("refresh_token")) return
        store.saveSessionTokens(root.getString("token"), root.getString("refresh_token"), root.optString("expires_at"), root.optString("refresh_expires_at"))
    }

    companion object {
        private val refreshMutex = Mutex()
        @Volatile private var sharedSessionStore: SecureSessionStore? = null
        fun configureSessionStore(store: SecureSessionStore) { sharedSessionStore = store }
    }
}
