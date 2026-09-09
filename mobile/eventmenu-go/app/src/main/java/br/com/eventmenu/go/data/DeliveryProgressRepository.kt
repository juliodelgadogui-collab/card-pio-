package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant

data class DeliveryProgress(val orderId:Int,val orderStatus:String,val pickedUpAt:String="",val routeStartedAt:String="",val arrivedAt:String="",val completedAt:String=""){
    val pickedUp:Boolean get()=pickedUpAt.isNotBlank();val routeStarted:Boolean get()=routeStartedAt.isNotBlank();val arrived:Boolean get()=arrivedAt.isNotBlank();val completed:Boolean get()=completedAt.isNotBlank()||orderStatus=="completed"
}
class DeliveryProgressRepository(baseUrl:String,deviceId:String,private val sessionStore:SecureSessionStore){
    private val api=ApiClient(baseUrl,deviceId)
    suspend fun listMine():List<DeliveryProgress>{val array=api.getDelivery("list",requireToken()).optJSONArray("progress")?:JSONArray();return buildList{for(i in 0 until array.length())add(parse(array.getJSONObject(i)))}}
    suspend fun pickup(orderId:Int)=action("pickup",orderId);suspend fun startRoute(orderId:Int)=action("start-route",orderId);suspend fun arrive(orderId:Int)=action("arrive",orderId)
    suspend fun complete(orderId:Int):DeliveryProgress{val root=api.postDelivery("complete",requireToken(),JSONObject().put("order_id",orderId));return parse(root.getJSONObject("progress"))}
    suspend fun sendLocationForActiveRoutes(latitude:Double,longitude:Double,accuracy:Double?,speed:Double?,bearing:Double?,capturedAtMs:Long){
        val active=listMine().filter{it.routeStarted&&!it.completed&&it.orderStatus=="out_for_delivery"};if(active.isEmpty())return
        active.forEach{p->val body=JSONObject().put("order_id",p.orderId).put("latitude",latitude).put("longitude",longitude).put("captured_at",Instant.ofEpochMilli(capturedAtMs).toString());accuracy?.let{body.put("accuracy_m",it)};speed?.let{body.put("speed_mps",it)};bearing?.let{body.put("bearing_deg",it)};api.postDelivery("location",requireToken(),body)}
    }
    suspend fun trackingLink(orderId:Int):String=api.postDelivery("tracking-link",requireToken(),JSONObject().put("order_id",orderId)).optString("tracking_url")
    private suspend fun action(action:String,orderId:Int):DeliveryProgress{val root=api.postDelivery(action,requireToken(),JSONObject().put("order_id",orderId));return parse(root.getJSONObject("progress"))}
    private fun parse(j:JSONObject)=DeliveryProgress(j.optInt("order_id"),j.optString("order_status"),j.optString("picked_up_at"),j.optString("route_started_at"),j.optString("arrived_at"),j.optString("completed_at"))
    private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}