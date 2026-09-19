package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class OrderDetailItem(
    val id: Int,
    val name: String,
    val quantity: Double,
    val unitPriceCents: Int,
    val totalCents: Int,
    val notes: String,
)

data class OrderTimelineEntry(
    val id: Int,
    val fromStatus: String,
    val toStatus: String,
    val source: String,
    val notes: String,
    val createdAt: String,
    val userName: String,
)

data class LoyaltyOrderReservation(
    val points: Int,
    val discountCents: Int,
    val status: String,
)

data class LoyaltyOrderSummary(
    val enabled: Boolean,
    val customerId: Int,
    val balance: Int,
    val reserved: Int,
    val available: Int,
    val redeemPoints: Int,
    val redeemValueCents: Int,
    val minRedeemPoints: Int,
    val maxRedeemPercent: Int,
    val orderReservation: LoyaltyOrderReservation?,
)

data class OrderOperationalDetail(
    val orderId: Int,
    val channel: String,
    val status: String,
    val paymentStatus: String,
    val subtotalCents: Int,
    val deliveryFeeCents: Int,
    val totalCents: Int,
    val customerName: String,
    val customerPhone: String,
    val deliveryAddress: String,
    val notes: String,
    val items: List<OrderDetailItem>,
    val timeline: List<OrderTimelineEntry>,
    val loyalty: LoyaltyOrderSummary? = null,
    val orderSource: String = "",
) {
    val fromEventMenuDelivery: Boolean get() = orderSource.equals("EVENTMENU_DELIVERY", ignoreCase = true)
}

class OrderOperationsRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun accept(orderId: Int) {
        api.postOrderOps("accept", requireToken(), JSONObject().put("order_id", orderId))
    }

    suspend fun applyLoyalty(orderId: Int, points: Int): LoyaltyOrderSummary? {
        val root = api.postOrderOps(
            "loyalty-apply",
            requireToken(),
            JSONObject().put("order_id", orderId).put("points", points),
        )
        return parseLoyalty(root.optJSONObject("loyalty"))
    }

    suspend fun removeLoyalty(orderId: Int): LoyaltyOrderSummary? {
        val root = api.postOrderOps("loyalty-remove", requireToken(), JSONObject().put("order_id", orderId))
        return parseLoyalty(root.optJSONObject("loyalty"))
    }

    suspend fun detail(orderId: Int): OrderOperationalDetail {
        val root = api.getOrderOps("detail", requireToken(), mapOf("order_id" to orderId.toString())).getJSONObject("detail")
        val order = root.getJSONObject("order")
        val customer = root.optJSONObject("customer") ?: JSONObject()
        val itemsJson = root.optJSONArray("items") ?: JSONArray()
        val timelineJson = root.optJSONArray("timeline") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsJson.length()) {
                val item = itemsJson.getJSONObject(i)
                add(
                    OrderDetailItem(
                        id = item.optInt("id"),
                        name = item.optString("name_snapshot"),
                        quantity = item.optDouble("quantity", 1.0),
                        unitPriceCents = item.optInt("unit_price_cents"),
                        totalCents = item.optInt("total_cents"),
                        notes = item.optString("notes"),
                    )
                )
            }
        }
        val timeline = buildList {
            for (i in 0 until timelineJson.length()) {
                val event = timelineJson.getJSONObject(i)
                add(
                    OrderTimelineEntry(
                        id = event.optInt("id"),
                        fromStatus = event.optString("from_status"),
                        toStatus = event.optString("to_status"),
                        source = event.optString("source"),
                        notes = event.optString("notes"),
                        createdAt = event.optString("created_at"),
                        userName = event.optString("user_name"),
                    )
                )
            }
        }
        return OrderOperationalDetail(
            orderId = order.optInt("id"),
            channel = order.optString("channel"),
            status = order.optString("status"),
            paymentStatus = order.optString("payment_status"),
            subtotalCents = order.optInt("subtotal_cents"),
            deliveryFeeCents = order.optInt("delivery_fee_cents"),
            totalCents = order.optInt("total_cents"),
            customerName = customer.optString("name", "Consumidor"),
            customerPhone = customer.optString("phone"),
            deliveryAddress = order.optString("delivery_address"),
            notes = order.optString("notes"),
            items = items,
            timeline = timeline,
            loyalty = parseLoyalty(root.optJSONObject("loyalty")),
            orderSource = order.optString("order_source"),
        )
    }

    private fun parseLoyalty(json: JSONObject?): LoyaltyOrderSummary? {
        if (json == null) return null
        val reservation = json.optJSONObject("order_reservation")?.let {
            LoyaltyOrderReservation(
                points = it.optInt("points"),
                discountCents = it.optInt("discount_cents"),
                status = it.optString("status"),
            )
        }
        return LoyaltyOrderSummary(
            enabled = json.optBoolean("enabled", false),
            customerId = json.optInt("customer_id"),
            balance = json.optInt("balance"),
            reserved = json.optInt("reserved"),
            available = json.optInt("available"),
            redeemPoints = json.optInt("redeem_points", 100),
            redeemValueCents = json.optInt("redeem_value_cents", 0),
            minRedeemPoints = json.optInt("min_redeem_points", 100),
            maxRedeemPercent = json.optInt("max_redeem_percent", 30),
            orderReservation = reservation,
        )
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
