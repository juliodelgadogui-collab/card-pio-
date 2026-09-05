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
    val deliveryName: String = "",
    val createdAt: String = "",
    val tableName: String = "",
    val tableId: Int? = null,
    val tabId: Int? = null,
)

data class DeliveryUser(val id: Int, val name: String, val email: String, val onShift: Boolean, val startedAt: String = "")
data class KitchenItem(val name: String, val quantity: Double, val notes: String = "")
data class KitchenTicket(val id: Int, val channel: String, val status: String, val notes: String, val createdAt: String, val tableName: String, val customerName: String, val items: List<KitchenItem>)

data class RestaurantTable(
    val id: Int,
    val name: String,
    val seats: Int,
    val status: String,
    val tabId: Int?,
    val tabLabel: String,
    val openedAt: String,
    val tabTotalCents: Int,
    val unpaidCents: Int,
)

data class TableAccountOrder(
    val orderId: Int,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val paidCents: Int,
    val remainingCents: Int,
)

data class TableAccount(
    val table: RestaurantTable,
    val orders: List<TableAccountOrder>,
    val totalCents: Int,
    val paidCents: Int,
    val remainingCents: Int,
)

data class Product(
    val id: Int,
    val categoryId: Int?,
    val categoryName: String,
    val name: String,
    val description: String,
    val priceCents: Int,
    val stockQty: Double,
    val trackStock: Boolean,
    val imageUrl: String,
)

data class CreatedOrder(val id: Int, val publicToken: String, val channel: String, val totalCents: Int)
data class PaymentPart(val id: Int, val provider: String, val amountCents: Int, val status: String, val verifiedAt: String = "")
data class PaymentBalance(val orderId: Int, val totalCents: Int, val paidCents: Int, val remainingCents: Int, val paymentStatus: String, val payments: List<PaymentPart> = emptyList())

data class CashSession(
    val id: Int,
    val status: String,
    val openingCashCents: Int,
    val openedAt: String,
    val closingCashCents: Int? = null,
    val expectedCashCents: Int? = null,
    val differenceCents: Int? = null,
    val closedAt: String = "",
)
data class CashMovement(
    val id: Int,
    val type: String,
    val method: String,
    val direction: String,
    val amountCents: Int,
    val notes: String,
    val createdAt: String,
)
data class CashMethodTotal(val method: String, val direction: String, val totalCents: Int, val qty: Int)
data class CashDigitalTotal(val provider: String, val totalCents: Int, val qty: Int)
data class CashSummary(
    val session: CashSession?,
    val expectedCashCents: Int,
    val movements: List<CashMovement>,
    val byMethod: List<CashMethodTotal>,
    val digital: List<CashDigitalTotal>,
)

data class QrResult(
    val type: String,
    val title: String,
    val raw: String,
    val subtitle: String = "",
    val amountCents: Int? = null,
    val status: String = "",
    val orderId: Int? = null,
    val channel: String = "",
)
data class PixCharge(val paymentId: Int, val orderId: Int, val amountCents: Int, val copyPaste: String, val expiresAt: String)
data class DeliveryCashReceipt(val orderId: Int, val paymentId: Int?, val totalCents: Int, val receivedCents: Int, val changeCents: Int)
data class DeliveryCashBalance(val shiftId: Int, val cashCollectedCents: Int, val confirmedHandoffCents: Int, val outstandingCents: Int)
data class CashHandoff(val id: Int, val token: String, val qrPayload: String, val amountCents: Int, val status: String, val deliveryName: String = "")
data class TapOnRequest(val intentToken: String, val orderId: Int, val amountCents: Int, val appKey: String, val appName: String, val appVersion: String, val enableTaxPassThrough: Boolean)

enum class AppMode(val wire: String, val label: String, val emoji: String) {
    OPERATION("operation", "Operação", "🍽"), DELIVERY("delivery", "Delivery", "🛵"), EVENTS("events", "Eventos", "🎟"), PAY("pay", "Pay", "💳");
    companion object { fun fromWire(value: String): AppMode? = entries.firstOrNull { it.wire == value } }
}
