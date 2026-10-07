package br.com.eventmenu.delivery.data

/**
 * Ephemeral checkout-only state. It never stores card data or credentials.
 * The value is refreshed whenever a customer order enters the payment screen.
 */
object PaymentUiContext {
    @Volatile
    var amountCents: Int = 0
        private set

    fun updateAmount(value: Int) {
        amountCents = value.coerceAtLeast(0)
    }

    fun clear() {
        amountCents = 0
    }
}
