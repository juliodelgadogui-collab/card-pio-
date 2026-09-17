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
 suspend fun applyLoyalty(orderId:Int,points:Int):LoyaltyOrderSummary?=parseLoyalty(api.postOrderOps("loyalty-apply",requireToken(),JSONObject().put("order_id",orderId).put("points",points)).safeObject("loyalty"))
 suspend fun removeLoyalty(orderId:Int):LoyaltyOrderSummary?=parseLoyalty(api.postOrderOps("loyalty-remove",requireToken(),JSONObject().put("order_id",orderId)).safeObject("loyalty"))

 suspend fun detail(orderId:Int):OrderOperationalDetail{
  val response=api.getOrderOps("detail",requireToken(),mapOf("order_id" to orderId.toString()))
  val root=response.safeObject("detail")?:throw ApiException("O servidor não retornou os detalhes deste pedido.",502)
  val order=root.safeObject("order")?:throw ApiException("Os dados principais do pedido estão indisponíveis.",502)
  val customer=root.safeObject("customer")?:JSONObject()
  val itemsJson=root.safeArray("items")?:JSONArray(); val timelineJson=root.safeArray("timeline")?:JSONArray()
  val items=buildList{for(i in 0 until itemsJson.length()){val item=itemsJson.optJSONObject(i)?:continue;add(OrderDetailItem(item.safeInt("id"),item.safeText("name_snapshot","Item"),item.safeDouble("quantity",1.0),item.safeInt("unit_price_cents"),item.safeInt("total_cents"),item.safeText("notes")))}}
  val timeline=buildList{for(i in 0 until timelineJson.length()){val event=timelineJson.optJSONObject(i)?:continue;add(OrderTimelineEntry(event.safeInt("id"),event.safeText("from_status"),event.safeText("to_status"),event.safeText("source"),event.safeText("notes"),event.safeText("created_at"),event.safeText("user_name")))}}
  return OrderOperationalDetail(order.safeInt("id",orderId),order.safeText("channel","unknown"),order.safeText("status","unknown"),order.safeText("payment_status","unknown"),order.safeInt("subtotal_cents"),order.safeInt("delivery_fee_cents"),order.safeInt("total_cents"),customer.safeText("name","Consumidor"),customer.safeText("phone"),order.safeText("delivery_address"),order.safeText("notes"),items,timeline,parseLoyalty(root.safeObject("loyalty")))
 }
 private fun parseLoyalty(json:JSONObject?):LoyaltyOrderSummary?{if(json==null)return null;val reservation=json.safeObject("order_reservation")?.let{LoyaltyOrderReservation(it.safeInt("points"),it.safeInt("discount_cents"),it.safeText("status"))};return LoyaltyOrderSummary(json.safeBoolean("enabled"),json.safeInt("customer_id"),json.safeInt("balance"),json.safeInt("reserved"),json.safeInt("available"),json.safeInt("redeem_points",100),json.safeInt("redeem_value_cents"),json.safeInt("min_redeem_points",100),json.safeInt("max_redeem_percent",30),reservation)}
 private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}

private fun JSONObject.safeObject(key:String):JSONObject?=when(val v=opt(key)){is JSONObject->v;else->null}
private fun JSONObject.safeArray(key:String):JSONArray?=when(val v=opt(key)){is JSONArray->v;else->null}
private fun JSONObject.safeText(key:String,fallback:String=""):String{val v=opt(key);if(v==null||v==JSONObject.NULL)return fallback;val s=v.toString().trim();return if(s.isBlank()||s.equals("null",true)||s.equals("undefined",true))fallback else s}
private fun JSONObject.safeInt(key:String,fallback:Int=0):Int=when(val v=opt(key)){is Number->v.toInt();is String->v.trim().toDoubleOrNull()?.toInt()?:fallback;else->fallback}
private fun JSONObject.safeDouble(key:String,fallback:Double=0.0):Double=when(val v=opt(key)){is Number->v.toDouble();is String->v.trim().replace(',','.').toDoubleOrNull()?:fallback;else->fallback}
private fun JSONObject.safeBoolean(key:String,fallback:Boolean=false):Boolean=when(val v=opt(key)){is Boolean->v;is Number->v.toInt()!=0;is String->v.equals("true",true)||v=="1";else->fallback}
