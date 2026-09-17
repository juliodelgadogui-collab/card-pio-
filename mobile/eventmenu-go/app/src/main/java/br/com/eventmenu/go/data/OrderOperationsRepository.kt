package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class OrderDetailItem(val id:Int,val name:String,val quantity:Double,val unitPriceCents:Int,val totalCents:Int,val notes:String)
data class OrderTimelineEntry(val id:Int,val fromStatus:String,val toStatus:String,val source:String,val notes:String,val createdAt:String,val userName:String)
data class LoyaltyOrderReservation(val points:Int,val discountCents:Int,val status:String)
data class LoyaltyOrderSummary(val enabled:Boolean,val customerId:Int,val balance:Int,val reserved:Int,val available:Int,val redeemPoints:Int,val redeemValueCents:Int,val minRedeemPoints:Int,val maxRedeemPercent:Int,val orderReservation:LoyaltyOrderReservation?)
data class OrderOperationalDetail(val orderId:Int,val channel:String,val status:String,val paymentStatus:String,val subtotalCents:Int,val deliveryFeeCents:Int,val totalCents:Int,val customerName:String,val customerPhone:String,val deliveryAddress:String,val notes:String,val items:List<OrderDetailItem>,val timeline:List<OrderTimelineEntry>,val loyalty:LoyaltyOrderSummary?=null)

class OrderOperationsRepository(baseUrl:String,deviceId:String,private val sessionStore:SecureSessionStore){
 private val api=ApiClient(baseUrl,deviceId)
 suspend fun accept(orderId:Int){api.postOrderOps("accept",requireToken(),JSONObject().put("order_id",orderId))}
 suspend fun applyLoyalty(orderId:Int,points:Int):LoyaltyOrderSummary?=parseLoyalty(api.postOrderOps("loyalty-apply",requireToken(),JSONObject().put("order_id",orderId).put("points",points)).optJSONObject("loyalty"))
 suspend fun removeLoyalty(orderId:Int):LoyaltyOrderSummary?=parseLoyalty(api.postOrderOps("loyalty-remove",requireToken(),JSONObject().put("order_id",orderId)).optJSONObject("loyalty"))
 suspend fun detail(orderId:Int):OrderOperationalDetail{
  val response=api.getOrderOps("detail",requireToken(),mapOf("order_id" to orderId.toString()))
  val root=response.optJSONObject("detail") ?: throw ApiException(response.optString("message","Não foi possível carregar os detalhes do pedido."),422)
  val order=root.optJSONObject("order") ?: throw ApiException("O servidor retornou o pedido sem os dados principais. Atualize o servidor e tente novamente.",422)
  val customer=root.optJSONObject("customer") ?: JSONObject()
  val itemsJson=root.optJSONArray("items") ?: JSONArray(); val timelineJson=root.optJSONArray("timeline") ?: JSONArray()
  val items=buildList { for(i in 0 until itemsJson.length()){ val item=itemsJson.optJSONObject(i)?:continue; add(OrderDetailItem(item.optInt("id"),safe(item,"name_snapshot","Item"),item.optDouble("quantity",1.0),item.optInt("unit_price_cents"),item.optInt("total_cents"),safe(item,"notes"))) } }
  val timeline=buildList { for(i in 0 until timelineJson.length()){ val e=timelineJson.optJSONObject(i)?:continue; add(OrderTimelineEntry(e.optInt("id"),safe(e,"from_status"),safe(e,"to_status"),safe(e,"source"),safe(e,"notes"),safe(e,"created_at"),safe(e,"user_name"))) } }
  return OrderOperationalDetail(order.optInt("id",orderId),safe(order,"channel"),safe(order,"status"),safe(order,"payment_status"),order.optInt("subtotal_cents"),order.optInt("delivery_fee_cents"),order.optInt("total_cents"),safe(customer,"name","Consumidor"),safe(customer,"phone"),safe(order,"delivery_address"),safe(order,"notes"),items,timeline,parseLoyalty(root.optJSONObject("loyalty")))
 }
 private fun safe(json:JSONObject,key:String,fallback:String=""):String{ if(!json.has(key)||json.isNull(key)) return fallback; val v=json.optString(key,fallback); return if(v.equals("null",true)) fallback else v }
 private fun parseLoyalty(json:JSONObject?):LoyaltyOrderSummary?{ if(json==null)return null; val reservation=json.optJSONObject("order_reservation")?.let{LoyaltyOrderReservation(it.optInt("points"),it.optInt("discount_cents"),safe(it,"status"))}; return LoyaltyOrderSummary(json.optBoolean("enabled",false),json.optInt("customer_id"),json.optInt("balance"),json.optInt("reserved"),json.optInt("available"),json.optInt("redeem_points",100),json.optInt("redeem_value_cents"),json.optInt("min_redeem_points",100),json.optInt("max_redeem_percent",30),reservation) }
 private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
