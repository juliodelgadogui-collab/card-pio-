package br.com.eventmenu.go.payments

import br.com.eventmenu.go.data.CardPresentIntent
import br.com.eventmenu.go.data.CardVerification
import br.com.eventmenu.go.data.PaymentRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow

/**
 * Orquestra servidor + SDK. O evento Approved do SDK nunca significa pedido pago.
 * Somente o retorno de verifyCardIntent() do servidor pode produzir ServerVerified.
 */
class CardPaymentCoordinator(
    private val repository: PaymentRepository,
    private val sdkResolver: (String) -> CardPresentSdk,
) {
    fun charge(
        orderId: Int,
        amountCents: Int? = null,
        paymentMethod: String,
        installmentsCount: Int = 1,
        provider: String? = null,
    ): Flow<CardPaymentEvent> = flow {
        emit(CardPaymentEvent.Preparing)
        val intent = repository.createCardIntent(
            orderId = orderId,
            amountCents = amountCents,
            paymentMethod = paymentMethod,
            installmentsCount = installmentsCount,
            provider = provider,
        )
        emit(CardPaymentEvent.IntentCreated(intent))

        val sdk = sdkResolver(intent.provider)
        require(sdk.providerCode == intent.provider) { "SDK de pagamento incompatível com o provedor selecionado." }

        try {
            sdk.initialize(intent.providerSession)
            sdk.startPayment(intent).collect { event ->
                when (event) {
                    CardSdkEvent.Preparing -> emit(CardPaymentEvent.Preparing)
                    CardSdkEvent.AwaitingCard -> emit(CardPaymentEvent.AwaitingCard)
                    CardSdkEvent.Processing -> emit(CardPaymentEvent.Processing)
                    is CardSdkEvent.Approved -> {
                        emit(CardPaymentEvent.ValidatingServer)
                        val verification = repository.verifyCardIntent(intent.intentToken, event.result)
                        emit(CardPaymentEvent.ServerVerified(verification))
                    }
                    is CardSdkEvent.Cancelled -> {
                        repository.failCardIntent(intent.intentToken, event.reason)
                        emit(CardPaymentEvent.Cancelled(event.reason))
                    }
                    is CardSdkEvent.Failed -> {
                        repository.failCardIntent(intent.intentToken, event.reason)
                        emit(CardPaymentEvent.Failed(event.reason, event.resultUnknown))
                    }
                }
            }
        } catch (error: Throwable) {
            runCatching { repository.failCardIntent(intent.intentToken, error.message ?: "Falha no SDK") }
            throw error
        } finally {
            runCatching { sdk.tearDown() }
        }
    }
}

sealed interface CardPaymentEvent {
    data object Preparing : CardPaymentEvent
    data class IntentCreated(val intent: CardPresentIntent) : CardPaymentEvent
    data object AwaitingCard : CardPaymentEvent
    data object Processing : CardPaymentEvent
    data object ValidatingServer : CardPaymentEvent
    data class ServerVerified(val verification: CardVerification) : CardPaymentEvent
    data class Cancelled(val reason: String) : CardPaymentEvent
    data class Failed(val reason: String, val resultUnknown: Boolean) : CardPaymentEvent
}
