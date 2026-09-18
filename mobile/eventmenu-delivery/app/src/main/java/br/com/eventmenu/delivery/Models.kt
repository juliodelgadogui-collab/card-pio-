package br.com.eventmenu.delivery

data class Store(
    val tenantId: Int,
    val unitId: Int,
    val name: String,
    val description: String,
    val unitName: String,
    val city: String,
    val state: String,
    val address: String,
    val logoUrl: String,
    val coverUrl: String,
    val deliveryFeeCents: Int,
    val minimumOrderCents: Int,
)

data class Category(val id: Int, val name: String)

data class ModifierOption(val id: Int, val name: String, val priceDeltaCents: Int)

data class ModifierGroup(
    val id: Int,
    val name: String,
    val required: Boolean,
    val minSelect: Int,
    val maxSelect: Int,
    val options: List<ModifierOption>,
)

data class Product(
    val id: Int,
    val categoryId: Int?,
    val name: String,
    val description: String,
    val priceCents: Int,
    val imageUrl: String,
    val available: Boolean,
    val modifierGroups: List<ModifierGroup>,
)

data class CheckoutSession(val token: String, val expiresAt: String, val expiresIn: Int)

data class Catalog(
    val store: Store,
    val categories: List<Category>,
    val products: List<Product>,
    val checkoutSession: CheckoutSession,
)

data class CartLine(
    val product: Product,
    val quantity: Int = 1,
    val selectedOptionIds: Set<Int> = emptySet(),
) {
    val unitTotalCents: Int
        get() = product.priceCents + product.modifierGroups
            .flatMap { it.options }
            .filter { it.id in selectedOptionIds }
            .sumOf { it.priceDeltaCents }
    val totalCents: Int get() = unitTotalCents * quantity
}

data class TimelineStep(val key: String, val label: String, val done: Boolean, val current: Boolean)

data class TrackingReference(val active: Boolean, val token: String, val expiresAt: String?)

data class OrderItem(
    val id: Int,
    val name: String,
    val unitPriceCents: Int,
    val quantity: Double,
    val totalCents: Int,
    val modifiers: List<String>,
)

data class ConsumerOrder(
    val orderNumber: Int,
    val publicToken: String,
    val storeName: String,
    val unitName: String,
    val status: String,
    val statusLabel: String,
    val paymentStatus: String,
    val paymentStatusLabel: String,
    val subtotalCents: Int,
    val discountCents: Int,
    val deliveryFeeCents: Int,
    val totalCents: Int,
    val createdAt: String,
    val timeline: List<TimelineStep>,
    val items: List<OrderItem>,
    val tracking: TrackingReference?,
)

data class TrackingLocation(
    val latitude: Double,
    val longitude: Double,
    val accuracyM: Double?,
    val recordedAt: String?,
)

data class TrackingStatus(
    val active: Boolean,
    val status: String,
    val statusLabel: String,
    val location: TrackingLocation?,
    val routeStartedAt: String?,
    val arrivedAt: String?,
    val completedAt: String?,
    val expiresAt: String?,
)

data class CheckoutCustomer(val name: String, val phone: String, val address: String)

sealed interface Screen {
    data object Stores : Screen
    data class Menu(val catalog: Catalog) : Screen
    data class Checkout(val catalog: Catalog) : Screen
    data class Order(val order: ConsumerOrder) : Screen
}

fun Int.money(): String = "R$ %.2f".format(this / 100.0).replace('.', ',')
