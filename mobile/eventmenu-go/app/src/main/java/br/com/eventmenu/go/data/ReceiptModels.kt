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

data class GroupReceiptAllocation(
    val orderId: Int,
    val paymentId: Int,
    val amountCents: Int,
    val paymentStatus: String,
    val verifiedAt: String,
)

data class GroupReceiptItem(
    val orderItemId: Int,
    val orderId: Int,
    val name: String,
    val quantity: Double,
    val amountCents: Int,
)

data class GroupReceipt(
    val receiptNumber: String,
    val tenantName: String,
    val groupId: Int,
    val tabId: Int?,
    val tableName: String,
    val tabLabel: String,
    val operatorName: String,
    val splitType: String,
    val method: String,
    val provider: String,
    val status: String,
    val amountCents: Int,
    val confirmedCents: Int,
    val providerPaymentId: String,
    val verifiedAt: String,
    val createdAt: String,
    val allocations: List<GroupReceiptAllocation>,
    val items: List<GroupReceiptItem>,
)
