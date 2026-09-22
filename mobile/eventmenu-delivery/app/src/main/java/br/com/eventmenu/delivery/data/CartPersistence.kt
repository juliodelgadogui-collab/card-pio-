package br.com.eventmenu.delivery.data

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class SavedCart(
    val store: Store,
    val items: List<CartItem>,
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
                    .put("product", productToJson(item.product))
                    .put("quantity", item.quantity.coerceIn(1, 99))
                    .put("option_ids", JSONArray(item.optionIds.toList()))
                    .put("notes", item.notes.take(500))
            )
        }
        val root = JSONObject()
            .put("store", storeToJson(catalog.store))
            .put("items", rows)
        prefs.edit().putString(KEY, root.toString()).apply()
    }

    fun load(): SavedCart? {
        val raw = prefs.getString(KEY, null) ?: return null
        return runCatching {
            val root = JSONObject(raw)
            val store = jsonToStore(root.optJSONObject("store") ?: return@runCatching null)
            if (store.tenantId < 1 || store.unitId < 1) return@runCatching null
            val rows = root.optJSONArray("items") ?: return@runCatching null
            val items = buildList {
                for (index in 0 until rows.length()) {
                    val row = rows.optJSONObject(index) ?: continue
                    val product = jsonToProduct(row.optJSONObject("product") ?: continue)
                    if (product.id < 1) continue
                    val optionIds = linkedSetOf<Int>()
                    row.optJSONArray("option_ids")?.let { ids ->
                        for (i in 0 until ids.length()) ids.optInt(i).takeIf { it > 0 }?.let(optionIds::add)
                    }
                    add(CartItem(product, row.optInt("quantity", 1).coerceIn(1, 99), optionIds, row.optString("notes").take(500)))
                }
            }
            SavedCart(store, items).takeIf { it.items.isNotEmpty() }
        }.getOrNull()
    }

    fun clear() { prefs.edit().remove(KEY).apply() }

    private fun storeToJson(store: Store): JSONObject = JSONObject()
        .put("tenant_id", store.tenantId)
        .put("unit_id", store.unitId)
        .put("name", store.name)
        .put("description", store.description)
        .put("city", store.city)
        .put("state", store.state)
        .put("logo_url", store.logoUrl)
        .put("cover_url", store.coverUrl)
        .put("delivery_fee_cents", store.deliveryFeeCents)
        .put("minimum_order_cents", store.minimumOrderCents)
        .put("favorite", store.favorite)
        .put("accepting_orders", store.acceptingOrders)
        .put("delivery_eta_minutes", store.deliveryEtaMinutes)
        .put("delivery_radius_km", store.deliveryRadiusKm)
        .put("pickup_enabled", store.pickupEnabled)
        .put("schedule_note", store.scheduleNote)
        .put("categories", JSONArray(store.categories))

    private fun jsonToStore(o: JSONObject): Store = Store(
        tenantId = o.optInt("tenant_id"),
        unitId = o.optInt("unit_id"),
        name = o.optString("name"),
        description = o.optString("description"),
        city = o.optString("city"),
        state = o.optString("state"),
        logoUrl = o.optString("logo_url"),
        coverUrl = o.optString("cover_url"),
        deliveryFeeCents = o.optInt("delivery_fee_cents"),
        minimumOrderCents = o.optInt("minimum_order_cents"),
        favorite = o.optBoolean("favorite"),
        acceptingOrders = o.optBoolean("accepting_orders", true),
        deliveryEtaMinutes = o.optInt("delivery_eta_minutes", 45),
        deliveryRadiusKm = o.optDouble("delivery_radius_km", 0.0),
        pickupEnabled = o.optBoolean("pickup_enabled"),
        scheduleNote = o.optString("schedule_note"),
        categories = o.optJSONArray("categories").strings(),
    )

    private fun productToJson(product: Product): JSONObject = JSONObject()
        .put("id", product.id)
        .put("category_id", product.categoryId ?: JSONObject.NULL)
        .put("name", product.name)
        .put("description", product.description)
        .put("price_cents", product.priceCents)
        .put("image_url", product.imageUrl)
        .put("available", product.available)
        .put("modifier_groups", JSONArray().apply {
            product.modifierGroups.forEach { group ->
                put(JSONObject()
                    .put("id", group.id)
                    .put("name", group.name)
                    .put("required", group.required)
                    .put("min_select", group.minSelect)
                    .put("max_select", group.maxSelect)
                    .put("options", JSONArray().apply {
                        group.options.forEach { option ->
                            put(JSONObject().put("id", option.id).put("name", option.name).put("price_delta_cents", option.priceDeltaCents))
                        }
                    }))
            }
        })

    private fun jsonToProduct(o: JSONObject): Product = Product(
        id = o.optInt("id"),
        categoryId = if (o.isNull("category_id")) null else o.optInt("category_id"),
        name = o.optString("name"),
        description = o.optString("description"),
        priceCents = o.optInt("price_cents"),
        imageUrl = o.optString("image_url"),
        available = o.optBoolean("available", true),
        modifierGroups = buildList {
            val groups = o.optJSONArray("modifier_groups") ?: JSONArray()
            for (i in 0 until groups.length()) {
                val group = groups.optJSONObject(i) ?: continue
                val options = buildList {
                    val rows = group.optJSONArray("options") ?: JSONArray()
                    for (j in 0 until rows.length()) {
                        val option = rows.optJSONObject(j) ?: continue
                        add(ModifierOption(option.optInt("id"), option.optString("name"), option.optInt("price_delta_cents")))
                    }
                }
                add(ModifierGroup(group.optInt("id"), group.optString("name"), group.optBoolean("required"), group.optInt("min_select"), group.optInt("max_select", 1), options))
            }
        },
    )

    companion object { private const val KEY = "active_cart" }
}

private fun JSONArray?.strings(): List<String> {
    if (this == null) return emptyList()
    return buildList { for (i in 0 until length()) optString(i).takeIf { it.isNotBlank() }?.let(::add) }
}
