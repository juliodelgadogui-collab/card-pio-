package br.com.eventmenu.connect

import android.content.Context
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class ApiException(val status: Int, message: String) : Exception(message)

class EventMenuApi(context: Context) {
    private val store = SessionStore(context)
    private val base = BuildConfig.API_BASE_URL

    fun login(email: String, password: String) {
        val body = JSONObject()
            .put("email", email)
            .put("password", password)
            .put("device_id", store.deviceId())
            .put("device_label", deviceLabel())
        val result = request("api.php?action=login", "POST", body, auth = false)
        val token = result.optString("token")
        val refresh = result.optString("refresh_token")
        if (token.length < 32 || refresh.length < 32) throw Exception("O servidor não retornou uma sessão válida.")
        store.saveAuth(token, refresh, result.optJSONObject("user") ?: JSONObject())
    }

    fun logout() {
        runCatching { request("api.php?action=logout", "POST", JSONObject(), auth = true) }
    }

    fun heartbeat(status: String, phone: String = "", error: String = ""): JSONObject {
        val safeStatus = when (status.lowercase()) {
            "connected", "starting", "qr", "reconnecting", "error", "disconnected" -> status.lowercase()
            "pairing" -> "qr"
            else -> "disconnected"
        }
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("device_label", deviceLabel())
            .put("status", safeStatus)
            .put("phone", phone)
            .put("error", error.take(450))
        return agentRequest("heartbeat", "POST", body)
    }

    fun inbound(message: JSONObject): JSONObject {
        val body = JSONObject(message.toString()).put("device_id", store.deviceId())
        return agentRequest("inbound", "POST", body)
    }

    fun claim(limit: Int = 1): JSONArray {
        val body = JSONObject().put("device_id", store.deviceId()).put("limit", limit.coerceIn(1, 5))
        return agentRequest("claim", "POST", body).optJSONArray("messages") ?: JSONArray()
    }

    fun ack(id: Int, claimToken: String, externalMessageId: String) {
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("id", id)
            .put("claim_token", claimToken)
            .put("external_message_id", externalMessageId.take(190))
        agentRequest("ack", "POST", body)
    }

    fun fail(id: Int, claimToken: String, error: String) {
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("id", id)
            .put("claim_token", claimToken)
            .put("error", error.take(450))
        agentRequest("fail", "POST", body)
    }

    private fun agentRequest(action: String, method: String, body: JSONObject): JSONObject {
        return try {
            request("api-whatsapp-desktop.php?action=$action", method, body, auth = true)
        } catch (e: ApiException) {
            if (e.status == 401 && refresh()) request("api-whatsapp-desktop.php?action=$action", method, body, auth = true) else throw e
        }
    }

    private fun refresh(): Boolean {
        val refresh = store.refreshToken()
        if (refresh.length < 32) return false
        return try {
            val body = JSONObject().put("refresh_token", refresh).put("device_id", store.deviceId())
            val result = request("api.php?action=refresh", "POST", body, auth = false)
            val token = result.optString("token")
            val nextRefresh = result.optString("refresh_token")
            if (token.length < 32 || nextRefresh.length < 32) false
            else {
                store.updateTokens(token, nextRefresh, result.optJSONObject("user"))
                true
            }
        } catch (_: Exception) {
            false
        }
    }

    private fun request(path: String, method: String, body: JSONObject?, auth: Boolean): JSONObject {
        val connection = (URL(base + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 10_000
            readTimeout = 18_000
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("X-Device-Id", store.deviceId())
            setRequestProperty("User-Agent", "EventMenu-Connect-Android/${BuildConfig.VERSION_NAME}")
            if (auth) setRequestProperty("Authorization", "Bearer ${store.token()}")
            if (body != null && method != "GET") doOutput = true
        }
        if (body != null && method != "GET") connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        val code = connection.responseCode
        val raw = runCatching {
            (if (code in 200..299) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() }
        }.getOrNull().orEmpty()
        connection.disconnect()
        val json = runCatching { if (raw.isBlank()) JSONObject() else JSONObject(raw) }.getOrElse { JSONObject() }
        if (code !in 200..299) throw ApiException(code, json.optString("error").ifBlank { "Erro HTTP $code" })
        if (json.has("ok") && !json.optBoolean("ok", false)) throw ApiException(code, json.optString("error").ifBlank { "Falha no servidor." })
        return json
    }

    private fun deviceLabel(): String = "EventMenu Connect Android - ${Build.MANUFACTURER} ${Build.MODEL}"
}
