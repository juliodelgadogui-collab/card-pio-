package br.com.eventmenu.delivery.data

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class SavedCart(
    val tenantId: Int,
    val unitId: Int,
    val items: List<SavedCartItem>,
)

data class SavedCartItem(
    val productId: Int,
    val quantity: Int,
    val optionIds: Set<Int>,
    val notes: String,
)

class CartPersistence(context: Context) {
    private val prefs = context.getSharedPreferences("delyvre_cart", Context.MODE_PRIVATE)

    fun save(catalog: Catalog?, items: List<CartItem>) {
        if (catalog == null || items.isEmpty()) {
            clear()
            return
        }
        val rows = JSONArray()
        items.forEach { item ->
            rows.put(
                JSONObject()
                    .put("product_id", item.product.id)
                    .put("quantity", item.quantity.coerceIn(1, 99))
                    .put("option_ids", JSONArray(item.optionIds.toList()))
                    .put("notes", item.notes.take(500))
            )
        }
        val root = JSONObject()
            .put("tenant_id", catalog.store.tenantId)
            .put("unit_id", catalog.store.unitId)
            .put("items", rows)
        prefs.edit().putString(KEY, root.toString()).apply()
    }

    fun load(): SavedCart? {
        val raw = prefs.getString(KEY, null) ?: return null
        return runCatching {
            val root = JSONObject(raw)
            val tenantId = root.optInt("tenant_id")
            val unitId = root.optInt("unit_id")
            if (tenantId < 1 || unitId < 1) return@runCatching null
            val rows = root.optJSONArray("items") ?: return@runCatching null
            val items = buildList {
                for (index in 0 until rows.length()) {
                    val row = rows.optJSONObject(index) ?: continue
                    val productId = row.optInt("product_id")
                    if (productId < 1) continue
                    val optionIds = linkedSetOf<Int>()
                    row.optJSONArray("option_ids")?.let { ids ->
                        for (i in 0 until ids.length()) ids.optInt(i).takeIf { it > 0 }?.let(optionIds::add)
                    }
                    add(
                        SavedCartItem(
                            productId = productId,
                            quantity = row.optInt("quantity", 1).coerceIn(1, 99),
                            optionIds = optionIds,
                            notes = row.optString("notes").take(500),
                        )
                    )
                }
            }
            SavedCart(tenantId, unitId, items).takeIf { it.items.isNotEmpty() }
        }.getOrNull()
    }

    fun clear() {
        prefs.edit().remove(KEY).apply()
    }

    companion object {
        private const val KEY = "active_cart"
    }
}
