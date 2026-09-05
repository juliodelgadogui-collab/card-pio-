package br.com.eventmenu.go.data

data class ReceiptItem(
    val name: String,
    val quantity: Double,
    val unitPriceCents: Int,
    val totalCents: Int,
)

data class ReceiptPayment(
    val id: Int,
    val provider: String,
    val method: String,
    val amountCents: Int,
    val status: String,
    val verifiedAt: String,
)

data class OrderReceipt(
    val receiptNumber: String,
    val tenantName: String,
    val orderId: Int,
    val channel: String,
    val status: String,
    val paymentStatus: String,
    val customerName: String,
    val customerPhone: String,
    val tableName: String,
    val subtotalCents: Int,
    val discountCents: Int,
    val deliveryFeeCents: Int,
    val totalCents: Int,
    val paidCents: Int,
    val createdAt: String,
    val items: List<ReceiptItem>,
    val payments: List<ReceiptPayment>,
)
