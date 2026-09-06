package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

data class OperatingUnit(
    val id: Int,
    val code: String,
    val name: String,
    val address: String,
    val isDefault: Boolean,
)

data class OperatingUnitContext(
    val tenantName: String,
    val units: List<OperatingUnit>,
)

data class UnassignedUnitDelivery(
    val id: Int,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val customerName: String,
    val customerPhone: String,
    val deliveryAddress: String,
    val createdAt: String,
)

class OperatingUnitRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun list(): OperatingUnitContext {
        val root = api.getUnits("list", requireToken())
        val tenant = root.optJSONObject("tenant") ?: JSONObject()
        val array = root.optJSONArray("units") ?: JSONArray()
        val units = buildList {
            for (i in 0 until array.length()) {
                val u = array.getJSONObject(i)
                add(
                    OperatingUnit(
                        id = u.optInt("id"),
                        code = u.optString("code"),
                        name = u.optString("name"),
                        address = u.optString("address"),
                        isDefault = u.optInt("is_default", 0) == 1,
                    )
                )
            }
        }
        return OperatingUnitContext(tenant.optString("name"), units)
    }

    suspend fun openShift(mode: AppMode, unitId: Int?, notes: String = "") {
        val body = JSONObject().put("mode", mode.wire).put("notes", notes)
        unitId?.takeIf { it > 0 }?.let { body.put("unit_id", it) }
        api.postUnits("shift-open", requireToken(), body)
    }

    suspend fun unassignedDeliveries(): List<UnassignedUnitDelivery> {
        val array = api.getUnits("delivery-unassigned", requireToken()).optJSONArray("orders") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val o = array.getJSONObject(i)
                add(
                    UnassignedUnitDelivery(
                        id = o.optInt("id"),
                        status = o.optString("status"),
                        paymentStatus = o.optString("payment_status"),
                        totalCents = o.optInt("total_cents"),
                        customerName = o.optString("customer_name", "Consumidor"),
                        customerPhone = o.optString("customer_phone"),
                        deliveryAddress = o.optString("delivery_address"),
                        createdAt = o.optString("created_at"),
                    )
                )
            }
        }
    }

    suspend fun assignDeliveryUnit(orderId: Int, unitId: Int) {
        api.postUnits(
            "delivery-assign-unit",
            requireToken(),
            JSONObject().put("order_id", orderId).put("unit_id", unitId),
        )
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
