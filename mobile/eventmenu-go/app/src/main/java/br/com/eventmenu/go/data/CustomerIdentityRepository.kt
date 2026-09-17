package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

class CustomerIdentityRepository(baseUrl:String,deviceId:String,private val sessionStore:SecureSessionStore){
 private val api=ApiClient(baseUrl,deviceId)
 suspend fun lookup(phone:String):CustomerIdentity?{
  val digits=phone.filter(Char::isDigit);if(digits.length<8)return null
  val root=api.get("customer-lookup",token(),mapOf("phone" to digits));if(!root.optBoolean("found",false))return null
  return parse(root.optJSONObject("customer")?:return null)
 }
 suspend fun save(id:Int?,name:String,phone:String,address:String,email:String=""):CustomerIdentity{
  val body=JSONObject().put("name",name.trim()).put("phone",phone.trim()).put("address",address.trim()).put("email",email.trim());id?.takeIf{it>0}?.let{body.put("customer_id",it)}
  val root=api.post("customer-save",token(),body);return parse(root.optJSONObject("customer")?:throw ApiException("O servidor não retornou o cliente salvo.",502))
 }
 private fun parse(j:JSONObject)=CustomerIdentity(id=j.optInt("id"),name=clean(j.opt("name")),phone=clean(j.opt("phone")),address=clean(j.opt("default_address")),points=flexInt(j.opt("points")),email=clean(j.opt("email")))
 private fun clean(v:Any?)=if(v==null||v==JSONObject.NULL||v.toString().equals("null",true))"" else v.toString().trim()
 private fun flexInt(v:Any?)=when(v){is Number->v.toInt();is String->v.toDoubleOrNull()?.toInt()?:0;else->0}
 private fun token()=sessionStore.token()?:throw ApiException("Sessão não encontrada.",401)
}
