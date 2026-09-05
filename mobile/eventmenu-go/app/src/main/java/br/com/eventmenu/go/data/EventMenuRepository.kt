package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

class EventMenuRepository(
    baseUrl: String,
    private val deviceId: String,
    val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun login(email: String, password: String, label: String): Session {
        val json = api.post("login", body = JSONObject()
            .put("email", email.trim())
            .put("password", password)
            .put("device_id", deviceId)
            .put("device_label", label))
        val token = json.getString("token")
        sessionStore.saveToken(token)
        return me(token)
    }

    suspend fun me(token: String = requireToken()): Session {
        val json = api.get("me", token)
        val u = json.getJSONObject("user")
        val permissionsJson = json.optJSONObject("permissions") ?: JSONObject()
        val permissions = permissionsJson.keys().asSequence()
            .filter { permissionsJson.optBoolean(it, false) }
            .toSet()
        return Session(
            user = AppUser(u.getInt("id"), u.getInt("tenant_id"), u.getString("name"), u.getString("email"), u.getString("role")),
            permissions = permissions,
        )
    }

    suspend fun logout() {
        sessionStore.token()?.let { runCatching { api.post("logout", it) } }
        sessionStore.clear()
    }

    suspend fun orders(): List<Order> {
        val array = api.get("orders", requireToken()).optJSONArray("orders") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val o = array.getJSONObject(i)
                add(Order(
                    id = o.getInt("id"),
                    channel = o.optString("channel"),
                    status = o.optString("status"),
                    paymentStatus = o.optString("payment_status"),
                    totalCents = o.optInt("total_cents"),
                    customerName = o.optString("customer_name", "Consumidor"),
                    customerPhone = o.optString("customer_phone"),
                    deliveryAddress = o.optString("delivery_address"),
                    assignedDeliveryUserId = if (o.isNull("assigned_delivery_user_id")) null else o.optInt("assigned_delivery_user_id"),
                ))
            }
        }
    }

    suspend fun changeOrderStatus(orderId: Int, status: String) =
        api.post("order-status", requireToken(), JSONObject().put("order_id", orderId).put("status", status))

    suspend fun resolveQr(value: String): QrResult {
        val json = api.get("qr-resolve", requireToken(), mapOf("value" to value))
        val data = json.optJSONObject("data") ?: JSONObject()
        val type = json.optString("type")
        val title = when (type) {
            "table" -> data.optString("name", "Mesa")
            "ticket" -> data.optString("event_name", "Ingresso")
            "guest" -> data.optString("name", "Convidado")
            else -> "Código identificado"
        }
        return QrResult(type, title, value)
    }

    suspend fun ticketCheckIn(value: String) = api.post("ticket-checkin", requireToken(), JSONObject().put("token", value))
    suspend fun guestCheckIn(value: String) = api.post("guest-checkin", requireToken(), JSONObject().put("code", value))

    suspend fun currentCash(): JSONObject = api.get("cash-current", requireToken())
    suspend fun openCash(openingCents: Int, notes: String = "") = api.post("cash-open", requireToken(), JSONObject().put("opening_cash_cents", openingCents).put("notes", notes))
    suspend fun closeCash(countedCents: Int, notes: String = "") = api.post("cash-close", requireToken(), JSONObject().put("counted_cash_cents", countedCents).put("notes", notes))

    suspend fun pixCheckout(orderId: Int): JSONObject = api.post("pix-checkout", requireToken(), JSONObject().put("order_id", orderId))

    suspend fun nfcIntent(orderId: Int): TapOnRequest {
        val json = api.post("nfc-intent", requireToken(), JSONObject().put("order_id", orderId))
        val tap = json.getJSONObject("tap_on")
        return TapOnRequest(
            intentToken = json.getString("intent_token"),
            orderId = json.getInt("order_id"),
            amountCents = json.getInt("amount_cents"),
            appKey = tap.getString("app_key"),
            appName = tap.optString("app_name", "EventMenu GO"),
            appVersion = tap.optString("app_version", "1.0.0"),
            enableTaxPassThrough = tap.optBoolean("enable_tax_pass_through", false),
        )
    }

    suspend fun nfcVerify(intentToken: String, transactionCode: String): JSONObject =
        api.post("nfc-verify", requireToken(), JSONObject().put("intent_token", intentToken).put("transaction_code", transactionCode))

    fun modes(session: Session): List<AppMode> = buildList {
        val p = session.permissions
        if (p.any { it in setOf("orders_create", "orders_kitchen", "tables", "cash") }) add(AppMode.OPERATION)
        if ("orders_delivery" in p || session.user.role == "delivery") add(AppMode.DELIVERY)
        if ("tickets" in p || "guests" in p || session.user.role == "promoter") add(AppMode.EVENTS)
        if ("cash" in p || "nfc_collect" in p) add(AppMode.PAY)
        if (isEmpty()) add(AppMode.OPERATION)
    }.distinct()

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
