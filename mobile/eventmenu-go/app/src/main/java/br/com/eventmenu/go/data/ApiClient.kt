package br.com.eventmenu.go.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class ApiException(message: String, val status: Int = 0) : RuntimeException(message)

class ApiClient(private val baseUrl: String, private val deviceId: String) {
    suspend fun get(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api.php", "GET", action, token, query, null)
    suspend fun post(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api.php", "POST", action, token, emptyMap(), body)
    suspend fun getGo(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go.php", "GET", action, token, query, null)
    suspend fun postGo(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go.php", "POST", action, token, emptyMap(), body)
    suspend fun getOrderOps(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-orders.php", "GET", action, token, query, null)
    suspend fun postOrderOps(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-orders.php", "POST", action, token, emptyMap(), body)
    suspend fun getEvents(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-events.php", "GET", action, token, query, null)
    suspend fun postEvents(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-events.php", "POST", action, token, emptyMap(), body)
    suspend fun getManager(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-manager.php", "GET", action, token, query, null)
    suspend fun postManager(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-manager.php", "POST", action, token, emptyMap(), body)
    suspend fun getNotifications(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-notifications.php", "GET", action, token, query, null)
    suspend fun postNotifications(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-notifications.php", "POST", action, token, emptyMap(), body)
    suspend fun getReceipt(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-receipts.php", "GET", action, token, query, null)
    suspend fun getDevice(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-device.php", "GET", action, token, query, null)
    suspend fun getQr(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-qr.php", "GET", action, token, query, null)
    suspend fun postQr(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-qr.php", "POST", action, token, emptyMap(), body)
    suspend fun getTabPayments(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-tab-payments.php", "GET", action, token, query, null)
    suspend fun postTabPayments(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-tab-payments.php", "POST", action, token, emptyMap(), body)
    suspend fun getUnits(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-units.php", "GET", action, token, query, null)
    suspend fun postUnits(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-units.php", "POST", action, token, emptyMap(), body)

    private suspend fun request(path: String, method: String, action: String, token: String?, query: Map<String, String>, body: JSONObject?): JSONObject = withContext(Dispatchers.IO) {
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
            json
        } finally { connection.disconnect() }
    }
}
