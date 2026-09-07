package br.com.eventmenu.go.payments

import br.com.eventmenu.go.data.CardPresentIntent
import br.com.eventmenu.go.data.CardProviderResult
import br.com.eventmenu.go.data.CardVerification
import br.com.eventmenu.go.data.PaymentRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow

/**
 * Orquestra servidor + SDK. O evento Approved do SDK nunca significa pedido pago.
 * Somente o retorno do servidor pode produzir ServerVerified.
 *
 * Erro de rede/resultado incerto depois da aproximação NÃO encerra a intenção como falha:
 * pode existir uma transação real no adquirente que ainda precisa ser reconciliada.
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

        sdk.initialize(intent.providerSession)
        try {
            sdk.startPayment(intent).collect { event ->
                when (event) {
                    CardSdkEvent.Preparing -> emit(CardPaymentEvent.Preparing)
                    CardSdkEvent.AwaitingCard -> emit(CardPaymentEvent.AwaitingCard)
                    CardSdkEvent.Processing -> emit(CardPaymentEvent.Processing)
                    is CardSdkEvent.Approved -> verifyOrPend(intent, event.result) { emit(it) }
                    is CardSdkEvent.ResultUnknown -> reconcileOrPend(intent, event.result) { emit(it) }
                    is CardSdkEvent.Cancelled -> {
                        repository.failCardIntent(intent.intentToken, event.reason)
                        emit(CardPaymentEvent.Cancelled(event.reason))
                    }
                    is CardSdkEvent.Failed -> {
                        repository.failCardIntent(intent.intentToken, event.reason)
                        emit(CardPaymentEvent.Failed(event.reason, resultUnknown = false))
                    }
                }
            }
        } catch (error: Throwable) {
            // Não marca como failed aqui. Uma exceção de rede pode ocorrer após o cartão
            // ter sido autorizado. A intenção fica disponível para reconciliação no servidor.
            emit(
                CardPaymentEvent.VerificationPending(
                    error.message ?: "Não foi possível confirmar o resultado. O servidor fará nova verificação.",
                )
            )
        }
    }

    suspend fun reconcile(intentToken: String): CardVerification = repository.reconcileCardIntent(intentToken)

    suspend fun tearDownProvider(provider: String) {
        sdkResolver(provider).tearDown()
    }

    private suspend fun verifyOrPend(
        intent: CardPresentIntent,
        result: CardProviderResult,
        emitEvent: suspend (CardPaymentEvent) -> Unit,
    ) {
        emitEvent(CardPaymentEvent.ValidatingServer)
        runCatching { repository.verifyCardIntent(intent.intentToken, result) }
            .onSuccess { emitEvent(CardPaymentEvent.ServerVerified(it)) }
            .onFailure {
                emitEvent(
                    CardPaymentEvent.VerificationPending(
                        it.message ?: "Pagamento processado. Aguardando confirmação do servidor.",
                    )
                )
            }
    }

    private suspend fun reconcileOrPend(
        intent: CardPresentIntent,
        result: CardProviderResult?,
        emitEvent: suspend (CardPaymentEvent) -> Unit,
    ) {
        emitEvent(CardPaymentEvent.ValidatingServer)
        val attempt = if (result != null) {
            runCatching { repository.verifyCardIntent(intent.intentToken, result) }
        } else {
            runCatching { repository.reconcileCardIntent(intent.intentToken) }
        }
        attempt
            .onSuccess { emitEvent(CardPaymentEvent.ServerVerified(it)) }
            .onFailure {
                emitEvent(
                    CardPaymentEvent.VerificationPending(
                        "Resultado ainda incerto. Não cobre novamente até o EventMenu confirmar a transação.",
                    )
                )
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
    data class VerificationPending(val reason: String) : CardPaymentEvent
    data class Cancelled(val reason: String) : CardPaymentEvent
    data class Failed(val reason: String, val resultUnknown: Boolean) : CardPaymentEvent
}
