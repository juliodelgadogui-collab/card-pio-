package br.com.eventmenu.delivery

private val customerOrderStatuses = setOf(
    "pending",
    "confirmed",
    "preparing",
    "ready",
    "out_for_delivery",
    "completed",
    "cancelled",
)

internal fun isSupportedCustomerOrderPush(type: String, status: String): Boolean {
    if (type.trim() != "customer.order.status") return false
    val normalized = status.trim().lowercase()
    return normalized.isBlank() || normalized in customerOrderStatuses
}

internal fun customerOrderPushFingerprint(
    orderId: Int,
    status: String,
    title: String,
    message: String,
): String = listOf(
    orderId.coerceAtLeast(0).toString(),
    status.trim().lowercase(),
    title.trim(),
    message.trim(),
).joinToString("|")

internal fun shouldSuppressDuplicatePush(
    previousFingerprint: String?,
    previousAtMillis: Long,
    fingerprint: String,
    nowMillis: Long,
    windowMillis: Long = 60_000L,
): Boolean = previousFingerprint == fingerprint &&
    previousAtMillis > 0L &&
    nowMillis >= previousAtMillis &&
    nowMillis - previousAtMillis <= windowMillis
