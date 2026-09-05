package br.com.eventmenu.go.data

data class AppUser(
    val id: Int,
    val tenantId: Int,
    val name: String,
    val email: String,
    val role: String,
)

data class Session(
    val user: AppUser,
    val permissions: Set<String>,
)

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
)

enum class AppMode(val label: String, val emoji: String) {
    OPERATION("Operação", "🍽"),
    DELIVERY("Delivery", "🛵"),
    EVENTS("Eventos", "🎟"),
    PAY("Pay", "💳"),
}
