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

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
