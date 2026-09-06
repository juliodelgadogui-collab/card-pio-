package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class DiscountRequest(
    val id: Int,
    val orderId: Int,
    val baseDiscountCents: Int,
    val requestedCents: Int,
    val reason: String,
    val status: String,
    val requesterName: String = "",
    val approverName: String = "",
    val createdAt: String = "",
    val subtotalCents: Int = 0,
    val currentDiscountCents: Int = 0,
    val totalCents: Int = 0,
    val paymentStatus: String = "",
)

class DiscountRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun pending(): List<DiscountRequest> {
        val a = api.getDiscounts("pending", token()).optJSONArray("requests") ?: JSONArray()
        return buildList { for (i in 0 until a.length()) add(parse(a.getJSONObject(i))) }
    }

    suspend fun forOrder(orderId: Int): DiscountRequest? {
        val root = api.getDiscounts("order", token(), mapOf("order_id" to orderId.toString()))
        if (root.isNull("request")) return null
        return parse(root.getJSONObject("request"))
    }

    suspend fun request(orderId: Int, amountCents: Int, reason: String): DiscountRequest {
        val root = api.postDiscounts(
            "request",
            token(),
            JSONObject().put("order_id", orderId).put("amount_cents", amountCents).put("reason", reason),
        )
        return parse(root.getJSONObject("request"))
    }

    suspend fun approve(requestId: Int) = api.postDiscounts("approve", token(), JSONObject().put("request_id", requestId))
    suspend fun reject(requestId: Int, reason: String) = api.postDiscounts("reject", token(), JSONObject().put("request_id", requestId).put("reason", reason))

    private fun parse(j: JSONObject) = DiscountRequest(
        id = j.optInt("id", j.optInt("request_id")),
        orderId = j.optInt("order_id"),
        baseDiscountCents = j.optInt("base_discount_cents"),
        requestedCents = j.optInt("requested_cents", j.optInt("approved_cents")),
        reason = j.optString("reason"),
        status = j.optString("status"),
        requesterName = j.optString("requester_name"),
        approverName = j.optString("approver_name"),
        createdAt = j.optString("created_at"),
        subtotalCents = j.optInt("subtotal_cents"),
        currentDiscountCents = j.optInt("discount_cents"),
        totalCents = j.optInt("total_cents"),
        paymentStatus = j.optString("payment_status"),
    )

    private fun token() = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
