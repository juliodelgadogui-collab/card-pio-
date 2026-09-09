package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject
import java.util.UUID

data class HubPeripheralStatus(
    val defaultPrinter: String = "",
    val cashDrawer: String = "",
    val scale: String = "",
    val barcodeScanner: String = "",
    val customerDisplay: String = "",
    val tefProvider: String = "",
    val pinpad: String = "",
    val printers: List<String> = emptyList(),
)

data class HubLink(
    val id: Int,
    val unitId: Int,
    val unitName: String,
    val desktopBindingId: Int,
    val desktopLabel: String,
    val online: Boolean,
    val lastSeenAt: String,
    val hardware: HubPeripheralStatus,
)

data class HubTerminal(
    val id: Int,
    val unitId: Int,
    val provider: String,
    val label: String,
    val pinpad: String,
)

data class HubCommand(
    val id: Int,
    val commandType: String,
    val status: String,
    val error: String = "",
    val createdAt: String = "",
    val completedAt: String = "",
    val approvedLocal: Boolean? = null,
    val verified: Boolean? = null,
    val resultMessage: String = "",
)

class HubRepository(
    baseUrl: String,
    private val deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun links(): List<HubLink> {
        val token = requireToken()
        val root = api.getHub("links", token, mapOf("device_id" to deviceId))
        val array = root.optJSONArray("links") ?: return emptyList()
        return buildList {
            for (i in 0 until array.length()) {
                val item = array.optJSONObject(i) ?: continue
                val hardware = item.optJSONObject("hardware") ?: JSONObject()
                val printersJson = hardware.optJSONArray("printers")
                val printers = buildList {
                    if (printersJson != null) for (p in 0 until printersJson.length()) {
                        printersJson.optString(p).takeIf { it.isNotBlank() }?.let(::add)
                    }
                }
                add(
                    HubLink(
                        id = item.optInt("id"),
                        unitId = item.optInt("unit_id"),
                        unitName = item.optString("unit_name"),
                        desktopBindingId = item.optInt("desktop_binding_id"),
                        desktopLabel = item.optString("desktop_label", "EventMenu Desktop"),
                        online = item.optBoolean("desktop_online", false),
                        lastSeenAt = item.optString("desktop_last_seen_at"),
                        hardware = HubPeripheralStatus(
                            defaultPrinter = hardware.optString("default_printer"),
                            cashDrawer = hardware.optString("cash_drawer"),
                            scale = hardware.optString("scale"),
                            barcodeScanner = hardware.optString("barcode_scanner"),
                            customerDisplay = hardware.optString("customer_display"),
                            tefProvider = hardware.optString("tef_provider"),
                            pinpad = hardware.optString("pinpad"),
                            printers = printers,
                        ),
                    )
                )
            }
        }
    }

    suspend fun terminals(unitId: Int): List<HubTerminal> {
        val root = api.getHub("terminals", requireToken(), mapOf("unit_id" to unitId.toString()))
        val array = root.optJSONArray("terminals") ?: return emptyList()
        return buildList {
            for (i in 0 until array.length()) {
                val item = array.optJSONObject(i) ?: continue
                add(
                    HubTerminal(
                        id = item.optInt("id"),
                        unitId = item.optInt("unit_id"),
                        provider = item.optString("provider"),
                        label = item.optString("label", "PINPad"),
                        pinpad = item.optString("pinpad_identifier"),
                    )
                )
            }
        }
    }

    suspend fun claimPairing(qrOrToken: String, label: String): HubLink {
        val root = api.postHub(
            "pairing-claim",
            requireToken(),
            JSONObject()
                .put("device_id", deviceId)
                .put("qr", qrOrToken.trim())
                .put("label", label.take(190)),
        )
        val link = root.getJSONObject("link")
        return HubLink(
            id = link.optInt("id"),
            unitId = link.optInt("unit_id"),
            unitName = "",
            desktopBindingId = link.optInt("desktop_binding_id"),
            desktopLabel = link.optString("desktop_label", "EventMenu Desktop"),
            online = true,
            lastSeenAt = "",
            hardware = HubPeripheralStatus(),
        )
    }

    suspend fun printOrder(link: HubLink, orderId: Int): HubCommand =
        queue(link, "print_order", JSONObject().put("order_id", orderId))

    suspend fun printReceipt(link: HubLink, orderId: Int): HubCommand =
        queue(link, "print_receipt", JSONObject().put("order_id", orderId))

    suspend fun openDrawer(link: HubLink, reason: String): HubCommand =
        queue(link, "open_drawer", JSONObject().put("reason", reason.take(300)))

    suspend fun showCustomerDisplay(link: HubLink, orderId: Int): HubCommand =
        queue(link, "customer_display", JSONObject().put("order_id", orderId))

    suspend fun chargeTef(
        link: HubLink,
        orderId: Int,
        terminalConfigId: Int,
        amountCents: Int,
        paymentType: String,
        installments: Int = 1,
    ): HubCommand = queue(
        link,
        "tef_charge",
        JSONObject()
            .put("order_id", orderId)
            .put("terminal_config_id", terminalConfigId)
            .put("amount_cents", amountCents)
            .put("payment_type", paymentType)
            .put("installments", installments.coerceIn(1, 24)),
    )

    suspend fun playAlert(link: HubLink, message: String): HubCommand =
        queue(link, "play_alert", JSONObject().put("message", message.take(300)))

    suspend fun commandStatus(id: Int): HubCommand {
        val root = api.getHub(
            "command-status",
            requireToken(),
            mapOf("id" to id.toString(), "device_id" to deviceId),
        )
        return parseCommand(root.getJSONObject("command"))
    }

    suspend fun revokeLink(linkId: Int) {
        api.postHub(
            "link-revoke",
            requireToken(),
            JSONObject().put("id", linkId).put("device_id", deviceId),
        )
    }

    private suspend fun queue(link: HubLink, type: String, payload: JSONObject): HubCommand {
        if (!link.online) throw ApiException("O computador EventMenu está offline.")
        val key = "mobile-hub:${link.desktopBindingId}:$type:${UUID.randomUUID()}"
        val root = api.postHub(
            "command-create",
            requireToken(),
            JSONObject()
                .put("target_binding_id", link.desktopBindingId)
                .put("command_type", type)
                .put("payload", payload)
                .put("idempotency_key", key)
                .put("device_id", deviceId),
        )
        return parseCommand(root.getJSONObject("command"))
    }

    private fun parseCommand(item: JSONObject): HubCommand {
        val result = item.optJSONObject("result")
        val approved = if (result?.has("approved_local") == true) result.optBoolean("approved_local") else null
        val verified = if (result?.has("verified") == true) result.optBoolean("verified") else null
        return HubCommand(
            id = item.optInt("id"),
            commandType = item.optString("command_type"),
            status = item.optString("status"),
            error = item.optString("error_message"),
            createdAt = item.optString("created_at"),
            completedAt = item.optString("completed_at"),
            approvedLocal = approved,
            verified = verified,
            resultMessage = result?.optString("message").orEmpty(),
        )
    }

    private fun requireToken(): String = sessionStore.token()?.takeIf { it.isNotBlank() }
        ?: throw ApiException("Faça login novamente.", 401)
}
