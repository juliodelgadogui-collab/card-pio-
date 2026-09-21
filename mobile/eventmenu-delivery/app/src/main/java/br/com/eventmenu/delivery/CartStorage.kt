package br.com.eventmenu.delivery

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class SavedCartLine(val productId: Int, val quantity: Int, val optionIds: Set<Int>)
data class SavedCart(val tenantId: Int, val unitId: Int, val lines: List<SavedCartLine>)

class CartStorage(context: Context) {
    private val prefs = context.getSharedPreferences("eventmenu_delivery", Context.MODE_PRIVATE)

    fun save(catalog: Catalog?, cart: List<CartLine>) {
        if (catalog == null || cart.isEmpty()) {
            clear()
            return
        }
        val lines = JSONArray()
        cart.forEach { line ->
            lines.put(JSONObject().apply {
                put("product_id", line.product.id)
                put("quantity", line.quantity)
                put("option_ids", JSONArray(line.selectedOptionIds.toList()))
            })
        }
        val json = JSONObject().apply {
            put("tenant_id", catalog.store.tenantId)
            put("unit_id", catalog.store.unitId)
            put("lines", lines)
        }
        prefs.edit().putString(KEY, json.toString()).apply()
    }

    fun load(): SavedCart? {
        val raw = prefs.getString(KEY, null)?.takeIf { it.isNotBlank() } ?: return null
        return runCatching {
            val json = JSONObject(raw)
            val tenantId = json.optInt("tenant_id")
            val unitId = json.optInt("unit_id")
            if (tenantId < 1 || unitId < 1) return@runCatching null
            val array = json.optJSONArray("lines") ?: JSONArray()
            val lines = buildList {
                for (i in 0 until array.length()) {
                    val row = array.optJSONObject(i) ?: continue
                    val productId = row.optInt("product_id")
                    val quantity = row.optInt("quantity", 1).coerceIn(1, 99)
                    if (productId < 1) continue
                    val options = row.optJSONArray("option_ids") ?: JSONArray()
                    val ids = buildSet {
                        for (j in 0 until options.length()) options.optInt(j).takeIf { it > 0 }?.let(::add)
                    }
                    add(SavedCartLine(productId, quantity, ids))
                }
            }
            if (lines.isEmpty()) null else SavedCart(tenantId, unitId, lines)
        }.getOrNull()
    }

    fun clear() {
        prefs.edit().remove(KEY).apply()
    }

    private companion object { const val KEY = "draft_cart_v1" }
}
