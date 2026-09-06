package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLDecoder
import java.util.UUID

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

    suspend fun products():List<Product>{
        val a=api.getGo("catalog",requireToken()).optJSONArray("products")?:JSONArray()
        return buildList{for(i in 0 until a.length()){val p=a.getJSONObject(i);add(Product(
            id=p.getInt("id"),categoryId=if(p.isNull("category_id"))null else p.optInt("category_id"),categoryName=p.optString("category_name").ifBlank{"Sem categoria"},
            name=p.optString("name"),description=p.optString("description"),priceCents=p.optInt("price_cents"),stockQty=p.optDouble("stock_qty",0.0),trackStock=p.optInt("track_stock",0)==1,imageUrl=p.optString("image_url")
        ))}}
    }

    suspend fun tables():List<RestaurantTable>{
        val a=api.getGo("tables-list",requireToken()).optJSONArray("tables")?:JSONArray()
        return buildList{for(i in 0 until a.length()){
            val t=a.getJSONObject(i)
            add(RestaurantTable(
                id=t.getInt("id"),name=t.optString("name"),seats=t.optInt("seats"),status=t.optString("status"),
                tabId=if(t.isNull("tab_id"))null else t.optInt("tab_id"),tabLabel=t.optString("tab_label"),openedAt=t.optString("opened_at"),
                tabTotalCents=t.optInt("tab_total_cents"),unpaidCents=t.optInt("unpaid_cents")
            ))
        }}
    }
    suspend fun openTable(tableId:Int,label:String="")=api.postGo("table-open",requireToken(),JSONObject().put("table_id",tableId).put("label",label))
    suspend fun closeTab(tabId:Int)=api.postGo("table-close",requireToken(),JSONObject().put("tab_id",tabId))

    suspend fun tableAccount(table:RestaurantTable, visibleOrders:List<Order>):TableAccount{
        val tabId=table.tabId?:throw ApiException("Comanda não está aberta.")
        val linked=visibleOrders.filter{it.tabId==tabId && it.status!="cancelled"}.sortedBy{it.id}
        val accountOrders=linked.map{order->
            val balance=paymentBalance(order.id)
            TableAccountOrder(order.id,order.status,balance.paymentStatus,balance.totalCents,balance.paidCents,balance.remainingCents)
        }
        return TableAccount(
            table=table,
            orders=accountOrders,
            totalCents=accountOrders.sumOf{it.totalCents},
            paidCents=accountOrders.sumOf{it.paidCents},
            remainingCents=accountOrders.sumOf{it.remainingCents},
        )
    }

    suspend fun createOrder(channel:String,cart:Map<Int,Int>,customerName:String="",phone:String="",address:String="",notes:String="",tableId:Int?=null):CreatedOrder{
        if(cart.isEmpty())throw ApiException("Carrinho vazio.")
        val items=JSONArray();cart.filterValues{it>0}.forEach{(id,qty)->items.put(JSONObject().put("product_id",id).put("quantity",qty))}
        val body=JSONObject().put("channel",channel).put("customer_name",customerName).put("customer_phone",phone).put("delivery_address",address).put("notes",notes).put("items",items)
        tableId?.let{body.put("table_id",it)}
        val o=api.post("order-create",requireToken(),body).getJSONObject("order")
        return CreatedOrder(o.getInt("id"),o.optString("public_token"),o.optString("channel"),o.getInt("total_cents"))
    }

    suspend fun orders():List<Order>{
        val a=api.getGo("orders",requireToken()).optJSONArray("orders")?:JSONArray()
        return buildList{for(i in 0 until a.length()){val o=a.getJSONObject(i);add(Order(
            id=o.getInt("id"),channel=o.optString("channel"),status=o.optString("status"),paymentStatus=o.optString("payment_status"),totalCents=o.optInt("total_cents"),
            customerName=o.optString("customer_name","Consumidor"),customerPhone=o.optString("customer_phone"),deliveryAddress=o.optString("delivery_address"),
            assignedDeliveryUserId=if(o.isNull("assigned_delivery_user_id"))null else o.optInt("assigned_delivery_user_id"),deliveryName=o.optString("delivery_name"),createdAt=o.optString("created_at"),tableName=o.optString("table_name"),
            tableId=if(o.isNull("table_id"))null else o.optInt("table_id"),tabId=if(o.isNull("tab_id"))null else o.optInt("tab_id")
        ))}}
    }
    suspend fun changeOrderStatus(orderId:Int,status:String)=api.postGo("order-status",requireToken(),JSONObject().put("order_id",orderId).put("status",status))

    suspend fun deliveryUsers():List<DeliveryUser>{
        val a=api.getGo("delivery-users",requireToken()).optJSONArray("delivery_users")?:JSONArray()
        return buildList{for(i in 0 until a.length()){
            val u=a.getJSONObject(i)
            add(DeliveryUser(u.getInt("id"),u.optString("name"),u.optString("email"),u.optInt("on_shift",0)==1,u.optString("started_at")))
        }}
    }
    suspend fun assignDelivery(orderId:Int,deliveryUserId:Int)=api.postGo("delivery-assign",requireToken(),JSONObject().put("order_id",orderId).put("delivery_user_id",deliveryUserId))

    suspend fun kitchenBoard():List<KitchenTicket>{
        val a=api.getGo("kitchen-board",requireToken()).optJSONArray("tickets")?:JSONArray()
        return buildList{for(i in 0 until a.length()){
            val t=a.getJSONObject(i);val itemArray=t.optJSONArray("items")?:JSONArray();val items=buildList{for(j in 0 until itemArray.length()){val x=itemArray.getJSONObject(j);add(KitchenItem(x.optString("name_snapshot"),x.optDouble("quantity",1.0),x.optString("notes")))}}
            add(KitchenTicket(t.getInt("id"),t.optString("channel"),t.optString("status"),t.optString("notes"),t.optString("created_at"),t.optString("table_name"),t.optString("customer_name"),items))
        }}
    }

    suspend fun paymentBalance(orderId:Int):PaymentBalance=parsePaymentBalance(api.getGo("payment-status",requireToken(),mapOf("order_id" to orderId.toString())).getJSONObject("payment"))
    suspend fun payCashPart(orderId:Int,amountCents:Int):PaymentBalance{
        val body=JSONObject().put("order_id",orderId).put("amount_cents",amountCents).put("idempotency_key","go-cash-${UUID.randomUUID()}")
        return parsePaymentBalance(api.postGo("payment-cash",requireToken(),body).getJSONObject("payment"))
    }

    suspend fun resolveQr(value:String):QrResult{
        extractHandoffToken(value)?.let { token ->
            val h=api.getGo("handoff-resolve",requireToken(),mapOf("token" to token)).getJSONObject("handoff")
            return QrResult(type="delivery_handoff",title="Repasse de ${h.optString("delivery_name","Entregador")}",raw=token,subtitle="Dinheiro do turno de Delivery",amountCents=h.optInt("amount_cents"),status=h.optString("status"))
        }

        val known=runCatching{api.get("qr-resolve",requireToken(),mapOf("value" to value))}.getOrNull()
        if(known!=null){
            val data=known.optJSONObject("data")?:JSONObject();val type=known.optString("type")
            val title=when(type){"table"->data.optString("name","Mesa");"ticket"->data.optString("event_name","Ingresso");"guest"->data.optString("name","Convidado");else->"Código identificado"}
            return QrResult(type,title,value)
        }

        val token=Regex("[A-Fa-f0-9]{40}").find(value)?.value?:value.trim()
        val o=api.getGo("order-qr-resolve",requireToken(),mapOf("value" to token)).getJSONObject("order")
        val channel=o.optString("channel")
        val where=when(channel){"table"->o.optString("table_name").ifBlank{"Mesa"};"delivery"->"Delivery";"pickup"->"Retirada";else->"Balcão"}
        val customer=o.optString("customer_name").takeIf{it.isNotBlank()&&it!="Consumidor"}
        val subtitle=listOfNotNull(where,customer).joinToString(" · ")
        return QrResult(type="order",title="Pedido #${o.getInt("id")}",raw=token,subtitle=subtitle,amountCents=o.optInt("total_cents"),status=o.optString("status"),orderId=o.getInt("id"),channel=channel)
    }
    suspend fun ticketCheckIn(value:String)=api.post("ticket-checkin",requireToken(),JSONObject().put("token",value))
    suspend fun guestCheckIn(value:String)=api.post("guest-checkin",requireToken(),JSONObject().put("code",value))

    suspend fun currentCash():JSONObject=api.get("cash-current",requireToken())
    suspend fun openCash(openingCents:Int,notes:String="")=api.post("cash-open",requireToken(),JSONObject().put("opening_cash_cents",openingCents).put("notes",notes))
    suspend fun closeCash(countedCents:Int,notes:String="")=api.post("cash-close",requireToken(),JSONObject().put("counted_cash_cents",countedCents).put("notes",notes))
    suspend fun cashMovement(type:String,amountCents:Int,notes:String,direction:String="in")=api.post("cash-movement",requireToken(),JSONObject().put("type",type).put("amount_cents",amountCents).put("notes",notes).put("direction",direction))
    suspend fun cashSummary():CashSummary{
        val j=api.get("cash-summary",requireToken());val s=j.optJSONObject("summary")?:JSONObject();val sessionJson=s.optJSONObject("session")
        val session=sessionJson?.let{CashSession(it.getInt("id"),it.optString("status"),it.optInt("opening_cash_cents"),it.optString("opened_at"),if(it.isNull("closing_cash_cents"))null else it.optInt("closing_cash_cents"),if(it.isNull("expected_cash_cents"))null else it.optInt("expected_cash_cents"),if(it.isNull("difference_cents"))null else it.optInt("difference_cents"),it.optString("closed_at"))}
        val movementsJson=s.optJSONArray("movements")?:JSONArray();val movements=buildList{for(i in 0 until movementsJson.length()){val m=movementsJson.getJSONObject(i);add(CashMovement(m.getInt("id"),m.optString("type"),m.optString("method"),m.optString("direction"),m.optInt("amount_cents"),m.optString("notes"),m.optString("created_at")))}}
        val byJson=s.optJSONArray("by_method")?:JSONArray();val by=buildList{for(i in 0 until byJson.length()){val x=byJson.getJSONObject(i);add(CashMethodTotal(x.optString("method"),x.optString("direction"),x.optInt("total_cents"),x.optInt("qty")))}}
        val digitalJson=s.optJSONArray("digital")?:JSONArray();val digital=buildList{for(i in 0 until digitalJson.length()){val x=digitalJson.getJSONObject(i);add(CashDigitalTotal(x.optString("provider"),x.optInt("total_cents"),x.optInt("qty")))}}
        return CashSummary(session,s.optInt("expected_cash_cents"),movements,by,digital)
    }

    suspend fun nativePix(orderId:Int,taxId:String,amountCents:Int?=null):PixCharge{
        val body=JSONObject().put("order_id",orderId).put("tax_id",taxId);amountCents?.let{body.put("amount_cents",it)}
        val p=api.postGo("pix-create",requireToken(),body).getJSONObject("pix")
        return PixCharge(p.getInt("payment_id"),p.getInt("order_id"),p.getInt("amount_cents"),p.getString("copy_paste"),p.optString("expires_at"))
    }
    suspend fun nfcIntent(orderId:Int,amountCents:Int?=null):TapOnRequest{
        val body=JSONObject().put("order_id",orderId);amountCents?.let{body.put("amount_cents",it)}
        val j=api.postGo("nfc-intent",requireToken(),body);val t=j.getJSONObject("tap_on")
        return TapOnRequest(j.getString("intent_token"),j.getInt("order_id"),j.getInt("amount_cents"),t.getString("app_key"),t.optString("app_name","EventMenu GO"),t.optString("app_version","1.0.0"),t.optBoolean("enable_tax_pass_through",false))
    }
    suspend fun nfcVerify(intentToken:String,transactionCode:String):JSONObject=api.postGo("nfc-verify",requireToken(),JSONObject().put("intent_token",intentToken).put("transaction_code",transactionCode))

    suspend fun collectDeliveryCash(orderId:Int,receivedCents:Int):DeliveryCashReceipt{
        val r=api.postGo("delivery-cash-collect",requireToken(),JSONObject().put("order_id",orderId).put("received_cents",receivedCents)).getJSONObject("receipt")
        return DeliveryCashReceipt(r.getInt("order_id"),if(r.has("payment_id")&&!r.isNull("payment_id"))r.optInt("payment_id") else null,r.getInt("total_cents"),r.getInt("received_cents"),r.optInt("change_cents"))
    }
    suspend fun deliveryCashOutstanding():DeliveryCashBalance{
        val c=api.getGo("delivery-cash-outstanding",requireToken()).getJSONObject("cash")
        return DeliveryCashBalance(c.getInt("shift_id"),c.optInt("cash_collected_cents"),c.optInt("confirmed_handoff_cents"),c.optInt("outstanding_cents"))
    }
    suspend fun createDeliveryHandoff():CashHandoff{val h=api.postGo("handoff-create",requireToken()).getJSONObject("handoff");return CashHandoff(h.getInt("id"),h.getString("token"),h.getString("qr_payload"),h.getInt("amount_cents"),h.optString("status"))}
    suspend fun confirmDeliveryHandoff(token:String):CashHandoff{val h=api.postGo("handoff-confirm",requireToken(),JSONObject().put("token",token)).getJSONObject("handoff");return CashHandoff(h.getInt("id"),token,"",h.getInt("amount_cents"),h.optString("status"),h.optString("delivery_name"))}

    fun modes(session:Session):List<AppMode> = session.modes.ifEmpty{listOf(AppMode.OPERATION)}
    private fun parseShift(json:JSONObject?):WorkShift? {
        if(json==null)return null
        return WorkShift(
            id=json.optInt("id"),
            mode=json.optString("mode"),
            status=json.optString("status"),
            startedAt=json.optString("started_at"),
            endedAt=json.optString("ended_at").takeIf{it.isNotBlank()},
            unitId=if(json.isNull("unit_id"))null else json.optInt("unit_id").takeIf{it>0},
            unitName=json.optString("unit_name"),
            unitCode=json.optString("unit_code"),
        )
    }
    private fun parsePaymentBalance(p:JSONObject):PaymentBalance{
        val a=p.optJSONArray("payments")?:JSONArray();val parts=buildList{for(i in 0 until a.length()){val x=a.getJSONObject(i);add(PaymentPart(x.getInt("id"),x.optString("provider"),x.optInt("amount_cents"),x.optString("status"),x.optString("verified_at")))}}
        return PaymentBalance(p.getInt("order_id"),p.getInt("total_cents"),p.optInt("paid_cents"),p.optInt("remaining_cents"),p.optString("payment_status"),parts)
    }
    private fun extractHandoffToken(value:String):String?{if(!value.contains("api-go.php")||!value.contains("handoff-view"))return null;val encoded=Regex("[?&]t=([^&]+)").find(value)?.groupValues?.getOrNull(1)?:return null;return URLDecoder.decode(encoded,"UTF-8").takeIf{it.length>=32}}
    private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
