package br.com.eventmenu.go.payments

import android.content.Context
import br.com.eventmenu.go.data.CardPresentIntent
import br.com.eventmenu.go.data.CardSdkSession
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow

/**
 * Variante de homologação sem o artefato privado da SumUp.
 * Permite instalar/testar todo o EventMenu enquanto as credenciais Maven da SumUp
 * ainda não foram liberadas. Esta variante nunca simula aprovação de pagamento.
 */
class SumUpTapToPaySdk(@Suppress("UNUSED_PARAMETER") context: Context) : CardPresentSdk {
    override val providerCode: String = "sumup"

    override suspend fun initialize(session: CardSdkSession) {
        require(session.provider == providerCode) { "Sessão SumUp inválida." }
        throw IllegalStateException(
            "Este APK é a variante de homologação sem o SDK privado da SumUp. " +
                "Cadastre SUMUP_MAVEN_USERNAME e SUMUP_MAVEN_PASSWORD no build para gerar o APK Tap to Pay."
        )
    }

    override fun startPayment(intent: CardPresentIntent): Flow<CardSdkEvent> = flow {
        emit(CardSdkEvent.Failed("Tap to Pay SumUp não está incluído nesta variante de homologação."))
    }

    override suspend fun tearDown() = Unit
}
