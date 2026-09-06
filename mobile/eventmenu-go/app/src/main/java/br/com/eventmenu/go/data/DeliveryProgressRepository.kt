package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

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

class DeliveryProgressRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun listMine(): List<DeliveryProgress> {
        val array = api.getDelivery("list", requireToken()).optJSONArray("progress") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) add(parse(array.getJSONObject(i)))
        }
    }

    suspend fun pickup(orderId: Int): DeliveryProgress = action("pickup", orderId)
    suspend fun startRoute(orderId: Int): DeliveryProgress = action("start-route", orderId)
    suspend fun arrive(orderId: Int): DeliveryProgress = action("arrive", orderId)

    suspend fun complete(orderId: Int): DeliveryProgress {
        val root = api.postDelivery("complete", requireToken(), JSONObject().put("order_id", orderId))
        return parse(root.getJSONObject("progress"))
    }

    private suspend fun action(action: String, orderId: Int): DeliveryProgress {
        val root = api.postDelivery(action, requireToken(), JSONObject().put("order_id", orderId))
        return parse(root.getJSONObject("progress"))
    }

    private fun parse(json: JSONObject): DeliveryProgress = DeliveryProgress(
        orderId = json.optInt("order_id"),
        orderStatus = json.optString("order_status"),
        pickedUpAt = json.optString("picked_up_at"),
        routeStartedAt = json.optString("route_started_at"),
        arrivedAt = json.optString("arrived_at"),
        completedAt = json.optString("completed_at"),
    )

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
