package br.com.eventmenu.delivery

import android.content.SharedPreferences
import org.json.JSONArray
import org.json.JSONObject

data class CartDraftLine(
    val productId: Int,
    val quantity: Int,
    val optionIds: Set<Int>,
)

data class CartDraft(
    val tenantId: Int,
    val unitId: Int,
    val lines: List<CartDraftLine>,
)

data class RevalidatedCart(
    val lines: List<CartLine>,
    val changed: Boolean,
)

class CartDraftStore(private val prefs: SharedPreferences) {
    fun load(): CartDraft? {
        val raw = prefs.getString(KEY, null)?.trim().orEmpty()
        if (raw.isBlank()) return null
        return runCatching {
            val json = JSONObject(raw)
            val tenantId = json.optInt("tenant_id")
            val unitId = json.optInt("unit_id")
            if (tenantId < 1 || unitId < 1) return@runCatching null
            val source = json.optJSONArray("lines") ?: JSONArray()
            val lines = buildList {
                for (i in 0 until source.length()) {
                    val item = source.optJSONObject(i) ?: continue
                    val productId = item.optInt("product_id")
                    val quantity = item.optInt("quantity").coerceIn(1, 99)
                    if (productId < 1) continue
                    val optionsJson = item.optJSONArray("option_ids") ?: JSONArray()
                    val optionIds = buildSet {
                        for (j in 0 until optionsJson.length()) {
                            val id = optionsJson.optInt(j)
                            if (id > 0) add(id)
                        }
                    }
                    add(CartDraftLine(productId, quantity, optionIds))
                }
            }
            if (lines.isEmpty()) null else CartDraft(tenantId, unitId, lines)
        }.getOrNull().also { if (it == null && raw.isNotBlank()) clear() }
    }

    fun save(store: Store, cart: List<CartLine>) {
        if (cart.isEmpty()) {
            clear()
            return
        }
        val json = JSONObject().apply {
            put("tenant_id", store.tenantId)
            put("unit_id", store.unitId)
            put("lines", JSONArray().apply {
                cart.forEach { line ->
                    put(JSONObject().apply {
                        put("product_id", line.product.id)
                        put("quantity", line.quantity.coerceIn(1, 99))
                        put("option_ids", JSONArray(line.selectedOptionIds.sorted()))
                    })
                }
            })
        }
        prefs.edit().putString(KEY, json.toString()).apply()
    }

    fun clear() {
        prefs.edit().remove(KEY).apply()
    }

    fun revalidate(catalog: Catalog, draft: CartDraft): RevalidatedCart {
        if (catalog.store.tenantId != draft.tenantId || catalog.store.unitId != draft.unitId) {
            return RevalidatedCart(emptyList(), changed = true)
        }
        var changed = false
        val productsById = catalog.products.associateBy { it.id }
        val restored = buildList {
            draft.lines.forEach { saved ->
                val product = productsById[saved.productId]
                if (product == null || !product.available) {
                    changed = true
                    return@forEach
                }
                val validOptionIds = product.modifierGroups.flatMap { it.options }.map { it.id }.toSet()
                if (!saved.optionIds.all { it in validOptionIds }) {
                    changed = true
                    return@forEach
                }
                val groupsValid = product.modifierGroups.all { group ->
                    val count = group.options.count { it.id in saved.optionIds }
                    count in group.minSelect..group.maxSelect
                }
                if (!groupsValid) {
                    changed = true
                    return@forEach
                }
                val quantity = saved.quantity.coerceIn(1, 99)
                if (quantity != saved.quantity) changed = true
                add(CartLine(product, quantity, saved.optionIds.toSortedSet()))
            }
        }
        if (restored.size != draft.lines.size) changed = true
        return RevalidatedCart(restored, changed)
    }

    fun revalidate(catalog: Catalog, cart: List<CartLine>): RevalidatedCart {
        val draft = CartDraft(
            tenantId = catalog.store.tenantId,
            unitId = catalog.store.unitId,
            lines = cart.map { CartDraftLine(it.product.id, it.quantity, it.selectedOptionIds) },
        )
        val result = revalidate(catalog, draft)
        if (result.lines.size != cart.size) return result.copy(changed = true)
        val priceOrIdentityChanged = result.lines.zip(cart).any { (fresh, old) ->
            fresh.product.id != old.product.id ||
                fresh.product.name != old.product.name ||
                fresh.unitTotalCents != old.unitTotalCents ||
                fresh.quantity != old.quantity ||
                fresh.selectedOptionIds != old.selectedOptionIds
        }
        return result.copy(changed = result.changed || priceOrIdentityChanged)
    }

    companion object {
        private const val KEY = "cart_draft_v1"
    }
}
