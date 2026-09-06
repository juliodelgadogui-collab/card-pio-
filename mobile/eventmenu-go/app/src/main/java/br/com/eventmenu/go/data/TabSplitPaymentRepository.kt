package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

class TabSplitPaymentRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun account(tabId: Int): TabSplitAccount {
        val a = api.getTabPayments("account", requireToken(), mapOf("tab_id" to tabId.toString())).getJSONObject("account")
        val tab = a.getJSONObject("tab")
        val ordersJson = a.optJSONArray("orders") ?: JSONArray()
        val orders = buildList {
            for (i in 0 until ordersJson.length()) {
                val o = ordersJson.getJSONObject(i)
                add(TabSplitOrder(o.getInt("id"), o.optString("status"), o.optString("payment_status"), o.optInt("total_cents"), o.optInt("paid_cents"), o.optInt("remaining_cents")))
            }
        }
        val itemsJson = a.optJSONArray("items") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsJson.length()) {
                val item = itemsJson.getJSONObject(i)
                add(
                    TabSplitItem(
                        orderItemId = item.getInt("order_item_id"),
                        orderId = item.getInt("order_id"),
                        productId = if (item.isNull("product_id")) null else item.optInt("product_id"),
                        name = item.optString("name_snapshot"),
                        quantity = item.optDouble("quantity", 1.0),
                        totalCents = item.optInt("total_cents"),
                        splitUsed = item.optInt("split_used", 0) == 1,
                    )
                )
            }
        }
        val openJson = a.optJSONObject("open_group")
        val open = openJson?.let { TabSplitOpenGroup(it.getInt("id"), it.optString("method"), it.optString("split_type"), it.optInt("amount_cents"), it.optString("status")) }
        return TabSplitAccount(
            tabId = tab.getInt("id"),
            tableName = tab.optString("table_name"),
            tabLabel = tab.optString("label"),
            totalCents = a.optInt("total_cents"),
            paidCents = a.optInt("paid_cents"),
            remainingCents = a.optInt("remaining_cents"),
            orders = orders,
            items = items,
            openGroup = open,
        )
    }

    suspend fun createGroup(tabId: Int, splitType: String, method: String, options: JSONObject): PaymentGroup {
        val body = JSONObject()
            .put("tab_id", tabId)
            .put("split_type", splitType)
            .put("method", method)
            .put("options", options)
            .put("idempotency_key", "go-tab-group-${UUID.randomUUID()}")
        return parseGroup(api.postTabPayments("group-create", requireToken(), body).getJSONObject("group"))
    }

    suspend fun groupStatus(groupId: Int): PaymentGroup =
        parseGroup(api.getTabPayments("group-status", requireToken(), mapOf("group_id" to groupId.toString())).getJSONObject("group"))

    suspend fun cancel(groupId: Int): PaymentGroup =
        parseGroup(api.postTabPayments("group-cancel", requireToken(), JSONObject().put("group_id", groupId)).getJSONObject("group"))

    suspend fun pix(groupId: Int, taxId: String): GroupPixCharge {
        val p = api.postTabPayments("pix-create", requireToken(), JSONObject().put("group_id", groupId).put("tax_id", taxId)).getJSONObject("pix")
        return GroupPixCharge(p.getInt("group_id"), p.getInt("amount_cents"), p.getString("copy_paste"), p.optString("expires_at"))
    }

    suspend fun pixStatus(groupId: Int): Pair<PaymentGroup, Boolean> {
        val j = api.getTabPayments("pix-status", requireToken(), mapOf("group_id" to groupId.toString()))
        return parseGroup(j.getJSONObject("group")) to j.optBoolean("paid", false)
    }

    suspend fun nfcIntent(groupId: Int): TapOnRequest {
        val j = api.postTabPayments("nfc-intent", requireToken(), JSONObject().put("group_id", groupId))
        val t = j.getJSONObject("tap_on")
        return TapOnRequest(
            intentToken = j.getString("intent_token"),
            orderId = 0,
            amountCents = j.getInt("amount_cents"),
            appKey = t.getString("app_key"),
            appName = t.optString("app_name", "EventMenu GO"),
            appVersion = t.optString("app_version", "1.0.0"),
            enableTaxPassThrough = t.optBoolean("enable_tax_pass_through", false),
        )
    }

    suspend fun verifyNfc(intentToken: String, transactionCode: String): PaymentGroup =
        parseGroup(api.postTabPayments("nfc-verify", requireToken(), JSONObject().put("intent_token", intentToken).put("transaction_code", transactionCode)).getJSONObject("group"))

    private fun parseGroup(g: JSONObject): PaymentGroup {
        val a = g.optJSONArray("allocations") ?: JSONArray()
        val allocations = buildList {
            for (i in 0 until a.length()) {
                val x = a.getJSONObject(i)
                add(PaymentGroupAllocation(x.getInt("order_id"), x.getInt("payment_id"), x.getInt("amount_cents"), x.optString("payment_status"), x.optString("order_payment_status")))
            }
        }
        return PaymentGroup(
            id = g.getInt("id"),
            tabId = g.optInt("tab_id"),
            provider = g.optString("provider"),
            method = g.optString("method"),
            splitType = g.optString("split_type"),
            amountCents = g.optInt("amount_cents"),
            status = g.optString("status"),
            providerPaymentId = g.optString("provider_payment_id"),
            tabRemainingCents = g.optInt("tab_remaining_cents"),
            allocations = allocations,
        )
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
