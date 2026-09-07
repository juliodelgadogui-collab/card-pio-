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
                add(ManagerCashSession(item.optInt("id"), item.optInt("user_id"), friendly(item.optString("user_name"), "Funcionário"), item.optInt("opening_cash_cents"), item.optString("opened_at")))
            }
        }
        val delivery = buildList {
            for (i in 0 until deliveryJson.length()) {
                val item = deliveryJson.getJSONObject(i)
                add(ManagerDeliveryShift(item.optInt("shift_id"), item.optInt("user_id"), friendly(item.optString("user_name"), "Funcionário"), item.optString("started_at"), item.optInt("active_orders")))
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
                        customerName = friendly(item.optString("customer_name"), ""),
                        deliveryUserId = if (item.isNull("assigned_delivery_user_id")) null else item.optInt("assigned_delivery_user_id"),
                        deliveryName = friendly(item.optString("delivery_name"), ""),
                        updatedAt = item.optString("updated_at"),
                        problemType = item.optString("problem_type"),
                    )
                )
            }
        }
        return ManagerDetails(cash, delivery, problems)
    }

    suspend fun reopenCandidates(): List<ManagerReopenCandidate> {
        val array = api.getManager("reopen-candidates", requireToken()).optJSONArray("orders") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val item = array.optJSONObject(i) ?: continue
                add(
                    ManagerReopenCandidate(
                        id = item.optInt("id"),
                        channel = item.optString("channel"),
                        paymentStatus = item.optString("payment_status"),
                        totalCents = item.optInt("total_cents"),
                        customerName = friendly(item.optString("customer_name"), "Consumidor"),
                        tableName = friendly(item.optString("table_name"), ""),
                        updatedAt = item.optString("updated_at"),
                        eligible = item.optInt("reopen_eligible") == 1,
                        blockReason = friendly(item.optString("reopen_block_reason"), ""),
                    )
                )
            }
        }
    }

    suspend fun transferDelivery(orderId: Int, deliveryUserId: Int) {
        api.postManager("transfer-delivery", requireToken(), JSONObject().put("order_id", orderId).put("delivery_user_id", deliveryUserId))
    }

    suspend fun reopenOrder(orderId: Int, reason: String) {
        api.postManager("reopen-order", requireToken(), JSONObject().put("order_id", orderId).put("reason", reason))
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)

    private fun friendly(value: String, fallback: String): String {
        val clean = value.trim()
        return if (clean.isBlank() || clean.equals("null", true) || clean.equals("undefined", true)) fallback else clean
    }
}
