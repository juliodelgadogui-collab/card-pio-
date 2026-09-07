package br.com.eventmenu.go.payments

import br.com.eventmenu.go.data.CardPresentIntent
import br.com.eventmenu.go.data.CardProviderResult
import br.com.eventmenu.go.data.CardSdkSession
import kotlinx.coroutines.flow.Flow

/**
 * Contrato local do EventMenu para SDKs Tap-to-Pay.
 *
 * A UI nunca conhece classes de SumUp/PagBank/Cielo diretamente. Cada provedor terá
 * um adaptador que implementa este contrato. Isso mantém o fluxo Cobrar reutilizável.
 */
interface CardPresentSdk {
    val providerCode: String

    /** Atualiza a sessão em memória e inicializa o SDK apenas quando necessário. */
    suspend fun initialize(session: CardSdkSession)

    fun startPayment(intent: CardPresentIntent): Flow<CardSdkEvent>

    /** Deve ser chamado no logout/encerramento da sessão, não após cada venda. */
    suspend fun tearDown()
}

sealed interface CardSdkEvent {
    data object Preparing : CardSdkEvent
    data object AwaitingCard : CardSdkEvent
    data object Processing : CardSdkEvent
    data class Approved(val result: CardProviderResult) : CardSdkEvent
    data class ResultUnknown(val result: CardProviderResult? = null) : CardSdkEvent
    data class Cancelled(val reason: String = "Pagamento cancelado.") : CardSdkEvent
    data class Failed(val reason: String) : CardSdkEvent
}
