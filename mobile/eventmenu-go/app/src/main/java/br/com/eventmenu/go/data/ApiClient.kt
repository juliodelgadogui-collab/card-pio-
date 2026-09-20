package br.com.eventmenu.go.data

import br.com.eventmenu.go.OperationalText
import br.com.eventmenu.go.security.SecureSessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class ApiException(message: String, val status: Int = 0) : RuntimeException(message)

enum class ApiConnectivity { UNKNOWN, ONLINE, OFFLINE }

object ApiConnectionMonitor {
    private val _state = MutableStateFlow(ApiConnectivity.UNKNOWN)
    val state: StateFlow<ApiConnectivity> = _state.asStateFlow()

    internal fun online() { _state.value = ApiConnectivity.ONLINE }
    internal fun offline() { _state.value = ApiConnectivity.OFFLINE }
}

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
    suspend fun getCancellations(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-cancellations.php", "GET", action, token, query, null)
    suspend fun postCancellations(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-cancellations.php", "POST", action, token, emptyMap(), body)
    suspend fun getEvents(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-events.php", "GET", action, token, query, null)
    suspend fun postEvents(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-events.php", "POST", action, token, emptyMap(), body)
    suspend fun getManager(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-manager.php", "GET", action, token, query, null)
    suspend fun postManager(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-manager.php", "POST", action, token, emptyMap(), body)
    suspend fun getNotifications(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-notifications.php", "GET", action, token, query, null)
    suspend fun postNotifications(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-notifications.php", "POST", action, token, emptyMap(), body)
    suspend fun getReceipt(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-receipts.php", "GET", action, token, query, null)
    suspend fun getDevice(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-device.php", "GET", action, token, query, null)
    suspend fun postDevice(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-device.php", "POST", action, token, emptyMap(), body)
    suspend fun getQr(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-qr.php", "GET", action, token, query, null)
    suspend fun postQr(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-qr.php", "POST", action, token, emptyMap(), body)
    suspend fun getTabPayments(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-tab-payments.php", "GET", action, token, query, null)
    suspend fun postTabPayments(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-tab-payments.php", "POST", action, token, emptyMap(), body)
    suspend fun getUnits(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-go-units.php", "GET", action, token, query, null)
    suspend fun postUnits(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-go-units.php", "POST", action, token, emptyMap(), body)
    suspend fun getHub(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject = request("api-hub.php", "GET", action, token, query, null)
    suspend fun postHub(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject = request("api-hub.php", "POST", action, token, emptyMap(), body)

    private suspend fun request(
        path: String,
        method: String,
        action: String,
        token: String?,
        query: Map<String, String>,
        body: JSONObject?,
    ): JSONObject = kotlinx.coroutines.withContext(Dispatchers.IO) {
        val store = sessionStore ?: sharedSessionStore
        val requestKey = cacheRequestKey(path, action, query)
        val cacheable = isCacheableRead(path, method, action, token)

        try {
            val result = try {
                execute(path, method, action, token, query, body)
            } catch (error: ApiException) {
                if (token == null || action == "refresh" || !shouldRefresh(error) || store == null) throw error
                val refreshed = refreshAccessToken(store, token)
                execute(path, method, action, refreshed, query, body)
            }

            if (path == "api.php" && action == "login" && result.has("refresh_token")) saveTokenPair(store, result)
            if (cacheable) {
                val scope = cacheScope(store, token)
                if (scope.isNotBlank()) sharedOfflineCache?.save(scope, requestKey, result)
            }
            result
        } catch (error: IOException) {
            ApiConnectionMonitor.offline()
            if (cacheable) {
                val scope = cacheScope(store, token)
                sharedOfflineCache?.read(scope, requestKey)?.let { cached -> return@withContext cached }
            }
            val message = if (method == "GET") {
                "Sem conexão com o servidor. Reconecte para atualizar esta tela."
            } else {
                "Sem conexão com o servidor. Reconecte para concluir esta ação."
            }
            throw ApiException(message, 0)
        }
    }

    private fun execute(
        path: String,
        method: String,
        action: String,
        token: String?,
        query: Map<String, String>,
        body: JSONObject?,
    ): JSONObject {
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
            ApiConnectionMonitor.online()
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val json = runCatching { JSONObject(text) }
                .getOrElse { JSONObject().put("ok", false).put("error", "Não foi possível interpretar a resposta do servidor.") }
            if (status !in 200..299 || !json.optBoolean("ok", false)) {
                val serverMessage = json.optString("message").takeIf { it.isNotBlank() }
                    ?: json.optString("error", "Não foi possível concluir esta operação.")
                throw ApiException(OperationalText.friendlyApiMessage(serverMessage, status), status)
            }
            return json
        } finally {
            connection.disconnect()
        }
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
            val root = execute(
                "api.php",
                "POST",
                "refresh",
                null,
                emptyMap(),
                JSONObject().put("refresh_token", refresh).put("device_id", deviceId),
            )
            saveTokenPair(store, root)
            root.getString("token")
        } catch (error: ApiException) {
            if (error.status == 401 || error.status == 403 || error.status == 422) store.clearSessionTokens()
            throw error
        }
    }

    private fun saveTokenPair(store: SecureSessionStore?, root: JSONObject) {
        if (store == null || !root.has("token") || !root.has("refresh_token")) return
        store.saveSessionTokens(
            root.getString("token"),
            root.getString("refresh_token"),
            root.optString("expires_at"),
            root.optString("refresh_expires_at"),
        )
    }

    private fun cacheScope(store: SecureSessionStore?, fallbackToken: String?): String =
        store?.token()?.takeIf { it.isNotBlank() } ?: fallbackToken.orEmpty()

    private fun cacheRequestKey(path: String, action: String, query: Map<String, String>): String = buildString {
        append(path).append('|').append(action)
        query.toSortedMap().forEach { (key, value) -> append('|').append(key).append('=').append(value) }
    }

    private fun isCacheableRead(path: String, method: String, action: String, token: String?): Boolean {
        if (method != "GET" || token.isNullOrBlank()) return false
        if (path == "api-go-events.php" && action == "bar-order-resolve") return false
        if (path == "api-hub.php") return false
        return path in CACHEABLE_PATHS
    }

    companion object {
        private val refreshMutex = Mutex()
        @Volatile private var sharedSessionStore: SecureSessionStore? = null
        @Volatile private var sharedOfflineCache: OfflineReadCache? = null

        private val CACHEABLE_PATHS = setOf(
            "api-go.php",
            "api-go-orders.php",
            "api-go-delivery.php",
            "api-go-manager.php",
            "api-go-notifications.php",
            "api-go-events.php",
            "api-go-units.php",
        )

        fun configureSessionStore(store: SecureSessionStore) { sharedSessionStore = store }
        fun configureOfflineCache(cache: OfflineReadCache) { sharedOfflineCache = cache }
        fun clearOfflineCache() { sharedOfflineCache?.clear() }
    }
}
