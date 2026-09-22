package br.com.eventmenu.delivery

enum class PaymentPhase { Idle, Waiting, Confirmed, Expired, Error }

internal fun paymentPhaseFor(status: String): PaymentPhase = when (status.trim().lowercase()) {
    "paid" -> PaymentPhase.Confirmed
    "expired" -> PaymentPhase.Expired
    "failed", "cancelled", "canceled", "refunded", "partially_refunded" -> PaymentPhase.Error
    "pending", "processing", "created", "unpaid", "authorized", "" -> PaymentPhase.Waiting
    else -> PaymentPhase.Waiting
}

internal fun paymentPollDelayMillis(attempt: Int): Long = when {
    attempt <= 0 -> 2_500L
    attempt < 6 -> 5_000L
    attempt < 18 -> 10_000L
    attempt < 38 -> 15_000L
    else -> 30_000L
}

internal fun orderPollDelayMillis(attempt: Int): Long = when {
    attempt < 6 -> 5_000L
    attempt < 18 -> 10_000L
    else -> 30_000L
}

internal fun trackingPollDelayMillis(attempt: Int): Long = when {
    attempt < 6 -> 10_000L
    attempt < 18 -> 15_000L
    else -> 30_000L
}

internal fun isTerminalOrderStatus(status: String): Boolean = status.trim().lowercase() in setOf("completed", "cancelled")
