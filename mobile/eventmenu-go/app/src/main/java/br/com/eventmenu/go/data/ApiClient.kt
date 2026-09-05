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

    suspend fun getEvents(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-events.php", "GET", action, token, query, null)

    suspend fun postEvents(action: String, token: String? = null, body: JSONObject = JSONObject()): JSONObject =
        request("api-go-events.php", "POST", action, token, emptyMap(), body)

    suspend fun getManager(action: String, token: String? = null, query: Map<String, String> = emptyMap()): JSONObject =
        request("api-go-manager.php", "GET", action, token, query, null)

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
        } finally {
            connection.disconnect()
        }
    }
}
