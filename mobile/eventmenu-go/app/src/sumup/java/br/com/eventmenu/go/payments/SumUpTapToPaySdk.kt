package br.com.eventmenu.go.payments

import android.content.Context
import br.com.eventmenu.go.data.CardPresentIntent
import br.com.eventmenu.go.data.CardProviderResult
import br.com.eventmenu.go.data.CardSdkSession
import com.sumup.taptopay.TapToPay
import com.sumup.taptopay.TapToPayApiProvider
import com.sumup.taptopay.auth.AuthTokenProvider
import com.sumup.taptopay.payment.domain.model.api.AffiliateModel
import com.sumup.taptopay.payment.domain.model.api.CheckoutData
import com.sumup.taptopay.payment.domain.model.api.PaymentEvent
import com.sumup.taptopay.payment.domain.model.api.ProcessCardAs
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * Integração nativa SumUp Tap-to-Pay dentro do EventMenu GO.
 * O SDK apresenta a interface segura de aproximação/PIN e o servidor confirma a venda.
 */
class SumUpTapToPaySdk(context: Context) : CardPresentSdk {
    override val providerCode: String = "sumup"

    private val tapToPay: TapToPay = TapToPayApiProvider.provide(context.applicationContext)
    private val initMutex = Mutex()

    @Volatile private var accessToken: String = ""
    @Volatile private var affiliateKey: String = ""
    @Volatile private var initialized: Boolean = false

    private val authTokenProvider = object : AuthTokenProvider {
        override fun getAccessToken(): String = accessToken
    }

    override suspend fun initialize(session: CardSdkSession) {
        require(session.provider == providerCode) { "Sessão SumUp inválida." }
        require(session.accessToken.isNotBlank()) { "Token temporário SumUp ausente." }
        require(session.affiliateKey.isNotBlank()) { "Affiliate Key SumUp ausente." }
        accessToken = session.accessToken
        affiliateKey = session.affiliateKey
        if (initialized) return
        initMutex.withLock {
            if (initialized) return@withLock
            tapToPay.init(authTokenProvider).getOrElse { throw it }
            initialized = true
        }
    }

    override fun startPayment(intent: CardPresentIntent): Flow<CardSdkEvent> = flow {
        check(initialized) { "SumUp Tap-to-Pay ainda não foi inicializado." }
        emit(CardSdkEvent.Preparing)
        val processCardAs = when (intent.paymentMethod) {
            "debit" -> ProcessCardAs.Debit
            "credit" -> ProcessCardAs.Credit(instalments = intent.installmentsCount.coerceIn(1, 12))
            else -> error("Forma de cartão não suportada.")
        }
        val checkout = CheckoutData(
            totalAmount = intent.amountCents.toLong(),
            tipsAmount = null,
            vatAmount = null,
            clientUniqueTransactionId = intent.clientTransactionId,
            customItems = null,
            products = null,
            priceItems = null,
            processCardAs = processCardAs,
            affiliateData = AffiliateModel(
                key = affiliateKey,
                foreignTransactionId = intent.clientTransactionId,
                tags = mapOf("integration" to "eventmenu-go", "order_id" to intent.orderId.toString()),
            ),
        )
        tapToPay.startPayment(
            checkoutData = checkout,
            skipSuccessScreen = true,
            timeoutCardWaitSeconds = 120,
        ).collect { event ->
            when (event) {
                is PaymentEvent.CardRequested -> emit(CardSdkEvent.AwaitingCard)
                is PaymentEvent.CardPresented -> emit(CardSdkEvent.Processing)
                is PaymentEvent.CVMRequested -> emit(CardSdkEvent.Processing)
                is PaymentEvent.CVMPresented -> emit(CardSdkEvent.Processing)
                is PaymentEvent.TransactionDone -> {
                    val output = event.paymentOutput
                    emit(CardSdkEvent.Approved(CardProviderResult(output.txCode, output.serverTransactionId, output.merchantCode.orEmpty())))
                }
                is PaymentEvent.TransactionResultUnknown -> {
                    val candidate = event.paymentOutput?.let { output ->
                        runCatching { CardProviderResult(output.txCode, output.serverTransactionId, output.merchantCode.orEmpty()) }.getOrNull()
                    }
                    emit(CardSdkEvent.ResultUnknown(candidate))
                }
                is PaymentEvent.TransactionCanceled -> emit(CardSdkEvent.Cancelled("Pagamento cancelado no Tap-to-Pay."))
                is PaymentEvent.TransactionFailed -> emit(CardSdkEvent.Failed(event.tapToPayException?.message ?: "A SumUp informou que a transação falhou."))
                is PaymentEvent.PaymentFlowClosedSuccessfully -> Unit
                else -> Unit
            }
        }
    }

    override suspend fun tearDown() {
        initMutex.withLock {
            if (!initialized) return@withLock
            tapToPay.tearDown().getOrElse { throw it }
            initialized = false
            accessToken = ""
            affiliateKey = ""
        }
    }
}
