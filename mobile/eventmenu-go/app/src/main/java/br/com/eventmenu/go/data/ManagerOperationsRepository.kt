package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray

class ManagerOperationsRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun overview(): ManagerOverview {
        val o = api.getManager("overview", requireToken()).getJSONObject("overview")
        val alertsJson = o.optJSONArray("alerts") ?: JSONArray()
        val alerts = buildList {
            for (i in 0 until alertsJson.length()) {
                val a = alertsJson.getJSONObject(i)
                add(ManagerAlert(a.optString("level"), a.optString("title"), a.optString("message")))
            }
        }
        return ManagerOverview(
            ordersNow = o.optInt("orders_now"),
            kitchenDelayed = o.optInt("kitchen_delayed"),
            readyOrders = o.optInt("ready_orders"),
            unassignedDelivery = o.optInt("unassigned_delivery"),
            deliveryOnline = o.optInt("delivery_online"),
            cashOpen = o.optInt("cash_open"),
            pendingPayments = o.optInt("pending_payments"),
            revenueTodayCents = o.optInt("revenue_today_cents"),
            alerts = alerts,
        )
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
