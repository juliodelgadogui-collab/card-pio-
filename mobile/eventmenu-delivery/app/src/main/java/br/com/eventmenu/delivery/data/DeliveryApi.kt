package br.com.eventmenu.delivery.data

import br.com.eventmenu.delivery.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class ApiException(message: String, val code: String = "") : RuntimeException(message)
data class LoginResult(val token: String, val customer: Customer)
data class RegisterResult(val email: String, val emailSent: Boolean)

class DeliveryApi(private val tokenProvider: () -> String?) {
    private val base = BuildConfig.API_BASE_URL

    suspend fun register(name:String,email:String,phone:String,password:String,legalAccepted:Boolean):RegisterResult = register(name,"",email,phone,password,legalAccepted)
    suspend fun register(name:String,cpf:String,email:String,phone:String,password:String,legalAccepted:Boolean):RegisterResult = request(
        "api-delivery-customer.php?action=register","POST",
        JSONObject().put("name",name).put("cpf",cpf).put("email",email).put("phone",phone).put("password",password).put("terms_accepted",legalAccepted).put("privacy_accepted",legalAccepted)
    ){root->RegisterResult(root.optString("email"),root.optBoolean("email_sent"))}
    suspend fun resend(email:String){requestUnit("api-delivery-customer.php?action=resend-verification","POST",JSONObject().put("email",email))}
    suspend fun login(email:String,password:String):LoginResult=request("api-delivery-customer.php?action=login","POST",JSONObject().put("email",email).put("password",password).put("device_name",android.os.Build.MODEL)){root->LoginResult(root.getString("access_token"),customer(root.getJSONObject("customer")))}
    suspend fun forgotPassword(email:String){requestUnit("api-delivery-customer.php?action=forgot-password","POST",JSONObject().put("email",email))}
    suspend fun logout(pushToken:String=""){requestUnit("api-delivery-customer.php?action=logout","POST",JSONObject().put("push_token",pushToken))}
    suspend fun me():Customer=request("api-delivery-customer.php?action=me"){customer(it.getJSONObject("customer"))}
    suspend fun paymentIdentification():String=request("api-delivery-customer.php?action=payment-identification"){it.getJSONObject("identification").getString("number")}
    suspend fun registerPush(pushToken:String,deviceIdHash:String=""){if(pushToken.isBlank())return;requestUnit("api-delivery-customer.php?action=push-register","POST",JSONObject().put("push_token",pushToken).put("platform","android").put("device_id_hash",deviceIdHash))}
    suspend fun saveProfile(name:String,phone:String):Customer=saveProfile(name,phone,"")
    suspend fun saveProfile(name:String,phone:String,cpf:String):Customer=request("api-delivery-customer.php?action=profile-save","POST",JSONObject().put("name",name).put("phone",phone).apply{if(cpf.isNotBlank())put("cpf",cpf)}){customer(it.getJSONObject("customer"))}

    suspend fun saveAddress(address:Address):Address{val body=JSONObject().put("id",address.id).put("label",address.label).put("street",address.street).put("number",address.number).put("complement",address.complement).put("neighborhood",address.neighborhood).put("city",address.city).put("state",address.state).put("postal_code",address.postalCode).put("reference",address.reference).put("phone",address.phone).put("is_default",address.isDefault);address.latitude?.let{body.put("latitude",it)};address.longitude?.let{body.put("longitude",it)};return request("api-delivery-customer.php?action=address-save","POST",body){address(it.getJSONObject("address"))}}
    suspend fun deleteAddress(id:Int){requestUnit("api-delivery-customer.php?action=address-delete","POST",JSONObject().put("id",id))}
    suspend fun stores(query:String=""): List<Store> {
        val path = if (tokenProvider().isNullOrBlank())
            "api-marketplace.php?action=stores&q=${encode(query)}"
        else
            "api-delivery-customer.php?action=stores&q=${encode(query)}"
        return if (tokenProvider().isNullOrBlank())
            requestPublic(path){root->root.optJSONArray("stores").toObjects(::store)}
        else
            request(path){root->root.optJSONArray("stores").toObjects(::store)}
    }
    suspend fun catalog(tenantId:Int,unitId:Int):Catalog=requestPublic("api-marketplace.php?action=catalog&tenant_id=$tenantId&unit_id=$unitId"){root->val checkout=root.getJSONObject("checkout_session");Catalog(store(root.getJSONObject("store")),root.optJSONArray("categories").toObjects{Category(it.getInt("id"),it.optString("name"))},root.optJSONArray("products").toObjects(::product),checkout.getString("token"))}
    suspend fun favorite(tenantId:Int,value:Boolean){requestUnit("api-delivery-customer.php?action=favorite","POST",JSONObject().put("tenant_id",tenantId).put("favorite",value))}
    suspend fun couponQuote(tenantId:Int,code:String,subtotalCents:Int):CouponQuote{val normalized=code.trim().uppercase();return request("api-delivery-customer.php?action=coupon-quote","POST",JSONObject().put("tenant_id",tenantId).put("coupon_code",normalized).put("code",normalized).put("subtotal_cents",subtotalCents)){root->val c=root.getJSONObject("coupon");CouponQuote(c.optString("code"),c.optInt("discount_cents"),c.optInt("min_order_cents"),if(c.isNull("max_discount_cents"))null else c.optInt("max_discount_cents"))}}
    suspend fun createOrder(catalog:Catalog,addressId:Int,items:List<CartItem>,couponCode:String=""):OrderSummary{val lines=JSONArray();items.forEach{item->lines.put(JSONObject().put("product_id",item.product.id).put("quantity",item.quantity).put("option_ids",JSONArray(item.optionIds.toList())).put("notes",item.notes))};val body=JSONObject().put("entry_token",catalog.entryToken).put("address_id",addressId).put("items",lines);if(couponCode.isNotBlank())body.put("coupon_code",couponCode.trim().uppercase());return request("api-delivery-customer.php?action=order-create","POST",body){order(it.getJSONObject("order"))}}
    suspend fun orders(): List<OrderSummary> = request("api-delivery-customer.php?action=orders"){root->root.optJSONArray("orders").toObjects(::order)}
    suspend fun order(id:Int):OrderSummary=request("api-delivery-customer.php?action=order&order_id=$id"){order(it.getJSONObject("order"))}
    suspend fun reorder(id:Int):JSONObject=request("api-delivery-customer.php?action=reorder","POST",JSONObject().put("order_id",id)){it.getJSONObject("reorder")}
    suspend fun review(id:Int,rating:Int,comment:String){requestUnit("api-delivery-customer.php?action=review","POST",JSONObject().put("order_id",id).put("rating",rating).put("comment",comment))}
    suspend fun paymentMethods(orderId:Int):PaymentMethods=request("api-delivery-payment-methods.php?order_id=$orderId"){root->val m=root.getJSONObject("methods");val pix=m.optJSONArray("pix").toObjects{it.optString("provider")}.filter{it.isNotBlank()};val cards=m.optJSONArray("card").toObjects{row->CardMethod(row.optString("provider"),row.optString("public_key"),row.optInt("max_installments",12),row.optJSONArray("payment_types").toStrings().filter{it=="credit_card"||it=="debit_card"}.toSet().ifEmpty{setOf("credit_card","debit_card")})};PaymentMethods(pix,cards,m.optBoolean("cash"))}
    suspend fun pix(orderId:Int,provider:String,taxId:String):PixPayment=request("api-delivery-customer.php?action=payment-pix","POST",JSONObject().put("order_id",orderId).put("provider",provider)){root->val p=root.getJSONObject("payment");PixPayment(p.getInt("payment_id"),p.optString("provider"),p.optString("copy_paste"),p.optString("image_url"),p.optString("expires_at"))}
    suspend fun card(orderId:Int,token:String,paymentMethodId:String,paymentTypeId:String,installments:Int,taxId:String,issuerId:String?=null):String=request("api-delivery-customer.php?action=payment-card","POST",JSONObject().put("order_id",orderId).put("provider","mercadopago").put("card_token",token).put("payment_method_id",paymentMethodId).put("payment_type_id",paymentTypeId).put("installments",installments).apply{issuerId?.takeIf{it.isNotBlank()}?.let{put("issuer_id",it)}}){it.getJSONObject("payment").optString("status")}
    suspend fun cash(orderId:Int,changeForCents:Int?){requestUnit("api-delivery-customer.php?action=payment-cash","POST",JSONObject().put("order_id",orderId).apply{if(changeForCents==null)put("change_for_cents",JSONObject.NULL)else put("change_for_cents",changeForCents)})}
    suspend fun paymentStatus(orderId:Int):JSONObject=request("api-delivery-customer.php?action=payment-status&order_id=$orderId"){it.getJSONObject("payment")}
    suspend fun tracking(token:String):TrackingStatus=requestPublic("api-marketplace.php?action=tracking&token=${encode(token)}"){root->val data=root.optJSONObject("tracking")?:root;val loc=data.optJSONObject("location");TrackingStatus(data.optBoolean("tracking_active"),data.optString("status"),data.optString("status_label"),loc?.optDoubleOrNull("latitude"),loc?.optDoubleOrNull("longitude"),loc?.optString("recorded_at"))}

    private suspend fun requestUnit(path:String,method:String="GET",body:JSONObject?=null){request(path,method,body){Unit}}
    private suspend fun <T> request(path:String,method:String="GET",body:JSONObject?=null,parser:(JSONObject)->T):T=withContext(Dispatchers.IO){execute(path,method,body,tokenProvider(),parser)}
    private suspend fun <T> requestPublic(path:String,parser:(JSONObject)->T):T=withContext(Dispatchers.IO){execute(path,"GET",null,null,parser)}
    private fun <T> execute(path:String,method:String,body:JSONObject?,token:String?,parser:(JSONObject)->T):T{val connection=(URL(base+path).openConnection()as HttpURLConnection).apply{requestMethod=method;connectTimeout=10_000;readTimeout=25_000;useCaches=false;setRequestProperty("Accept","application/json");setRequestProperty("Content-Type","application/json; charset=utf-8");if(!token.isNullOrBlank())setRequestProperty("Authorization","Bearer $token");if(body!=null){doOutput=true;outputStream.use{it.write(body.toString().toByteArray(Charsets.UTF_8))}}};try{val status=connection.responseCode;val text=(if(status in 200..299)connection.inputStream else connection.errorStream)?.bufferedReader()?.use{it.readText()}.orEmpty();val root=runCatching{JSONObject(text)}.getOrElse{throw ApiException("Resposta inválida do servidor.","INVALID_RESPONSE")};if(status !in 200..299||!root.optBoolean("ok",true))throw ApiException(root.optString("message",root.optString("error","Não foi possível concluir.")),root.optString("code"));return parser(root)}finally{connection.disconnect()}}

    private fun customer(o:JSONObject):Customer=Customer(id=o.getInt("id"),name=o.optString("name"),email=o.optString("email"),phone=o.optString("phone"),emailVerified=o.optBoolean("email_verified"),cpfConfigured=o.optBoolean("cpf_configured"),cpfMasked=o.optString("cpf_masked"),addresses=o.optJSONArray("addresses").toObjects(::address))
    private fun address(o:JSONObject):Address=Address(o.optInt("id"),o.optString("label","Casa"),o.optString("street"),o.optString("number"),o.optString("complement"),o.optString("neighborhood"),o.optString("city"),o.optString("state"),o.optString("postal_code"),o.optString("reference"),o.optString("phone"),o.optDoubleOrNull("latitude"),o.optDoubleOrNull("longitude"),o.optBoolean("is_default"))
    private fun store(o:JSONObject):Store=Store(tenantId=o.optInt("tenant_id"),unitId=o.optInt("unit_id"),name=o.optString("name"),description=o.optString("description"),city=o.optString("city"),state=o.optString("state"),logoUrl=o.optString("logo_url"),coverUrl=o.optString("cover_url"),deliveryFeeCents=o.optInt("delivery_fee_cents"),minimumOrderCents=o.optInt("minimum_order_cents"),favorite=o.optBoolean("favorite"),acceptingOrders=o.optBoolean("accepting_orders",true),deliveryEtaMinutes=o.optInt("delivery_eta_minutes",45),deliveryRadiusKm=o.optDouble("delivery_radius_km",0.0),pickupEnabled=o.optBoolean("pickup_enabled"),scheduleNote=o.optString("schedule_note"))
    private fun product(o:JSONObject):Product=Product(o.getInt("id"),if(o.isNull("category_id"))null else o.optInt("category_id"),o.optString("name"),o.optString("description"),o.optInt("price_cents"),o.optString("image_url"),o.optBoolean("available",true),o.optJSONArray("modifier_groups").toObjects{g->ModifierGroup(g.getInt("id"),g.optString("name"),g.optBoolean("required"),g.optInt("min_select"),g.optInt("max_select",1),g.optJSONArray("options").toObjects{x->ModifierOption(x.getInt("id"),x.optString("name"),x.optInt("price_delta_cents"))})})
    private fun order(o:JSONObject):OrderSummary{val tracking=o.optJSONObject("tracking");return OrderSummary(o.optInt("order_number"),o.optString("public_token"),o.optString("store_name"),o.optString("status"),o.optString("status_label"),o.optString("payment_status"),o.optInt("total_cents"),tracking?.optString("token")?.takeIf{it.isNotBlank()})}
    private fun encode(v:String):String=java.net.URLEncoder.encode(v,"UTF-8")
}

private fun <T> JSONArray?.toObjects(mapper:(JSONObject)->T):List<T>{if(this==null)return emptyList();val out=ArrayList<T>(length());for(i in 0 until length())optJSONObject(i)?.let{out+=mapper(it)};return out}
private fun JSONArray?.toStrings():List<String>{if(this==null)return emptyList();val out=ArrayList<String>(length());for(i in 0 until length())optString(i).takeIf{it.isNotBlank()}?.let(out::add);return out}
private fun JSONObject.optDoubleOrNull(key:String):Double?=if(!has(key)||isNull(key))null else optDouble(key)