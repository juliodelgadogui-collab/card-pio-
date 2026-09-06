package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

class EventBarRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun catalog(): List<Product> {
        val array = api.getGo("catalog", requireToken()).optJSONArray("products") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val p = array.getJSONObject(i)
                add(
                    Product(
                        id = p.getInt("id"),
                        categoryId = if (p.isNull("category_id")) null else p.optInt("category_id"),
                        categoryName = p.optString("category_name").ifBlank { "Sem categoria" },
                        name = p.optString("name"),
                        description = p.optString("description"),
                        priceCents = p.optInt("price_cents"),
                        stockQty = p.optDouble("stock_qty", 0.0),
                        trackStock = p.optInt("track_stock", 0) == 1,
                        imageUrl = p.optString("image_url"),
                    )
                )
            }
        }
    }

    suspend fun create(eventId: Int, cart: Map<Int, Int>, notes: String = ""): CreatedOrder {
        if (eventId < 1) throw ApiException("Evento inválido.")
        if (cart.isEmpty()) throw ApiException("Carrinho vazio.")
        val items = JSONArray()
        cart.filterValues { it > 0 }.forEach { (productId, qty) ->
            items.put(JSONObject().put("product_id", productId).put("quantity", qty))
        }
        val body = JSONObject()
            .put("channel", "bar")
            .put("event_id", eventId)
            .put("notes", notes.trim())
            .put("items", items)
        val o = api.post("order-create", requireToken(), body).getJSONObject("order")
        return CreatedOrder(o.getInt("id"), o.optString("public_token"), o.optString("channel"), o.getInt("total_cents"))
    }

    suspend fun balance(orderId: Int): PaymentBalance {
        val p = api.getGo("payment-status", requireToken(), mapOf("order_id" to orderId.toString())).getJSONObject("payment")
        val a = p.optJSONArray("payments") ?: JSONArray()
        val parts = buildList {
            for (i in 0 until a.length()) {
                val x = a.getJSONObject(i)
                add(PaymentPart(x.getInt("id"), x.optString("provider"), x.optInt("amount_cents"), x.optString("status"), x.optString("verified_at")))
            }
        }
        return PaymentBalance(p.getInt("order_id"), p.getInt("total_cents"), p.optInt("paid_cents"), p.optInt("remaining_cents"), p.optString("payment_status"), parts)
    }

    suspend fun cash(orderId: Int, amountCents: Int): PaymentBalance {
        val body = JSONObject()
            .put("order_id", orderId)
            .put("amount_cents", amountCents)
            .put("idempotency_key", "go-event-bar-cash-${UUID.randomUUID()}")
        val p = api.postGo("payment-cash", requireToken(), body).getJSONObject("payment")
        val a = p.optJSONArray("payments") ?: JSONArray()
        val parts = buildList {
            for (i in 0 until a.length()) {
                val x = a.getJSONObject(i)
                add(PaymentPart(x.getInt("id"), x.optString("provider"), x.optInt("amount_cents"), x.optString("status"), x.optString("verified_at")))
            }
        }
        return PaymentBalance(p.getInt("order_id"), p.getInt("total_cents"), p.optInt("paid_cents"), p.optInt("remaining_cents"), p.optString("payment_status"), parts)
    }

    suspend fun pix(orderId: Int, amountCents: Int, taxId: String): PixCharge {
        val p = api.postGo(
            "pix-create",
            requireToken(),
            JSONObject().put("order_id", orderId).put("amount_cents", amountCents).put("tax_id", taxId),
        ).getJSONObject("pix")
        return PixCharge(p.getInt("payment_id"), p.getInt("order_id"), p.getInt("amount_cents"), p.getString("copy_paste"), p.optString("expires_at"))
    }

    suspend fun nfc(orderId: Int, amountCents: Int): TapOnRequest {
        val j = api.postGo("nfc-intent", requireToken(), JSONObject().put("order_id", orderId).put("amount_cents", amountCents))
        val t = j.getJSONObject("tap_on")
        return TapOnRequest(
            intentToken = j.getString("intent_token"),
            orderId = j.getInt("order_id"),
            amountCents = j.getInt("amount_cents"),
            appKey = t.getString("app_key"),
            appName = t.optString("app_name", "EventMenu GO"),
            appVersion = t.optString("app_version", "1.0.0"),
            enableTaxPassThrough = t.optBoolean("enable_tax_pass_through", false),
        )
    }

    suspend fun verifyNfc(intentToken: String, transactionCode: String): PaymentBalance {
        val result = api.postGo("nfc-verify", requireToken(), JSONObject().put("intent_token", intentToken).put("transaction_code", transactionCode))
        val orderId = result.optInt("order_id")
        if (orderId < 1) throw ApiException("Pagamento NFC confirmado sem pedido válido.")
        return balance(orderId)
    }

    suspend fun complete(orderId: Int) {
        api.postGo("order-status", requireToken(), JSONObject().put("order_id", orderId).put("status", "completed"))
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
