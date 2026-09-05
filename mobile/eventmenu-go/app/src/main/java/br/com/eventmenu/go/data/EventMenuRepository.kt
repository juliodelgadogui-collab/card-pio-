package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLDecoder

class EventMenuRepository(baseUrl: String, private val deviceId: String, val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun login(email: String, password: String, label: String): Session {
        val json = api.post("login", body = JSONObject().put("email", email.trim()).put("password", password).put("device_id", deviceId).put("device_label", label))
        val token = json.getString("token"); sessionStore.saveToken(token); return context(token)
    }
    suspend fun me(token: String = requireToken()): Session = context(token)
    suspend fun context(token: String = requireToken()): Session {
        val json = api.getGo("context", token); val u = json.getJSONObject("user"); val p = json.optJSONObject("permissions") ?: JSONObject()
        val permissions = p.keys().asSequence().filter { p.optBoolean(it, false) }.toSet(); val modesJson = json.optJSONArray("modes") ?: JSONArray()
        val modes = buildList { for (i in 0 until modesJson.length()) AppMode.fromWire(modesJson.optString(i))?.let(::add) }.distinct()
        return Session(AppUser(u.getInt("id"),u.getInt("tenant_id"),u.getString("name"),u.getString("email"),u.getString("role")),permissions,modes,parseShift(json.optJSONObject("shift")))
    }
    suspend fun logout(){sessionStore.token()?.let{runCatching{api.post("logout",it)}};sessionStore.clear()}
    suspend fun openShift(mode: AppMode, notes: String=""):WorkShift=parseShift(api.postGo("shift-open",requireToken(),JSONObject().put("mode",mode.wire).put("notes",notes)).getJSONObject("shift"))?:throw ApiException("Turno inválido.")
    suspend fun closeShift(notes:String=""):WorkShift=parseShift(api.postGo("shift-close",requireToken(),JSONObject().put("notes",notes)).getJSONObject("shift"))?:throw ApiException("Turno inválido.")
    suspend fun currentShift():WorkShift?=parseShift(api.getGo("shift-current",requireToken()).optJSONObject("shift"))
    suspend fun shiftSummary():JSONObject=api.getGo("shift-summary",requireToken()).getJSONObject("summary")

    suspend fun orders():List<Order>{val a=api.get("orders",requireToken()).optJSONArray("orders")?:JSONArray();return buildList{for(i in 0 until a.length()){val o=a.getJSONObject(i);add(Order(o.getInt("id"),o.optString("channel"),o.optString("status"),o.optString("payment_status"),o.optInt("total_cents"),o.optString("customer_name","Consumidor"),o.optString("customer_phone"),o.optString("delivery_address"),if(o.isNull("assigned_delivery_user_id"))null else o.optInt("assigned_delivery_user_id")))}}}
    suspend fun changeOrderStatus(orderId:Int,status:String)=api.post("order-status",requireToken(),JSONObject().put("order_id",orderId).put("status",status))

    suspend fun resolveQr(value:String):QrResult{
        extractHandoffToken(value)?.let { token ->
            val h=api.getGo("handoff-resolve",requireToken(),mapOf("token" to token)).getJSONObject("handoff")
            return QrResult(
                type="delivery_handoff",
                title="Repasse de ${h.optString("delivery_name","Entregador")}",
                raw=token,
                subtitle="Dinheiro do turno de Delivery",
                amountCents=h.optInt("amount_cents"),
                status=h.optString("status"),
            )
        }
        val json=api.get("qr-resolve",requireToken(),mapOf("value" to value));val data=json.optJSONObject("data")?:JSONObject();val type=json.optString("type")
        val title=when(type){"table"->data.optString("name","Mesa");"ticket"->data.optString("event_name","Ingresso");"guest"->data.optString("name","Convidado");else->"Código identificado"}
        return QrResult(type,title,value)
    }
    suspend fun ticketCheckIn(value:String)=api.post("ticket-checkin",requireToken(),JSONObject().put("token",value))
    suspend fun guestCheckIn(value:String)=api.post("guest-checkin",requireToken(),JSONObject().put("code",value))

    suspend fun currentCash():JSONObject=api.get("cash-current",requireToken())
    suspend fun openCash(openingCents:Int,notes:String="")=api.post("cash-open",requireToken(),JSONObject().put("opening_cash_cents",openingCents).put("notes",notes))
    suspend fun closeCash(countedCents:Int,notes:String="")=api.post("cash-close",requireToken(),JSONObject().put("counted_cash_cents",countedCents).put("notes",notes))

    suspend fun nativePix(orderId:Int,taxId:String):PixCharge{
        val p=api.postGo("pix-create",requireToken(),JSONObject().put("order_id",orderId).put("tax_id",taxId)).getJSONObject("pix")
        return PixCharge(p.getInt("payment_id"),p.getInt("order_id"),p.getInt("amount_cents"),p.getString("copy_paste"),p.optString("expires_at"))
    }
    suspend fun nfcIntent(orderId:Int):TapOnRequest{val j=api.post("nfc-intent",requireToken(),JSONObject().put("order_id",orderId));val t=j.getJSONObject("tap_on");return TapOnRequest(j.getString("intent_token"),j.getInt("order_id"),j.getInt("amount_cents"),t.getString("app_key"),t.optString("app_name","EventMenu GO"),t.optString("app_version","1.0.0"),t.optBoolean("enable_tax_pass_through",false))}
    suspend fun nfcVerify(intentToken:String,transactionCode:String):JSONObject=api.post("nfc-verify",requireToken(),JSONObject().put("intent_token",intentToken).put("transaction_code",transactionCode))

    suspend fun collectDeliveryCash(orderId:Int,receivedCents:Int):DeliveryCashReceipt{
        val r=api.postGo("delivery-cash-collect",requireToken(),JSONObject().put("order_id",orderId).put("received_cents",receivedCents)).getJSONObject("receipt")
        return DeliveryCashReceipt(r.getInt("order_id"),if(r.has("payment_id")&&!r.isNull("payment_id"))r.optInt("payment_id") else null,r.getInt("total_cents"),r.getInt("received_cents"),r.optInt("change_cents"))
    }
    suspend fun deliveryCashOutstanding():DeliveryCashBalance{
        val c=api.getGo("delivery-cash-outstanding",requireToken()).getJSONObject("cash")
        return DeliveryCashBalance(c.getInt("shift_id"),c.optInt("cash_collected_cents"),c.optInt("confirmed_handoff_cents"),c.optInt("outstanding_cents"))
    }
    suspend fun createDeliveryHandoff():CashHandoff{
        val h=api.postGo("handoff-create",requireToken()).getJSONObject("handoff")
        return CashHandoff(h.getInt("id"),h.getString("token"),h.getString("qr_payload"),h.getInt("amount_cents"),h.optString("status"))
    }
    suspend fun confirmDeliveryHandoff(token:String):CashHandoff{
        val h=api.postGo("handoff-confirm",requireToken(),JSONObject().put("token",token)).getJSONObject("handoff")
        return CashHandoff(h.getInt("id"),token,"",h.getInt("amount_cents"),h.optString("status"),h.optString("delivery_name"))
    }

    fun modes(session:Session):List<AppMode> = session.modes.ifEmpty{listOf(AppMode.OPERATION)}
    private fun parseShift(json:JSONObject?):WorkShift?{if(json==null)return null;return WorkShift(json.optInt("id"),json.optString("mode"),json.optString("status"),json.optString("started_at"),json.optString("ended_at").takeIf{it.isNotBlank()})}
    private fun extractHandoffToken(value:String):String?{
        if(!value.contains("api-go.php")||!value.contains("handoff-view"))return null
        val encoded=Regex("[?&]t=([^&]+)").find(value)?.groupValues?.getOrNull(1)?:return null
        return URLDecoder.decode(encoded,"UTF-8").takeIf{it.length>=32}
    }
    private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
