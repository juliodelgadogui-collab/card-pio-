package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

data class DeliveryProgress(
    val orderId: Int,
    val orderStatus: String,
    val pickedUpAt: String = "",
    val routeStartedAt: String = "",
    val arrivedAt: String = "",
    val completedAt: String = "",
) {
    val pickedUp: Boolean get() = pickedUpAt.isNotBlank()
    val routeStarted: Boolean get() = routeStartedAt.isNotBlank()
    val arrived: Boolean get() = arrivedAt.isNotBlank()
    val completed: Boolean get() = completedAt.isNotBlank() || orderStatus == "completed"
}

data class DeliveryLocationSample(
    val latitude: Double,
    val longitude: Double,
    val accuracyM: Double? = null,
    val speedMps: Double? = null,
    val bearingDeg: Double? = null,
    val capturedAtMs: Long,
    val batteryPct: Int? = null,
    val provider: String? = null,
    val isMock: Boolean = false,
)

data class DeliveryLocationUploadResult(
    val activeOrders: Int,
    val stored: Int,
)

class DeliveryProgressRepository(
    baseUrl: String,
    deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun listMine(): List<DeliveryProgress> {
        val array = api.getDelivery("list", requireToken()).optJSONArray("progress") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val row = array.optJSONObject(i) ?: continue
                val parsed = parse(row)
                if (parsed.orderId > 0) add(parsed)
            }
        }
    }

    suspend fun pickup(orderId: Int): DeliveryProgress = action("pickup", orderId)
    suspend fun startRoute(orderId: Int): DeliveryProgress = action("start-route", orderId)
    suspend fun arrive(orderId: Int): DeliveryProgress = action("arrive", orderId)

    suspend fun complete(orderId: Int): DeliveryProgress {
        val root = api.postDelivery("complete", requireToken(), JSONObject().put("order_id", orderId))
        return parse(root.optJSONObject("progress") ?: throw ApiException("Progresso da entrega indisponível."))
    }

    suspend fun sendLocationBatch(samples: List<DeliveryLocationSample>): DeliveryLocationUploadResult {
        if (samples.isEmpty()) return DeliveryLocationUploadResult(activeOrders = 0, stored = 0)
        val points = JSONArray()
        samples.takeLast(30).forEach { sample ->
            val point = JSONObject()
                .put("latitude", sample.latitude)
                .put("longitude", sample.longitude)
                .put("captured_at", utc(sample.capturedAtMs))
                .put("is_mock", sample.isMock)
            sample.accuracyM?.let { point.put("accuracy_m", it) }
            sample.speedMps?.let { point.put("speed_mps", it) }
            sample.bearingDeg?.let { point.put("bearing_deg", it) }
            sample.batteryPct?.let { point.put("battery_pct", it) }
            sample.provider?.takeIf { it.isNotBlank() }?.let { point.put("provider", it.take(32)) }
            points.put(point)
        }
        val root = api.postDelivery("location-batch", requireToken(), JSONObject().put("points", points))
        val tracking = root.optJSONObject("tracking") ?: JSONObject()
        return DeliveryLocationUploadResult(
            activeOrders = tracking.optInt("active_orders", 0),
            stored = tracking.optInt("stored", 0),
        )
    }

    suspend fun trackingLink(orderId: Int): String =
        api.postDelivery("tracking-link", requireToken(), JSONObject().put("order_id", orderId)).optString("tracking_url")

    private suspend fun action(action: String, orderId: Int): DeliveryProgress {
        val root = api.postDelivery(action, requireToken(), JSONObject().put("order_id", orderId))
        return parse(root.optJSONObject("progress") ?: throw ApiException("Progresso da entrega indisponível."))
    }

    private fun parse(json: JSONObject) = DeliveryProgress(
        orderId = safeInt(json, "order_id"),
        orderStatus = safeText(json, "order_status"),
        pickedUpAt = safeText(json, "picked_up_at"),
        routeStartedAt = safeText(json, "route_started_at"),
        arrivedAt = safeText(json, "arrived_at"),
        completedAt = safeText(json, "completed_at"),
    )

    private fun safeText(json: JSONObject, key: String): String {
        if (!json.has(key) || json.isNull(key)) return ""
        val value = json.opt(key) ?: return ""
        val text = when (value) {
            is String -> value
            is Number, is Boolean -> value.toString()
            else -> return ""
        }.trim()
        return text.takeUnless { it.isBlank() || it.equals("null", true) || it.equals("undefined", true) } ?: ""
    }

    private fun safeInt(json: JSONObject, key: String): Int {
        if (!json.has(key) || json.isNull(key)) return 0
        return when (val value = json.opt(key)) {
            is Number -> value.toInt()
            is String -> value.trim().toDoubleOrNull()?.toInt() ?: 0
            else -> 0
        }
    }

    private fun utc(ms: Long): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }.format(Date(ms))

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
