package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

data class DeliveryProgress(val orderId:Int,val orderStatus:String,val pickedUpAt:String="",val routeStartedAt:String="",val arrivedAt:String="",val completedAt:String=""){
 val pickedUp:Boolean get()=pickedUpAt.isNotBlank();val routeStarted:Boolean get()=routeStartedAt.isNotBlank();val arrived:Boolean get()=arrivedAt.isNotBlank();val completed:Boolean get()=completedAt.isNotBlank()||orderStatus=="completed"
}
data class DeliveryLocationSample(val latitude:Double,val longitude:Double,val accuracyM:Double?=null,val speedMps:Double?=null,val bearingDeg:Double?=null,val capturedAtMs:Long,val batteryPct:Int?=null,val provider:String?=null,val isMock:Boolean=false)
data class DeliveryLocationUploadResult(val activeOrders:Int,val stored:Int)

class DeliveryProgressRepository(baseUrl:String,deviceId:String,private val sessionStore:SecureSessionStore){
 private val api=ApiClient(baseUrl,deviceId)
 suspend fun listMine():List<DeliveryProgress>{val array=api.getDelivery("list",requireToken()).optJSONArray("progress")?:JSONArray();return buildList{for(i in 0 until array.length()){val obj=array.optJSONObject(i)?:continue;parse(obj)?.let(::add)}}}
 suspend fun pickup(orderId:Int)=action("pickup",orderId)
 suspend fun startRoute(orderId:Int)=action("start-route",orderId)
 suspend fun arrive(orderId:Int)=action("arrive",orderId)
 suspend fun complete(orderId:Int)=action("complete",orderId)
 suspend fun sendLocationBatch(samples:List<DeliveryLocationSample>):DeliveryLocationUploadResult{if(samples.isEmpty())return DeliveryLocationUploadResult(0,0);val points=JSONArray();samples.takeLast(30).forEach{s->val p=JSONObject().put("latitude",s.latitude).put("longitude",s.longitude).put("captured_at",utc(s.capturedAtMs)).put("is_mock",s.isMock);s.accuracyM?.let{p.put("accuracy_m",it)};s.speedMps?.let{p.put("speed_mps",it)};s.bearingDeg?.let{p.put("bearing_deg",it)};s.batteryPct?.let{p.put("battery_pct",it)};s.provider?.takeIf{it.isNotBlank()}?.let{p.put("provider",it.take(32))};points.put(p)};val tracking=api.postDelivery("location-batch",requireToken(),JSONObject().put("points",points)).optJSONObject("tracking")?:JSONObject();return DeliveryLocationUploadResult(tracking.flexInt("active_orders"),tracking.flexInt("stored"))}
 suspend fun trackingLink(orderId:Int):String=clean(api.postDelivery("tracking-link",requireToken(),JSONObject().put("order_id",orderId)).opt("tracking_url"))
 private suspend fun action(action:String,orderId:Int):DeliveryProgress{require(orderId>0){"Pedido inválido."};val root=api.postDelivery(action,requireToken(),JSONObject().put("order_id",orderId));val progress=root.optJSONObject("progress")?:throw ApiException("O servidor não retornou o progresso da entrega.",502);return parse(progress)?:throw ApiException("O servidor retornou um progresso de entrega inválido.",502)}
 private fun parse(j:JSONObject):DeliveryProgress?{val id=j.flexInt("order_id");if(id<1)return null;return DeliveryProgress(id,clean(j.opt("order_status"),"ready"),clean(j.opt("picked_up_at")),clean(j.opt("route_started_at")),clean(j.opt("arrived_at")),clean(j.opt("completed_at")))}
 private fun clean(v:Any?,fallback:String=""):String{if(v==null||v==JSONObject.NULL)return fallback;val s=v.toString().trim();return if(s.isBlank()||s.equals("null",true)||s.equals("undefined",true))fallback else s}
 private fun JSONObject.flexInt(key:String,default:Int=0):Int=when(val v=opt(key)){is Number->v.toInt();is String->v.trim().toDoubleOrNull()?.toInt()?:default;else->default}
 private fun utc(ms:Long)=SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'",Locale.US).apply{timeZone=TimeZone.getTimeZone("UTC")}.format(Date(ms))
 private fun requireToken():String=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
