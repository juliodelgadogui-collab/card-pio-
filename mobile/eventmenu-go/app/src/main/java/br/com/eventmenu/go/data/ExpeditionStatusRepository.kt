package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray

data class DeliveryExpeditionStatus(
    val orderId: Int,
    val stage: String,
    val deliveryUserId: Int?,
    val deliveryName: String,
    val pickedUp: Boolean,
    val routeStarted: Boolean,
    val arrived: Boolean,
    val completed: Boolean,
    val locationFresh: Boolean,
)

class ExpeditionStatusRepository(
    baseUrl: String,
    deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun status(): List<DeliveryExpeditionStatus> {
        val array = api.getExpedition("status", requireToken()).optJSONArray("delivery") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val item = array.optJSONObject(i) ?: continue
                add(
                    DeliveryExpeditionStatus(
                        orderId = item.optInt("order_id"),
                        stage = item.optString("stage"),
                        deliveryUserId = if (item.isNull("delivery_user_id")) null else item.optInt("delivery_user_id"),
                        deliveryName = item.optString("delivery_name"),
                        pickedUp = item.optBoolean("picked_up"),
                        routeStarted = item.optBoolean("route_started"),
                        arrived = item.optBoolean("arrived"),
                        completed = item.optBoolean("completed"),
                        locationFresh = item.optBoolean("location_fresh"),
                    )
                )
            }
        }
    }

    private fun requireToken(): String = sessionStore.token()?.takeIf { it.isNotBlank() }
        ?: throw ApiException("Faça login novamente.", 401)
}
