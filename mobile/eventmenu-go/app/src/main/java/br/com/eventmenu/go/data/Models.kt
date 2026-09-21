package br.com.eventmenu.go.data

data class AppUser(val id: Int, val tenantId: Int, val name: String, val email: String, val role: String, val tenantName: String = "")
data class WorkShift(
    val id: Int,
    val mode: String,
    val status: String,
    val startedAt: String,
    val endedAt: String? = null,
    val unitId: Int? = null,
    val unitName: String = "",
    val unitCode: String = "",
)
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
    val orderSource: String = "",
) {
    val fromEventMenuDelivery: Boolean get() = orderSource.equals("EVENTMENU_DELIVERY", ignoreCase = true)
}

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
data class PaymentBalance(
    val orderId: Int,
    val totalCents: Int,
    val paidCents: Int,
    val remainingCents: Int,
    val paymentStatus: String,
    val payments: List<PaymentPart> = emptyList(),
    val latestPix: PaymentPart? = null,
)

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

data class ShiftMethodTotal(val method: String, val direction: String, val qty: Int, val totalCents: Int)
data class ShiftOrderSummary(val qty: Int, val totalCents: Int)
data class DeliveryCommissionSummary(
    val deliveries: Int,
    val revenueCents: Int,
    val percentBps: Int,
    val fixedPerDeliveryCents: Int,
    val percentPartCents: Int,
    val fixedPartCents: Int,
    val commissionCents: Int,
)
data class ShiftSummary(
    val shift: WorkShift?,
    val userName: String,
    val orders: ShiftOrderSummary,
    val byMethod: List<ShiftMethodTotal>,
    val deliveryCash: DeliveryCashBalance? = null,
    val deliveryCommission: DeliveryCommissionSummary? = null,
) {
    fun totalFor(method: String, direction: String = "in"): Int = byMethod.filter { it.method == method && it.direction == direction }.sumOf { it.totalCents }
    val receivedTotalCents: Int get() = byMethod.filter { it.direction == "in" }.sumOf { it.totalCents }
}

data class EventOverview(
    val id: Int,
    val name: String,
    val venue: String,
    val address: String,
    val startsAt: String,
    val endsAt: String,
    val status: String,
    val ticketsPaid: Int,
    val ticketsCheckedIn: Int,
    val ticketsReserved: Int,
    val guestsPending: Int,
    val guestsCheckedIn: Int,
    val ticketRevenueCents: Int,
    val barRevenueCents: Int,
    val revenueCents: Int,
)

data class EventEntry(
    val id: Int,
    val type: String,
    val personName: String,
    val detail: String,
    val checkedInAt: String,
    val operatorName: String,
)

data class EventPickupItem(
    val id: Int,
    val name: String,
    val quantity: Double,
    val unitPriceCents: Int,
    val totalCents: Int,
)

data class EventPickupOrder(
    val id: Int,
    val eventId: Int,
    val eventName: String,
    val publicToken: String,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val customerName: String,
    val customerPhone: String,
    val alreadyDelivered: Boolean,
    val canDeliver: Boolean,
    val items: List<EventPickupItem>,
)

data class ManagerAlert(val level: String, val title: String, val message: String)
data class ManagerOverview(
    val ordersNow: Int,
    val kitchenDelayed: Int,
    val readyOrders: Int,
    val unassignedDelivery: Int,
    val deliveryOnline: Int,
    val cashOpen: Int,
    val pendingPayments: Int,
    val revenueTodayCents: Int,
    val alerts: List<ManagerAlert>,
)
data class ManagerCashSession(val id: Int, val userId: Int, val userName: String, val openingCashCents: Int, val openedAt: String)
data class ManagerDeliveryShift(val shiftId: Int, val userId: Int, val userName: String, val startedAt: String, val activeOrders: Int)
data class ManagerProblemOrder(
    val id: Int,
    val channel: String,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val customerName: String,
    val deliveryUserId: Int?,
    val deliveryName: String,
    val updatedAt: String,
    val problemType: String,
)
data class ManagerDetails(
    val cashSessions: List<ManagerCashSession>,
    val deliveryShifts: List<ManagerDeliveryShift>,
    val problemOrders: List<ManagerProblemOrder>,
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

data class PixCharge(
    val paymentId: Int,
    val orderId: Int,
    val amountCents: Int,
    val copyPaste: String,
    val expiresAt: String,
    val provider: String = "",
    val reused: Boolean = false,
)

data class DeliveryCashReceipt(val orderId: Int, val paymentId: Int?, val totalCents: Int, val receivedCents: Int, val changeCents: Int)
data class DeliveryCashBalance(val shiftId: Int, val cashCollectedCents: Int, val confirmedHandoffCents: Int, val outstandingCents: Int)
data class CashHandoff(val id: Int, val token: String, val qrPayload: String, val amountCents: Int, val status: String, val deliveryName: String = "")
data class TapOnRequest(val intentToken: String, val orderId: Int, val amountCents: Int, val appKey: String, val appName: String, val appVersion: String, val enableTaxPassThrough: Boolean)

enum class AppMode(val wire: String, val label: String, val emoji: String) {
    OPERATION("operation", "Operação", "🍽"), DELIVERY("delivery", "Delivery", "🛵"), EVENTS("events", "Eventos", "🎟"), PAY("pay", "Pay", "💳");
    companion object { fun fromWire(value: String): AppMode? = entries.firstOrNull { it.wire == value } }
}
