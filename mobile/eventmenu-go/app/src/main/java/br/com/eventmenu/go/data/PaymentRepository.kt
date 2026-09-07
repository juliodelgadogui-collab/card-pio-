package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONObject

/**
 * Fonte única do app para pagamentos digitais.
 *
 * Credenciais permanentes de provedor nunca são persistidas aqui. A sessão curta do SDK
 * vem do servidor somente quando o operador inicia a cobrança e permanece em memória.
 */
class PaymentRepository(
    baseUrl: String,
    deviceId: String,
    private val sessionStore: SecureSessionStore,
) {
    private val api = ApiClient(baseUrl, deviceId, sessionStore)

    suspend fun capabilities(): PaymentCapabilities {
        val root = api.getPayments("capabilities", requireToken())
        val providersJson = root.optJSONObject("providers") ?: JSONObject()
        val providers = providersJson.keys().asSequence().map { code ->
            val row = providersJson.optJSONObject(code) ?: JSONObject()
            val meta = row.optJSONObject("meta") ?: JSONObject()
            PaymentProviderCapability(
                code = code,
                label = meta.optString("label", code),
                active = row.optBoolean("active", false),
                cardPresent = meta.optBoolean("card_present", false),
                pix = meta.optBoolean("pix", false),
                connection = meta.optString("connection"),
            )
        }.sortedBy { it.label.lowercase() }.toList()
        return PaymentCapabilities(
            cardPresentProvider = root.optString("card_present_provider").takeIf { it.isNotBlank() },
            pixProvider = root.optString("pix_provider").takeIf { it.isNotBlank() },
            providers = providers,
        )
    }

    suspend fun cardSession(): CardSdkSession {
        val root = api.getPayments("card-session", requireToken())
        val provider = root.optString("provider")
        val session = root.optJSONObject("session") ?: throw ApiException("Sessão do provedor não retornada.")
        return parseSdkSession(provider, session)
    }

    suspend fun createCardIntent(
        orderId: Int,
        amountCents: Int? = null,
        paymentMethod: String,
        installmentsCount: Int = 1,
        provider: String? = null,
    ): CardPresentIntent {
        require(paymentMethod in setOf("credit", "debit")) { "Forma do cartão inválida." }
        val body = JSONObject()
            .put("order_id", orderId)
            .put("payment_method", paymentMethod)
            .put("installments_count", if (paymentMethod == "debit") 1 else installmentsCount.coerceIn(1, 12))
        amountCents?.let { body.put("amount_cents", it) }
        provider?.takeIf { it.isNotBlank() }?.let { body.put("provider", it) }
        val root = api.postPayments("card-intent", requireToken(), body)
        val data = root.getJSONObject("card_present")
        return CardPresentIntent(
            intentToken = data.getString("intent_token"),
            intentId = data.getInt("intent_id"),
            orderId = data.getInt("order_id"),
            amountCents = data.getInt("amount_cents"),
            remainingBeforeCents = data.optInt("remaining_before_cents", data.getInt("amount_cents")),
            provider = data.getString("provider"),
            paymentMethod = data.optString("payment_method", paymentMethod),
            installmentsCount = data.optInt("installments_count", 1),
            clientTransactionId = data.getString("client_transaction_id"),
            expiresAt = data.optString("expires_at"),
            providerSession = parseSdkSession(data.getString("provider"), data.optJSONObject("provider_session") ?: JSONObject()),
        )
    }

    suspend fun verifyCardIntent(intentToken: String, result: CardProviderResult): CardVerification {
        val providerResult = JSONObject()
            .put("transaction_code", result.transactionCode)
            .put("server_transaction_id", result.serverTransactionId)
            .put("merchant_code", result.merchantCode)
        val root = api.postPayments(
            "card-verify",
            requireToken(),
            JSONObject().put("intent_token", intentToken).put("provider_result", providerResult),
        )
        return parseVerification(root)
    }

    /**
     * Reconsulta o adquirente pelo identificador gerado antes da aproximação.
     * Usado quando o SDK informa TransactionResultUnknown ou quando a rede cai depois do tap.
     */
    suspend fun reconcileCardIntent(intentToken: String): CardVerification {
        val root = api.postPayments(
            "card-reconcile",
            requireToken(),
            JSONObject().put("intent_token", intentToken),
        )
        return parseVerification(root)
    }

    suspend fun failCardIntent(intentToken: String, reason: String) {
        api.postPayments(
            "card-fail",
            requireToken(),
            JSONObject().put("intent_token", intentToken).put("reason", reason.take(500)),
        )
    }

    suspend fun createPix(
        orderId: Int,
        taxId: String,
        amountCents: Int? = null,
        provider: String? = null,
    ): MultiProviderPixCharge {
        val body = JSONObject().put("order_id", orderId).put("tax_id", taxId)
        amountCents?.let { body.put("amount_cents", it) }
        provider?.takeIf { it.isNotBlank() }?.let { body.put("provider", it) }
        val data = api.postPayments("pix-create", requireToken(), body).getJSONObject("pix")
        return MultiProviderPixCharge(
            provider = data.optString("provider"),
            paymentId = data.getInt("payment_id"),
            orderId = data.getInt("order_id"),
            amountCents = data.getInt("amount_cents"),
            copyPaste = data.getString("copy_paste"),
            imageUrl = data.optString("image_url"),
            imageBase64 = data.optString("image_base64"),
            expiresAt = data.optString("expires_at"),
        )
    }

    suspend fun verifyPix(paymentId: Int): PixVerification {
        val data = api.getPayments(
            "pix-status",
            requireToken(),
            mapOf("payment_id" to paymentId.toString()),
        ).getJSONObject("pix")
        val balance = data.optJSONObject("balance") ?: JSONObject()
        return PixVerification(
            paid = data.optBoolean("paid", false),
            provider = data.optString("provider"),
            paymentId = data.optInt("payment_id", paymentId),
            status = data.optString("status"),
            remainingCents = balance.optInt("remaining_cents"),
            paymentStatus = balance.optString("payment_status"),
        )
    }

    private fun parseVerification(root: JSONObject): CardVerification {
        val balance = root.optJSONObject("balance") ?: JSONObject()
        return CardVerification(
            provider = root.optString("provider"),
            orderId = root.optInt("order_id"),
            paymentId = root.optInt("payment_id"),
            remainingCents = balance.optInt("remaining_cents"),
            paymentStatus = balance.optString("payment_status"),
        )
    }

    private fun parseSdkSession(provider: String, json: JSONObject): CardSdkSession = CardSdkSession(
        provider = provider.ifBlank { json.optString("provider") },
        accessToken = json.optString("access_token"),
        merchantCode = json.optString("merchant_code"),
        affiliateKey = json.optString("affiliate_key"),
        appId = json.optString("app_id"),
        expiresAt = json.optString("expires_at"),
        appKey = json.optString("app_key"),
        appName = json.optString("app_name", "EventMenu GO"),
        appVersion = json.optString("app_version"),
    )

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Faça login novamente.", 401)
}
