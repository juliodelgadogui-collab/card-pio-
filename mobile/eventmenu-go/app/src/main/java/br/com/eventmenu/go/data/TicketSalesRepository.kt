package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class TicketSaleEvent(val id:Int,val name:String,val startsAt:String)
data class TicketSaleBatch(val id:Int,val name:String,val typeName:String,val priceCents:Int,val available:Int)
data class TicketSaleCatalog(val event:TicketSaleEvent,val batches:List<TicketSaleBatch>,val canCourtesy:Boolean)
data class SoldTicket(val id:Int,val code:String,val qrToken:String)
data class TicketSaleResult(val orderId:Int,val publicToken:String,val paymentStatus:String,val paymentMethod:String,val anonymous:Boolean,val totalCents:Int,val tickets:List<SoldTicket>)
data class TicketShiftState(val shift:WorkShift?,val eventsAllowed:Boolean)

class TicketSalesRepository(baseUrl:String,deviceId:String,private val sessionStore:SecureSessionStore){
    private val api=ApiClient(baseUrl,deviceId,sessionStore)
    suspend fun shiftState():TicketShiftState{
        val root=api.getGo("context",requireToken())
        val modes=root.optJSONArray("modes")?:JSONArray()
        var eventsAllowed=false
        for(i in 0 until modes.length()) if(modes.optString(i)==AppMode.EVENTS.wire) eventsAllowed=true
        return TicketShiftState(parseWorkShift(root.optJSONObject("shift")),eventsAllowed)
    }
    suspend fun activateEventsShift():WorkShift{
        val state=shiftState()
        if(!state.eventsAllowed)throw ApiException("Sua conta não possui permissão para operar Eventos.",403)
        val current=state.shift
        if(current?.mode==AppMode.EVENTS.wire)return current
        if(current!=null){
            api.postGo("shift-close",requireToken(),JSONObject().put("notes","Troca para operação de Eventos pela Bilheteria"))
        }
        val root=api.postGo("shift-open",requireToken(),JSONObject().put("mode",AppMode.EVENTS.wire).put("notes","Operação de Eventos iniciada pela Bilheteria"))
        return parseWorkShift(root.getJSONObject("shift"))?:throw ApiException("Não foi possível iniciar a operação de Eventos.")
    }
    suspend fun events():List<TicketSaleEvent>{
        val a=api.getEvents("overview",requireToken()).optJSONArray("events")?:JSONArray()
        return buildList{for(i in 0 until a.length()){val e=a.getJSONObject(i);if(e.optString("status") in setOf("published","draft"))add(TicketSaleEvent(e.getInt("id"),e.optString("name"),e.optString("starts_at")))}}
    }
    suspend fun catalog(eventId:Int):TicketSaleCatalog{
        val r=api.getEvents("ticket-sale-catalog",requireToken(),mapOf("event_id" to eventId.toString()));val e=r.getJSONObject("event");val a=r.optJSONArray("batches")?:JSONArray()
        return TicketSaleCatalog(TicketSaleEvent(e.getInt("id"),e.optString("name"),e.optString("starts_at")),buildList{for(i in 0 until a.length()){val b=a.getJSONObject(i);add(TicketSaleBatch(b.getInt("id"),b.optString("name"),b.optString("ticket_type_name"),b.optInt("price_cents"),b.optInt("available"))) }},r.optBoolean("can_courtesy"))
    }
    suspend fun sell(eventId:Int,batchId:Int,quantity:Int,method:String,name:String="",phone:String="",email:String=""):TicketSaleResult{
        val body=JSONObject().put("event_id",eventId).put("batch_id",batchId).put("quantity",quantity).put("payment_method",method).put("buyer_name",name.trim()).put("buyer_phone",phone.trim()).put("buyer_email",email.trim())
        val s=api.postEvents("ticket-sale",requireToken(),body).getJSONObject("sale");val a=s.optJSONArray("tickets")?:JSONArray()
        return TicketSaleResult(s.getInt("order_id"),s.optString("public_token"),s.optString("payment_status"),s.optString("payment_method"),s.optBoolean("anonymous"),s.optInt("total_cents"),buildList{for(i in 0 until a.length()){val t=a.getJSONObject(i);add(SoldTicket(t.getInt("id"),t.optString("code"),t.optString("qr_token")))}})
    }
    private fun parseWorkShift(o:JSONObject?):WorkShift?{
        if(o==null)return null
        return WorkShift(o.optInt("id"),o.optString("mode"),o.optString("status"),o.optString("started_at"),o.optString("ended_at").takeIf{it.isNotBlank()},if(o.isNull("unit_id"))null else o.optInt("unit_id"),o.optString("unit_name"),o.optString("unit_code"))
    }
    private fun requireToken()=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
