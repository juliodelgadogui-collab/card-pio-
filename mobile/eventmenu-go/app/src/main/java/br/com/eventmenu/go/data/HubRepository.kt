package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject
import java.util.UUID

data class HubPeripheralStatus(val defaultPrinter:String="",val cashDrawer:String="",val scale:String="",val barcodeScanner:String="",val customerDisplay:String="",val tefProvider:String="",val pinpad:String="",val printers:List<String> = emptyList())
data class HubLink(val id:Int,val unitId:Int,val unitName:String,val desktopBindingId:Int,val desktopLabel:String,val online:Boolean,val lastSeenAt:String,val hardware:HubPeripheralStatus)
data class HubTerminal(val id:Int,val unitId:Int,val provider:String,val label:String,val pinpad:String)
data class HubCommand(val id:Int,val commandType:String,val status:String,val error:String="",val createdAt:String="",val completedAt:String="",val approvedLocal:Boolean?=null,val verified:Boolean?=null,val resultMessage:String="",val weightGrams:Int?=null,val weightStable:Boolean?=null,val scaleLabel:String="")

class HubRepository(baseUrl:String,private val deviceId:String,private val sessionStore:SecureSessionStore){
 private val api=ApiClient(baseUrl,deviceId,sessionStore)
 suspend fun links():List<HubLink>{val root=api.getHub("links",requireToken(),mapOf("device_id" to deviceId));val array=root.optJSONArray("links")?:return emptyList();return buildList{for(i in 0 until array.length()){val item=array.optJSONObject(i)?:continue;val hardware=item.optJSONObject("hardware")?:JSONObject();val pj=hardware.optJSONArray("printers");val printers=buildList{if(pj!=null)for(p in 0 until pj.length())pj.optString(p).takeIf{it.isNotBlank()}?.let(::add)};add(HubLink(item.optInt("id"),item.optInt("unit_id"),item.optString("unit_name"),item.optInt("desktop_binding_id"),item.optString("desktop_label","EventMenu Desktop"),item.optBoolean("desktop_online",false),item.optString("desktop_last_seen_at"),HubPeripheralStatus(hardware.optString("default_printer"),hardware.optString("cash_drawer"),hardware.optString("scale"),hardware.optString("barcode_scanner"),hardware.optString("customer_display"),hardware.optString("tef_provider"),hardware.optString("pinpad"),printers)))}}}
 suspend fun terminals(unitId:Int):List<HubTerminal>{val a=api.getHub("terminals",requireToken(),mapOf("unit_id" to unitId.toString())).optJSONArray("terminals")?:return emptyList();return buildList{for(i in 0 until a.length()){val x=a.optJSONObject(i)?:continue;add(HubTerminal(x.optInt("id"),x.optInt("unit_id"),x.optString("provider"),x.optString("label","PINPad"),x.optString("pinpad_identifier")))}}}
 suspend fun claimPairing(qrOrToken:String,label:String):HubLink{val link=api.postHub("pairing-claim",requireToken(),JSONObject().put("device_id",deviceId).put("qr",qrOrToken.trim()).put("label",label.take(190))).getJSONObject("link");return HubLink(link.optInt("id"),link.optInt("unit_id"),"",link.optInt("desktop_binding_id"),link.optString("desktop_label","EventMenu Desktop"),true,"",HubPeripheralStatus())}
 suspend fun printOrder(link:HubLink,orderId:Int)=queue(link,"print_order",JSONObject().put("order_id",orderId),"order:$orderId")
 suspend fun printReceipt(link:HubLink,orderId:Int)=queue(link,"print_receipt",JSONObject().put("order_id",orderId),"receipt:$orderId")
 suspend fun openDrawer(link:HubLink,reason:String)=queue(link,"open_drawer",JSONObject().put("reason",reason.take(300)))
 suspend fun showCustomerDisplay(link:HubLink,orderId:Int)=queue(link,"customer_display",JSONObject().put("order_id",orderId),"display:$orderId")
 suspend fun readScale(link:HubLink,purpose:String="sale")=queue(link,"scale_read",JSONObject().put("purpose",purpose.take(40)))
 suspend fun chargeTef(link:HubLink,orderId:Int,terminalConfigId:Int,amountCents:Int,paymentType:String,installments:Int=1)=queue(link,"tef_charge",JSONObject().put("order_id",orderId).put("terminal_config_id",terminalConfigId).put("amount_cents",amountCents).put("payment_type",paymentType).put("installments",installments.coerceIn(1,24)),"tef:$orderId:$terminalConfigId:$amountCents:$paymentType:$installments")
 suspend fun playAlert(link:HubLink,message:String)=queue(link,"play_alert",JSONObject().put("message",message.take(300)))
 suspend fun commandStatus(id:Int)=parseCommand(api.getHub("command-status",requireToken(),mapOf("id" to id.toString(),"device_id" to deviceId)).getJSONObject("command"))
 suspend fun revokeLink(linkId:Int){api.postHub("link-revoke",requireToken(),JSONObject().put("id",linkId).put("device_id",deviceId))}
 private suspend fun queue(link:HubLink,type:String,payload:JSONObject,operationKey:String=UUID.randomUUID().toString()):HubCommand{if(!link.online)throw ApiException("O computador EventMenu está offline.");val key="mobile-hub:${link.desktopBindingId}:$type:$operationKey";return parseCommand(api.postHub("command-create",requireToken(),JSONObject().put("target_binding_id",link.desktopBindingId).put("command_type",type).put("payload",payload).put("idempotency_key",key).put("device_id",deviceId)).getJSONObject("command"))}
 private fun parseCommand(item:JSONObject):HubCommand{val r=item.optJSONObject("result");return HubCommand(item.optInt("id"),item.optString("command_type"),item.optString("status"),item.optString("error_message"),item.optString("created_at"),item.optString("completed_at"),if(r?.has("approved_local")==true)r.optBoolean("approved_local")else null,if(r?.has("verified")==true)r.optBoolean("verified")else null,r?.optString("message").orEmpty(),if(r?.has("weight_grams")==true)r.optInt("weight_grams")else null,if(r?.has("stable")==true)r.optBoolean("stable")else null,r?.optString("scale").orEmpty())}
 private fun requireToken():String=sessionStore.token()?.takeIf{it.isNotBlank()}?:throw ApiException("Faça login novamente.",401)
}
