package br.com.eventmenu.delivery.data

import br.com.eventmenu.delivery.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class PushApi(private val tokenProvider: () -> String?) {
    suspend fun register(pushToken: String) = withContext(Dispatchers.IO) {
        val session = tokenProvider().orEmpty()
        if (session.isBlank() || pushToken.isBlank()) return@withContext
        val connection = (URL(BuildConfig.API_BASE_URL + "api-delivery-customer.php?action=push-register").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 10_000
            readTimeout = 20_000
            useCaches = false
            doOutput = true
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Authorization", "Bearer $session")
        }
        try {
            val payload = JSONObject()
                .put("push_token", pushToken)
                .put("platform", "android")
                .put("device_id_hash", android.os.Build.FINGERPRINT.hashCode().toString())
            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            if (status !in 200..299) throw ApiException("Não foi possível registrar as notificações.", "PUSH_REGISTER_FAILED")
        } finally { connection.disconnect() }
    }
}
