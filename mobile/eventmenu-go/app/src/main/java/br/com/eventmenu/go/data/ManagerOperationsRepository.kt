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
                add(ManagerAlert(a.cleanString("level"), a.cleanString("title"), a.cleanString("message")))
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
                add(
                    ManagerCashSession(
                        item.optInt("id"),
                        item.optInt("user_id"),
                        item.cleanString("user_name", "Funcionário"),
                        item.optInt("opening_cash_cents"),
                        item.cleanString("opened_at"),
                    )
                )
            }
        }
        val delivery = buildList {
            for (i in 0 until deliveryJson.length()) {
                val item = deliveryJson.getJSONObject(i)
                add(
                    ManagerDeliveryShift(
                        item.optInt("shift_id"),
                        item.optInt("user_id"),
                        item.cleanString("user_name", "Funcionário"),
                        item.cleanString("started_at"),
                        item.optInt("active_orders"),
                    )
                )
            }
        }
        val problems = buildList {
            for (i in 0 until problemJson.length()) {
                val item = problemJson.getJSONObject(i)
                add(
                    ManagerProblemOrder(
                        id = item.optInt("id"),
                        channel = item.cleanString("channel"),
                        status = item.cleanString("status"),
                        paymentStatus = item.cleanString("payment_status"),
                        totalCents = item.optInt("total_cents"),
                        customerName = item.cleanString("customer_name"),
                        deliveryUserId = if (item.isNull("assigned_delivery_user_id")) null else item.optInt("assigned_delivery_user_id"),
                        deliveryName = item.cleanString("delivery_name"),
                        updatedAt = item.cleanString("updated_at"),
                        problemType = item.cleanString("problem_type"),
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
                        channel = item.cleanString("channel"),
                        paymentStatus = item.cleanString("payment_status"),
                        totalCents = item.optInt("total_cents"),
                        customerName = item.cleanString("customer_name"),
                        tableName = item.cleanString("table_name"),
                        updatedAt = item.cleanString("updated_at"),
                        eligible = item.optInt("reopen_eligible") == 1,
                        blockReason = item.cleanString("reopen_block_reason"),
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

    private fun JSONObject.cleanString(key: String, fallback: String = ""): String {
        if (!has(key) || isNull(key)) return fallback
        val value = optString(key).trim()
        return if (value.isBlank() || value.equals("null", ignoreCase = true)) fallback else value
    }
}
