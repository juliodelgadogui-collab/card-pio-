package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

data class MobileSessionStatus(
    val tokenId: Int,
    val deviceLabel: String,
    val expiresAt: String,
    val lastUsedAt: String,
    val createdAt: String,
)

data class NfcDeviceStatus(
    val id: Int,
    val name: String,
    val provider: String,
    val status: String,
    val pairedAt: String,
    val revokedAt: String,
    val boundToCurrentUser: Boolean,
)

data class DeviceStatus(
    val session: MobileSessionStatus,
    val nfc: NfcDeviceStatus?,
    val nfcPermission: Boolean,
    val pagbankActive: Boolean,
    val tapOnReady: Boolean,
)

class DeviceStatusRepository(
    baseUrl: String,
    deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    // Mantém o refresh token no mesmo cliente usado pelas demais APIs do app.
    // Assim a consulta de autorização remota não força logout quando o access
    // token expira enquanto o operador aguarda a aprovação do aparelho.
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun status(): DeviceStatus = parseDevice(
        api.getDevice("status", requireToken()).getJSONObject("device")
    )

    suspend fun requestNfcAuthorization(): DeviceStatus = parseDevice(
        api.postDevice("request-nfc", requireToken()).getJSONObject("device")
    )

    private fun parseDevice(d: JSONObject): DeviceStatus {
        val sessionJson = d.getJSONObject("session")
        val nfcJson = d.optJSONObject("nfc")
        return DeviceStatus(
            session = MobileSessionStatus(
                tokenId = sessionJson.optInt("token_id"),
                deviceLabel = sessionJson.optString("device_label"),
                expiresAt = sessionJson.optString("expires_at"),
                lastUsedAt = sessionJson.optString("last_used_at"),
                createdAt = sessionJson.optString("created_at"),
            ),
            nfc = nfcJson?.let {
                NfcDeviceStatus(
                    id = it.optInt("id"),
                    name = it.optString("name"),
                    provider = it.optString("provider"),
                    status = it.optString("status"),
                    pairedAt = it.optString("paired_at"),
                    revokedAt = it.optString("revoked_at"),
                    boundToCurrentUser = it.optBoolean("bound_to_current_user"),
                )
            },
            nfcPermission = d.optBoolean("nfc_permission"),
            pagbankActive = d.optBoolean("pagbank_active"),
            tapOnReady = d.optBoolean("tap_on_ready"),
        )
    }

    private fun requireToken(): String = sessionStore.token()?.takeIf { it.isNotBlank() }
        ?: throw ApiException("Sessão não encontrada.", 401)
}
