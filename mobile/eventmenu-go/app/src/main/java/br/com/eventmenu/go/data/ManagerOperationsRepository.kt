package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

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

    suspend fun details(): ManagerDetails {
        val d = api.getManager("details", requireToken()).getJSONObject("details")
        val cashJson = d.optJSONArray("cash_sessions") ?: JSONArray()
        val deliveryJson = d.optJSONArray("delivery_shifts") ?: JSONArray()
        val problemJson = d.optJSONArray("problem_orders") ?: JSONArray()
        val cash = buildList {
            for (i in 0 until cashJson.length()) {
                val item = cashJson.getJSONObject(i)
                add(ManagerCashSession(item.optInt("id"), item.optInt("user_id"), item.optString("user_name"), item.optInt("opening_cash_cents"), item.optString("opened_at")))
            }
        }
        val delivery = buildList {
            for (i in 0 until deliveryJson.length()) {
                val item = deliveryJson.getJSONObject(i)
                add(ManagerDeliveryShift(item.optInt("shift_id"), item.optInt("user_id"), item.optString("user_name"), item.optString("started_at"), item.optInt("active_orders")))
            }
        }
        val problems = buildList {
            for (i in 0 until problemJson.length()) {
                val item = problemJson.getJSONObject(i)
                add(
                    ManagerProblemOrder(
                        id = item.optInt("id"),
                        channel = item.optString("channel"),
                        status = item.optString("status"),
                        paymentStatus = item.optString("payment_status"),
                        totalCents = item.optInt("total_cents"),
                        customerName = item.optString("customer_name").ifBlank { "Consumidor" },
                        deliveryUserId = if (item.isNull("assigned_delivery_user_id")) null else item.optInt("assigned_delivery_user_id"),
                        deliveryName = item.optString("delivery_name"),
                        updatedAt = item.optString("updated_at"),
                        problemType = item.optString("problem_type"),
                    )
                )
            }
        }
        return ManagerDetails(cash, delivery, problems)
    }

    suspend fun transferDelivery(orderId: Int, deliveryUserId: Int) {
        api.postManager("transfer-delivery", requireToken(), JSONObject().put("order_id", orderId).put("delivery_user_id", deliveryUserId))
    }

    suspend fun cancelOrder(orderId: Int) {
        api.postManager("cancel-order", requireToken(), JSONObject().put("order_id", orderId))
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
