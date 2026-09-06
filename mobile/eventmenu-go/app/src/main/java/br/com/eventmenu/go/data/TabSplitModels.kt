package br.com.eventmenu.go.data

data class TabSplitOrder(
    val orderId: Int,
    val status: String,
    val paymentStatus: String,
    val totalCents: Int,
    val paidCents: Int,
    val remainingCents: Int,
)

data class TabSplitItem(
    val orderItemId: Int,
    val orderId: Int,
    val productId: Int?,
    val name: String,
    val quantity: Double,
    val totalCents: Int,
    val splitUsed: Boolean,
)

data class TabSplitOpenGroup(
    val id: Int,
    val method: String,
    val splitType: String,
    val amountCents: Int,
    val status: String,
)

data class TabSplitAccount(
    val tabId: Int,
    val tableName: String,
    val tabLabel: String,
    val totalCents: Int,
    val paidCents: Int,
    val remainingCents: Int,
    val orders: List<TabSplitOrder>,
    val items: List<TabSplitItem>,
    val openGroup: TabSplitOpenGroup?,
)

data class PaymentGroupAllocation(
    val orderId: Int,
    val paymentId: Int,
    val amountCents: Int,
    val paymentStatus: String,
    val orderPaymentStatus: String,
)

data class PaymentGroup(
    val id: Int,
    val tabId: Int,
    val provider: String,
    val method: String,
    val splitType: String,
    val amountCents: Int,
    val status: String,
    val providerPaymentId: String,
    val tabRemainingCents: Int,
    val allocations: List<PaymentGroupAllocation>,
)

data class GroupPixCharge(
    val groupId: Int,
    val amountCents: Int,
    val copyPaste: String,
    val expiresAt: String,
)
