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
        return context(token)
    }

    suspend fun me(token: String = requireToken()): Session = context(token)

    suspend fun context(token: String = requireToken()): Session {
        val json = api.getGo("context", token)
        val u = json.getJSONObject("user")
        val permissionsJson = json.optJSONObject("permissions") ?: JSONObject()
        val permissions = permissionsJson.keys().asSequence().filter { permissionsJson.optBoolean(it, false) }.toSet()
        val modesJson = json.optJSONArray("modes") ?: JSONArray()
        val modes = buildList {
            for (i in 0 until modesJson.length()) AppMode.fromWire(modesJson.optString(i))?.let(::add)
        }.distinct()
        return Session(
            user = AppUser(u.getInt("id"), u.getInt("tenant_id"), u.getString("name"), u.getString("email"), u.getString("role")),
            permissions = permissions,
            modes = modes,
            shift = parseShift(json.optJSONObject("shift")),
        )
    }

    suspend fun logout() {
        sessionStore.token()?.let { runCatching { api.post("logout", it) } }
        sessionStore.clear()
    }

    suspend fun openShift(mode: AppMode, notes: String = ""): WorkShift {
        val json = api.postGo("shift-open", requireToken(), JSONObject().put("mode", mode.wire).put("notes", notes))
        return parseShift(json.getJSONObject("shift")) ?: throw ApiException("Turno inválido.")
    }

    suspend fun closeShift(notes: String = ""): WorkShift {
        val json = api.postGo("shift-close", requireToken(), JSONObject().put("notes", notes))
        return parseShift(json.getJSONObject("shift")) ?: throw ApiException("Turno inválido.")
    }

    suspend fun currentShift(): WorkShift? = parseShift(api.getGo("shift-current", requireToken()).optJSONObject("shift"))
    suspend fun shiftSummary(): JSONObject = api.getGo("shift-summary", requireToken()).getJSONObject("summary")

    suspend fun orders(): List<Order> {
        val array = api.get("orders", requireToken()).optJSONArray("orders") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val o = array.getJSONObject(i)
                add(Order(
                    id = o.getInt("id"), channel = o.optString("channel"), status = o.optString("status"),
                    paymentStatus = o.optString("payment_status"), totalCents = o.optInt("total_cents"),
                    customerName = o.optString("customer_name", "Consumidor"), customerPhone = o.optString("customer_phone"),
                    deliveryAddress = o.optString("delivery_address"),
                    assignedDeliveryUserId = if (o.isNull("assigned_delivery_user_id")) null else o.optInt("assigned_delivery_user_id"),
                ))
            }
        }
    }

    suspend fun changeOrderStatus(orderId: Int, status: String) = api.post("order-status", requireToken(), JSONObject().put("order_id", orderId).put("status", status))

    suspend fun resolveQr(value: String): QrResult {
        val json = api.get("qr-resolve", requireToken(), mapOf("value" to value)); val data = json.optJSONObject("data") ?: JSONObject(); val type = json.optString("type")
        val title = when (type) { "table" -> data.optString("name", "Mesa"); "ticket" -> data.optString("event_name", "Ingresso"); "guest" -> data.optString("name", "Convidado"); else -> "Código identificado" }
        return QrResult(type, title, value)
    }

    suspend fun ticketCheckIn(value: String) = api.post("ticket-checkin", requireToken(), JSONObject().put("token", value))
    suspend fun guestCheckIn(value: String) = api.post("guest-checkin", requireToken(), JSONObject().put("code", value))

    suspend fun currentCash(): JSONObject = api.get("cash-current", requireToken())
    suspend fun openCash(openingCents: Int, notes: String = "") = api.post("cash-open", requireToken(), JSONObject().put("opening_cash_cents", openingCents).put("notes", notes))
    suspend fun closeCash(countedCents: Int, notes: String = "") = api.post("cash-close", requireToken(), JSONObject().put("counted_cash_cents", countedCents).put("notes", notes))

    suspend fun pixCheckout(orderId: Int): JSONObject = api.post("pix-checkout", requireToken(), JSONObject().put("order_id", orderId))

    suspend fun nfcIntent(orderId: Int): TapOnRequest {
        val json = api.post("nfc-intent", requireToken(), JSONObject().put("order_id", orderId)); val tap = json.getJSONObject("tap_on")
        return TapOnRequest(json.getString("intent_token"), json.getInt("order_id"), json.getInt("amount_cents"), tap.getString("app_key"), tap.optString("app_name", "EventMenu GO"), tap.optString("app_version", "1.0.0"), tap.optBoolean("enable_tax_pass_through", false))
    }

    suspend fun nfcVerify(intentToken: String, transactionCode: String): JSONObject = api.post("nfc-verify", requireToken(), JSONObject().put("intent_token", intentToken).put("transaction_code", transactionCode))

    fun modes(session: Session): List<AppMode> = session.modes.ifEmpty { listOf(AppMode.OPERATION) }

    private fun parseShift(json: JSONObject?): WorkShift? {
        if (json == null) return null
        return WorkShift(json.optInt("id"), json.optString("mode"), json.optString("status"), json.optString("started_at"), json.optString("ended_at").takeIf { it.isNotBlank() })
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
