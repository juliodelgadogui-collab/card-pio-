package br.com.eventmenu.go.data

data class AppUser(val id: Int, val tenantId: Int, val name: String, val email: String, val role: String)

data class WorkShift(val id: Int, val mode: String, val status: String, val startedAt: String, val endedAt: String? = null)

data class Session(val user: AppUser, val permissions: Set<String>, val modes: List<AppMode> = emptyList(), val shift: WorkShift? = null)

data class Order(
    val id: Int,
    val channel: String,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val customerName: String = "Consumidor",
    val customerPhone: String = "",
    val deliveryAddress: String = "",
    val assignedDeliveryUserId: Int? = null,
)

data class QrResult(
    val type: String,
    val title: String,
    val raw: String,
    val subtitle: String = "",
    val amountCents: Int? = null,
    val status: String = "",
)

data class PixCharge(
    val paymentId: Int,
    val orderId: Int,
    val amountCents: Int,
    val copyPaste: String,
    val expiresAt: String,
)

data class DeliveryCashReceipt(
    val orderId: Int,
    val paymentId: Int?,
    val totalCents: Int,
    val receivedCents: Int,
    val changeCents: Int,
)

data class DeliveryCashBalance(
    val shiftId: Int,
    val cashCollectedCents: Int,
    val confirmedHandoffCents: Int,
    val outstandingCents: Int,
)

data class CashHandoff(
    val id: Int,
    val token: String,
    val qrPayload: String,
    val amountCents: Int,
    val status: String,
    val deliveryName: String = "",
)

data class TapOnRequest(
    val intentToken: String,
    val orderId: Int,
    val amountCents: Int,
    val appKey: String,
    val appName: String,
    val appVersion: String,
    val enableTaxPassThrough: Boolean,
)

enum class AppMode(val wire: String, val label: String, val emoji: String) {
    OPERATION("operation", "Operação", "🍽"),
    DELIVERY("delivery", "Delivery", "🛵"),
    EVENTS("events", "Eventos", "🎟"),
    PAY("pay", "Pay", "💳");
    companion object { fun fromWire(value: String): AppMode? = entries.firstOrNull { it.wire == value } }
}
