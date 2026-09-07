package br.com.eventmenu.go.data

data class PaymentProviderCapability(
    val code: String,
    val label: String,
    val active: Boolean,
    val cardPresent: Boolean,
    val pix: Boolean,
    val connection: String,
)

data class PaymentCapabilities(
    val cardPresentProvider: String?,
    val pixProvider: String?,
    val providers: List<PaymentProviderCapability>,
)

data class CardSdkSession(
    val provider: String,
    val accessToken: String = "",
    val merchantCode: String = "",
    val affiliateKey: String = "",
    val appId: String = "",
    val expiresAt: String = "",
    val appKey: String = "",
    val appName: String = "EventMenu GO",
    val appVersion: String = "",
)

data class CardPresentIntent(
    val intentToken: String,
    val intentId: Int,
    val orderId: Int,
    val amountCents: Int,
    val remainingBeforeCents: Int,
    val provider: String,
    val paymentMethod: String,
    val installmentsCount: Int,
    val clientTransactionId: String,
    val expiresAt: String,
    val providerSession: CardSdkSession,
)

data class CardProviderResult(
    val transactionCode: String = "",
    val serverTransactionId: String = "",
    val merchantCode: String = "",
) {
    init {
        require(transactionCode.isNotBlank() || serverTransactionId.isNotBlank()) {
            "A transação do provedor precisa ter um identificador."
        }
    }
}

data class CardVerification(
    val provider: String,
    val orderId: Int,
    val paymentId: Int,
    val remainingCents: Int,
    val paymentStatus: String,
)

data class MultiProviderPixCharge(
    val provider: String,
    val paymentId: Int,
    val orderId: Int,
    val amountCents: Int,
    val copyPaste: String,
    val imageUrl: String,
    val imageBase64: String,
    val expiresAt: String,
)

data class PixVerification(
    val paid: Boolean,
    val provider: String,
    val paymentId: Int,
    val status: String,
    val remainingCents: Int,
    val paymentStatus: String,
)
