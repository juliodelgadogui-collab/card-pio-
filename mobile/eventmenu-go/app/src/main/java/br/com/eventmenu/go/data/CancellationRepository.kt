package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class CancellationRequest(
    val id: Int,
    val orderId: Int,
    val reason: String,
    val status: String,
    val requesterName: String = "",
    val deciderName: String = "",
    val createdAt: String = "",
    val channel: String = "",
    val orderStatus: String = "",
    val paymentStatus: String = "",
    val totalCents: Int = 0,
    val customerName: String = "",
)

class CancellationRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun pending(): List<CancellationRequest> {
        val rows = api.getCancellations("pending", token()).optJSONArray("requests") ?: JSONArray()
        return buildList { for (i in 0 until rows.length()) add(parse(rows.getJSONObject(i))) }
    }

    suspend fun forOrder(orderId: Int): CancellationRequest? {
        val root = api.getCancellations("order", token(), mapOf("order_id" to orderId.toString()))
        if (root.isNull("request")) return null
        return parse(root.getJSONObject("request"))
    }

    suspend fun request(orderId: Int, reason: String): CancellationRequest {
        val root = api.postCancellations("request", token(), JSONObject().put("order_id", orderId).put("reason", reason))
        return parse(root.getJSONObject("request"))
    }

    suspend fun approve(requestId: Int) = api.postCancellations("approve", token(), JSONObject().put("request_id", requestId))
    suspend fun reject(requestId: Int, reason: String) = api.postCancellations("reject", token(), JSONObject().put("request_id", requestId).put("reason", reason))

    private fun parse(j: JSONObject) = CancellationRequest(
        id = j.optInt("id", j.optInt("request_id")),
        orderId = j.optInt("order_id"),
        reason = j.optString("reason"),
        status = j.optString("status"),
        requesterName = j.optString("requester_name"),
        deciderName = j.optString("decider_name"),
        createdAt = j.optString("created_at"),
        channel = j.optString("channel"),
        orderStatus = j.optString("order_status"),
        paymentStatus = j.optString("payment_status"),
        totalCents = j.optInt("total_cents"),
        customerName = j.optString("customer_name"),
    )

    private fun token() = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
