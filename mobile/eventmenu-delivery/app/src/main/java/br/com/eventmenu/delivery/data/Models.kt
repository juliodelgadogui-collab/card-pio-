package br.com.eventmenu.delivery.data

data class Address(
    val id: Int = 0,
    val label: String = "Casa",
    val street: String = "",
    val number: String = "",
    val complement: String = "",
    val neighborhood: String = "",
    val city: String = "",
    val state: String = "",
    val postalCode: String = "",
    val reference: String = "",
    val phone: String = "",
    val latitude: Double? = null,
    val longitude: Double? = null,
    val isDefault: Boolean = false,
)

data class Customer(
    val id: Int,
    val name: String,
    val email: String,
    val phone: String,
    val emailVerified: Boolean,
    val addresses: List<Address> = emptyList(),
)

data class Store(
    val tenantId: Int,
    val unitId: Int,
    val name: String,
    val description: String,
    val city: String,
    val state: String,
    val logoUrl: String,
    val coverUrl: String,
    val deliveryFeeCents: Int,
    val minimumOrderCents: Int,
    val favorite: Boolean,
    val acceptingOrders: Boolean = true,
    val deliveryEtaMinutes: Int = 45,
    val deliveryRadiusKm: Double = 0.0,
    val pickupEnabled: Boolean = false,
    val scheduleNote: String = "",
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

data class Catalog(
    val store: Store,
    val categories: List<Category>,
    val products: List<Product>,
    val entryToken: String,
)

data class CartItem(
    val product: Product,
    val quantity: Int = 1,
    val optionIds: Set<Int> = emptySet(),
    val notes: String = "",
) {
    fun unitTotalCents(): Int {
        val optionTotal = product.modifierGroups.flatMap { it.options }
            .filter { optionIds.contains(it.id) }.sumOf { it.priceDeltaCents }
        return product.priceCents + optionTotal
    }
    fun totalCents(): Int = unitTotalCents() * quantity
}

data class OrderSummary(
    val orderNumber: Int,
    val publicToken: String,
    val storeName: String,
    val status: String,
    val statusLabel: String,
    val paymentStatus: String,
    val totalCents: Int,
    val trackingToken: String? = null,
)

data class CardMethod(
    val provider: String,
    val publicKey: String,
    val maxInstallments: Int,
)

data class PaymentMethods(
    val pixProviders: List<String>,
    val cards: List<CardMethod>,
    val cash: Boolean,
)

data class PixPayment(
    val paymentId: Int,
    val provider: String,
    val copyPaste: String,
    val imageUrl: String,
    val expiresAt: String,
)

data class TrackingStatus(
    val active: Boolean,
    val status: String,
    val statusLabel: String,
    val latitude: Double? = null,
    val longitude: Double? = null,
    val recordedAt: String? = null,
)
