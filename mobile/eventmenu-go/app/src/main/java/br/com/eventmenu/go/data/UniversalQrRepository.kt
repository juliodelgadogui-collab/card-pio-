package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

data class IssuedUniversalQr(
    val type: String,
    val entityId: Int,
    val label: String,
    val payload: String,
    val expiresAt: String,
)

data class ResolvedUniversalQr(
    val type: String,
    val entityId: Int,
    val title: String,
    val subtitle: String,
    val details: List<String>,
    val deliveryShiftOpen: Boolean = false,
)

class UniversalQrRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun issue(type: String, entityId: Int, label: String = "", ttlHours: Int? = null): IssuedUniversalQr {
        val body = JSONObject().put("type", type).put("entity_id", entityId).put("label", label)
        ttlHours?.let { body.put("ttl_hours", it) }
        val q = api.postQr("issue", requireToken(), body).getJSONObject("qr")
        return IssuedUniversalQr(
            type = q.optString("type"),
            entityId = q.optInt("entity_id"),
            label = q.optString("label"),
            payload = q.getString("payload"),
            expiresAt = q.optString("expires_at"),
        )
    }

    suspend fun revoke(type: String, entityId: Int) {
        api.postQr("revoke", requireToken(), JSONObject().put("type", type).put("entity_id", entityId))
    }

    suspend fun resolve(value: String): ResolvedUniversalQr {
        val q = api.getQr("resolve", requireToken(), mapOf("value" to value)).getJSONObject("qr")
        val type = q.optString("type")
        val entityId = q.optInt("entity_id")
        val data = q.optJSONObject("data") ?: JSONObject()
        return when (type) {
            "delivery_user", "employee" -> {
                val shift = data.optJSONObject("shift")
                val shiftMode = shift?.optString("mode").orEmpty()
                val onDelivery = shiftMode == "delivery"
                ResolvedUniversalQr(
                    type = type,
                    entityId = entityId,
                    title = if (type == "delivery_user") "🛵 ${data.optString("name", "Entregador")}" else "👤 ${data.optString("name", "Funcionário")}",
                    subtitle = roleLabel(data.optString("role")),
                    details = buildList {
                        data.optString("email").takeIf { it.isNotBlank() }?.let { add(it) }
                        if (shift != null) add("Turno ${modeLabel(shiftMode)} desde ${shift.optString("started_at")}") else add("Sem turno aberto")
                    },
                    deliveryShiftOpen = onDelivery,
                )
            }
            "customer" -> ResolvedUniversalQr(
                type = type,
                entityId = entityId,
                title = "👤 ${data.optString("name", "Cliente")}",
                subtitle = "Cliente EventMenu",
                details = buildList {
                    data.optString("phone").takeIf { it.isNotBlank() }?.let { add("Telefone: $it") }
                    data.optString("email").takeIf { it.isNotBlank() }?.let { add(it) }
                    add("Pontos: ${data.optInt("points")}")
                },
            )
            "event" -> ResolvedUniversalQr(
                type = type,
                entityId = entityId,
                title = "🎟 ${data.optString("name", "Evento")}",
                subtitle = eventStatus(data.optString("status")),
                details = buildList {
                    data.optString("venue").takeIf { it.isNotBlank() }?.let(::add)
                    data.optString("address").takeIf { it.isNotBlank() }?.let(::add)
                    data.optString("starts_at").takeIf { it.isNotBlank() }?.let { add("Início: $it") }
                },
            )
            "device" -> ResolvedUniversalQr(
                type = type,
                entityId = entityId,
                title = "📱 ${data.optString("name").ifBlank { "Dispositivo NFC #$entityId" }}",
                subtitle = "${data.optString("provider", "pagbank")} · ${data.optString("status")}",
                details = buildList {
                    data.optString("user_name").takeIf { it.isNotBlank() }?.let { add("Vinculado a: $it") }
                    data.optString("paired_at").takeIf { it.isNotBlank() }?.let { add("Pareado: $it") }
                },
            )
            "tab" -> ResolvedUniversalQr(
                type = type,
                entityId = entityId,
                title = "🧾 ${data.optString("table_name", "Comanda")}",
                subtitle = data.optString("label").ifBlank { "Comanda #$entityId" },
                details = buildList {
                    add("Status: ${data.optString("status")}")
                    data.optString("opened_at").takeIf { it.isNotBlank() }?.let { add("Aberta: $it") }
                    add("Lugares: ${data.optInt("seats")}")
                },
            )
            else -> ResolvedUniversalQr(type, entityId, "QR EventMenu", type, emptyList())
        }
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)

    private fun roleLabel(role: String) = when (role) {
        "delivery" -> "Entregador"
        "cashier" -> "Caixa"
        "attendant" -> "Balconista"
        "kitchen" -> "Cozinha"
        "waiter" -> "Garçom"
        "manager" -> "Gerente"
        "admin" -> "Administrador"
        else -> role
    }

    private fun modeLabel(mode: String) = when (mode) {
        "operation" -> "Operação"
        "delivery" -> "Delivery"
        "events" -> "Eventos"
        "pay" -> "Pay"
        else -> mode
    }

    private fun eventStatus(status: String) = when (status) {
        "published" -> "Publicado"
        "draft" -> "Rascunho"
        "closed" -> "Encerrado"
        "cancelled" -> "Cancelado"
        else -> status
    }
}
