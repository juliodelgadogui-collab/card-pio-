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
)

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
        val response = api.getOrderOps("detail", requireToken(), mapOf("order_id" to orderId.toString()))
        val root = response.optJSONObject("detail") ?: throw ApiException("Detalhes do pedido indisponíveis.")
        val order = root.optJSONObject("order") ?: throw ApiException("Dados principais do pedido indisponíveis.")
        val parsedOrderId = safeInt(order, "id", orderId).takeIf { it > 0 } ?: orderId
        val customer = root.optJSONObject("customer") ?: JSONObject()
        val itemsJson = root.optJSONArray("items") ?: JSONArray()
        val timelineJson = root.optJSONArray("timeline") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsJson.length()) {
                val item = itemsJson.optJSONObject(i) ?: continue
                add(
                    OrderDetailItem(
                        id = safeInt(item, "id", -(i + 1)).let { if (it == 0) -(i + 1) else it },
                        name = safeText(item, "name_snapshot", "Item"),
                        quantity = safeDouble(item, "quantity", 1.0).takeIf { it > 0 } ?: 1.0,
                        unitPriceCents = safeInt(item, "unit_price_cents"),
                        totalCents = safeInt(item, "total_cents"),
                        notes = safeText(item, "notes"),
                    )
                )
            }
        }
        val timeline = buildList {
            for (i in 0 until timelineJson.length()) {
                val event = timelineJson.optJSONObject(i) ?: continue
                add(
                    OrderTimelineEntry(
                        id = safeInt(event, "id", -(i + 1)).let { if (it == 0) -(i + 1) else it },
                        fromStatus = safeText(event, "from_status"),
                        toStatus = safeText(event, "to_status", "pending"),
                        source = safeText(event, "source"),
                        notes = safeText(event, "notes"),
                        createdAt = safeText(event, "created_at"),
                        userName = safeText(event, "user_name"),
                    )
                )
            }
        }
        return OrderOperationalDetail(
            orderId = parsedOrderId,
            channel = safeText(order, "channel", "counter"),
            status = safeText(order, "status", "pending"),
            paymentStatus = safeText(order, "payment_status", "unpaid"),
            subtotalCents = safeInt(order, "subtotal_cents"),
            deliveryFeeCents = safeInt(order, "delivery_fee_cents"),
            totalCents = safeInt(order, "total_cents"),
            customerName = safeText(customer, "name", "Consumidor"),
            customerPhone = safeText(customer, "phone"),
            deliveryAddress = safeText(order, "delivery_address"),
            notes = safeText(order, "notes"),
            items = items,
            timeline = timeline,
            loyalty = parseLoyalty(root.optJSONObject("loyalty")),
        )
    }

    private fun parseLoyalty(json: JSONObject?): LoyaltyOrderSummary? {
        if (json == null) return null
        val reservation = json.optJSONObject("order_reservation")?.let {
            LoyaltyOrderReservation(
                points = safeInt(it, "points"),
                discountCents = safeInt(it, "discount_cents"),
                status = safeText(it, "status"),
            )
        }
        return LoyaltyOrderSummary(
            enabled = safeBoolean(json, "enabled"),
            customerId = safeInt(json, "customer_id"),
            balance = safeInt(json, "balance"),
            reserved = safeInt(json, "reserved"),
            available = safeInt(json, "available"),
            redeemPoints = safeInt(json, "redeem_points", 100),
            redeemValueCents = safeInt(json, "redeem_value_cents"),
            minRedeemPoints = safeInt(json, "min_redeem_points", 100),
            maxRedeemPercent = safeInt(json, "max_redeem_percent", 30),
            orderReservation = reservation,
        )
    }

    private fun safeText(json: JSONObject, key: String, fallback: String = ""): String {
        if (!json.has(key) || json.isNull(key)) return fallback
        val value = json.opt(key) ?: return fallback
        val text = when (value) {
            is String -> value
            is Number, is Boolean -> value.toString()
            else -> return fallback
        }.trim()
        return text.takeUnless { it.isBlank() || it.equals("null", true) || it.equals("undefined", true) } ?: fallback
    }

    private fun safeInt(json: JSONObject, key: String, fallback: Int = 0): Int {
        if (!json.has(key) || json.isNull(key)) return fallback
        return when (val value = json.opt(key)) {
            is Number -> value.toInt()
            is String -> value.trim().toDoubleOrNull()?.toInt() ?: fallback
            else -> fallback
        }
    }

    private fun safeDouble(json: JSONObject, key: String, fallback: Double = 0.0): Double {
        if (!json.has(key) || json.isNull(key)) return fallback
        return when (val value = json.opt(key)) {
            is Number -> value.toDouble()
            is String -> value.trim().replace(',', '.').toDoubleOrNull() ?: fallback
            else -> fallback
        }
    }

    private fun safeBoolean(json: JSONObject, key: String, fallback: Boolean = false): Boolean {
        if (!json.has(key) || json.isNull(key)) return fallback
        return when (val value = json.opt(key)) {
            is Boolean -> value
            is Number -> value.toInt() != 0
            is String -> value.equals("true", true) || value == "1"
            else -> fallback
        }
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
