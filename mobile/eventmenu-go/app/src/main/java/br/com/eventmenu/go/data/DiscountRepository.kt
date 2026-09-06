package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class DiscountPolicy(
    val cashierAutoBps: Int = 500,
    val managerAutoBps: Int = 2000,
    val adminAutoBps: Int = 10000,
    val requireReason: Boolean = true,
    val allowPercentage: Boolean = true,
)

data class DiscountRequest(
    val id: Int,
    val orderId: Int,
    val baseDiscountCents: Int,
    val requestedCents: Int,
    val reason: String,
    val status: String,
    val discountType: String = "fixed",
    val requestedBps: Int = 0,
    val appliedCents: Int = 0,
    val autoApproved: Boolean = false,
    val requesterName: String = "",
    val approverName: String = "",
    val createdAt: String = "",
    val subtotalCents: Int = 0,
    val currentDiscountCents: Int = 0,
    val totalCents: Int = 0,
    val paymentStatus: String = "",
)

class DiscountRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun pending(): List<DiscountRequest> {
        val a = api.getDiscounts("pending", token()).optJSONArray("requests") ?: JSONArray()
        return buildList { for (i in 0 until a.length()) add(parse(a.getJSONObject(i))) }
    }

    suspend fun forOrder(orderId: Int): DiscountRequest? {
        val root = api.getDiscounts("order", token(), mapOf("order_id" to orderId.toString()))
        if (root.isNull("request")) return null
        return parse(root.getJSONObject("request"))
    }

    suspend fun policy(): DiscountPolicy {
        val p = api.getDiscounts("policy", token()).optJSONObject("policy") ?: JSONObject()
        return DiscountPolicy(
            cashierAutoBps = p.optInt("cashier_auto_bps", 500),
            managerAutoBps = p.optInt("manager_auto_bps", 2000),
            adminAutoBps = p.optInt("admin_auto_bps", 10000),
            requireReason = p.optInt("require_reason", 1) == 1,
            allowPercentage = p.optInt("allow_percentage", 1) == 1,
        )
    }

    suspend fun request(orderId: Int, discountType: String, value: Int, reason: String): DiscountRequest {
        val body = JSONObject()
            .put("order_id", orderId)
            .put("discount_type", discountType)
            .put("reason", reason)
        if (discountType == "percent") body.put("value_bps", value) else body.put("amount_cents", value)
        val root = api.postDiscounts("request", token(), body)
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
        discountType = j.optString("discount_type", "fixed"),
        requestedBps = j.optInt("requested_bps"),
        appliedCents = j.optInt("applied_cents", j.optInt("approved_cents")),
        autoApproved = j.optInt("auto_approved", 0) == 1,
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
