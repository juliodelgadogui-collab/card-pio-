package br.com.eventmenu.delivery

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URI
import java.net.URLEncoder
import java.nio.charset.StandardCharsets

class MarketplaceApi(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
) {
    private val endpoint = URI(baseUrl).resolve("api-marketplace.php").toString()

    suspend fun stores(query: String = "", city: String = "", state: String = ""): List<Store> = withContext(Dispatchers.IO) {
        val json = get("stores", mapOf("q" to query, "city" to city, "state" to state))
        json.array("stores").mapObjects(::store)
    }

    suspend fun catalog(store: Store): Catalog = withContext(Dispatchers.IO) {
        val json = get("catalog", mapOf("tenant_id" to store.tenantId.toString(), "unit_id" to store.unitId.toString()))
        val session = json.obj("checkout_session")
        Catalog(
            store = store(json.obj("store")),
            categories = json.array("categories").mapObjects { Category(it.int("id"), it.string("name")) },
            products = json.array("products").mapObjects(::product),
            checkoutSession = CheckoutSession(session.string("token"), session.string("expires_at"), session.optInt("expires_in", 0)),
        )
    }

    suspend fun createOrder(catalog: Catalog, cart: List<CartLine>, customer: CheckoutCustomer): ConsumerOrder = withContext(Dispatchers.IO) {
        val items = JSONArray()
        cart.forEach { line ->
            items.put(JSONObject().apply {
                put("product_id", line.product.id)
                put("quantity", line.quantity)
                put("option_ids", JSONArray(line.selectedOptionIds.toList()))
            })
        }
        val body = JSONObject().apply {
            put("entry_token", catalog.checkoutSession.token)
            put("items", items)
            put("name", customer.name.trim())
            put("phone", customer.phone.trim())
            put("address", customer.address.trim())
        }
        order(post("order-create", body).obj("order"))
    }

    suspend fun orderStatus(publicToken: String): ConsumerOrder = withContext(Dispatchers.IO) {
        order(get("order-status", mapOf("t" to publicToken)).obj("order"))
    }

    suspend fun tracking(token: String): TrackingStatus = withContext(Dispatchers.IO) {
        tracking(get("tracking", mapOf("token" to token)).obj("tracking"))
    }

    private fun get(action: String, params: Map<String, String>): JSONObject {
        val query = buildList {
            add("action=" + enc(action))
            params.forEach { (key, value) -> if (value.isNotBlank()) add(enc(key) + "=" + enc(value)) }
        }.joinToString("&")
        return request("GET", "$endpoint?$query", null)
    }

    private fun post(action: String, body: JSONObject): JSONObject =
        request("POST", "$endpoint?action=${enc(action)}", body.toString())

    private fun request(method: String, url: String, body: String?): JSONObject {
        val connection = (URI(url).toURL().openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 12_000
            readTimeout = 18_000
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", "EventMenuDelivery/${BuildConfig.VERSION_NAME}")
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }
        try {
            if (body != null) connection.outputStream.bufferedWriter(StandardCharsets.UTF_8).use { it.write(body) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val raw = stream?.let { BufferedReader(InputStreamReader(it, StandardCharsets.UTF_8)).use(BufferedReader::readText) }.orEmpty()
            val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
            if (status !in 200..299 || !json.optBoolean("ok", false)) {
                throw MarketplaceException(json.optString("message").ifBlank { friendlyHttp(status) }, status)
            }
            return json
        } catch (e: MarketplaceException) {
            throw e
        } catch (e: java.net.SocketTimeoutException) {
            throw MarketplaceException("A conexão demorou mais que o esperado. Tente novamente.")
        } catch (e: java.io.IOException) {
            throw MarketplaceException("Não foi possível conectar agora. Confira sua internet e tente novamente.")
        } finally {
            connection.disconnect()
        }
    }

    private fun friendlyHttp(status: Int): String = when (status) {
        404 -> "Não encontramos esta informação."
        409 -> "Esta compra precisa ser atualizada antes de continuar."
        422 -> "Revise os dados e tente novamente."
        429 -> "Muitas tentativas em pouco tempo. Aguarde um momento."
        in 500..599 -> "O EventMenu está temporariamente indisponível. Tente novamente."
        else -> "Não foi possível concluir agora. Tente novamente."
    }

    private fun store(j: JSONObject) = Store(
        tenantId = j.int("tenant_id"), unitId = j.int("unit_id"), name = j.string("name"),
        description = j.optString("description"), unitName = j.optString("unit_name"), city = j.optString("city"),
        state = j.optString("state"), address = j.optString("address"), logoUrl = j.optString("logo_url"),
        coverUrl = j.optString("cover_url"), deliveryFeeCents = j.optInt("delivery_fee_cents", 0),
        minimumOrderCents = j.optInt("minimum_order_cents", 0),
    )

    private fun product(j: JSONObject): Product = Product(
        id = j.int("id"),
        categoryId = if (j.isNull("category_id")) null else j.optInt("category_id").takeIf { it > 0 },
        name = j.string("name"), description = j.optString("description"), priceCents = j.optInt("price_cents"),
        imageUrl = j.optString("image_url"), available = j.optBoolean("available", true),
        modifierGroups = j.optJSONArray("modifier_groups").orEmpty().mapObjects { group ->
            ModifierGroup(
                id = group.int("id"), name = group.string("name"), required = group.optBoolean("required", false),
                minSelect = group.optInt("min_select", 0), maxSelect = group.optInt("max_select", 1),
                options = group.optJSONArray("options").orEmpty().mapObjects { option ->
                    ModifierOption(option.int("id"), option.string("name"), option.optInt("price_delta_cents", 0))
                },
            )
        },
    )

    private fun order(j: JSONObject): ConsumerOrder = ConsumerOrder(
        orderNumber = j.int("order_number"), publicToken = j.string("public_token"), storeName = j.string("store_name"),
        unitName = j.optString("unit_name"), status = j.string("status"), statusLabel = j.string("status_label"),
        paymentStatus = j.string("payment_status"), paymentStatusLabel = j.string("payment_status_label"),
        subtotalCents = j.optInt("subtotal_cents"), discountCents = j.optInt("discount_cents"),
        deliveryFeeCents = j.optInt("delivery_fee_cents"), totalCents = j.optInt("total_cents"),
        createdAt = j.optString("created_at"),
        timeline = j.optJSONArray("timeline").orEmpty().mapObjects { TimelineStep(it.string("key"), it.string("label"), it.optBoolean("done"), it.optBoolean("current")) },
        items = j.optJSONArray("items").orEmpty().mapObjects { item ->
            OrderItem(
                id = item.int("id"), name = item.string("name_snapshot"), unitPriceCents = item.optInt("unit_price_cents"),
                quantity = item.optDouble("quantity", 0.0), totalCents = item.optInt("total_cents"),
                modifiers = item.optJSONArray("modifiers").orEmpty().mapObjects { mod -> "${mod.optString("group")}: ${mod.optString("name")}" },
            )
        },
        tracking = j.optJSONObject("tracking")?.let { TrackingReference(it.optBoolean("active"), it.string("token"), it.optString("expires_at").ifBlank { null }) },
    )

    private fun tracking(j: JSONObject): TrackingStatus = TrackingStatus(
        active = j.optBoolean("tracking_active"), status = j.string("status"), statusLabel = j.string("status_label"),
        location = j.optJSONObject("location")?.let { TrackingLocation(it.optDouble("latitude"), it.optDouble("longitude"), if (it.isNull("accuracy_m")) null else it.optDouble("accuracy_m"), it.optString("recorded_at").ifBlank { null }) },
        routeStartedAt = j.optString("route_started_at").ifBlank { null }, arrivedAt = j.optString("arrived_at").ifBlank { null },
        completedAt = j.optString("completed_at").ifBlank { null }, expiresAt = j.optString("expires_at").ifBlank { null },
    )

    private fun enc(value: String): String = URLEncoder.encode(value, StandardCharsets.UTF_8.name())
}

class MarketplaceException(message: String, val statusCode: Int? = null) : RuntimeException(message)

private fun JSONObject.string(key: String): String = optString(key).takeIf { it.isNotBlank() } ?: throw MarketplaceException("Não foi possível carregar esta informação.")
private fun JSONObject.int(key: String): Int = optInt(key, Int.MIN_VALUE).takeIf { it != Int.MIN_VALUE } ?: throw MarketplaceException("Não foi possível carregar esta informação.")
private fun JSONObject.obj(key: String): JSONObject = optJSONObject(key) ?: throw MarketplaceException("Não foi possível carregar esta informação.")
private fun JSONObject.array(key: String): JSONArray = optJSONArray(key) ?: JSONArray()
private fun JSONArray?.orEmpty(): JSONArray = this ?: JSONArray()
private inline fun <T> JSONArray.mapObjects(block: (JSONObject) -> T): List<T> = buildList {
    for (i in 0 until length()) optJSONObject(i)?.let { add(block(it)) }
}
