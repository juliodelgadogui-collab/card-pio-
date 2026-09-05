package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray

class ReceiptRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun order(orderId: Int): OrderReceipt {
        val r = api.getReceipt("order", requireToken(), mapOf("order_id" to orderId.toString())).getJSONObject("receipt")
        val itemsJson = r.optJSONArray("items") ?: JSONArray()
        val paymentsJson = r.optJSONArray("payments") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsJson.length()) {
                val item = itemsJson.getJSONObject(i)
                add(ReceiptItem(item.optString("name_snapshot"), item.optDouble("quantity", 1.0), item.optInt("unit_price_cents"), item.optInt("total_cents")))
            }
        }
        val payments = buildList {
            for (i in 0 until paymentsJson.length()) {
                val p = paymentsJson.getJSONObject(i)
                add(ReceiptPayment(p.optInt("id"), p.optString("provider"), p.optString("method"), p.optInt("amount_cents"), p.optString("status"), p.optString("verified_at")))
            }
        }
        return OrderReceipt(
            receiptNumber = r.optString("receipt_number"),
            tenantName = r.optString("tenant_name"),
            orderId = r.optInt("order_id"),
            channel = r.optString("channel"),
            status = r.optString("status"),
            paymentStatus = r.optString("payment_status"),
            customerName = r.optString("customer_name"),
            customerPhone = r.optString("customer_phone"),
            tableName = r.optString("table_name"),
            subtotalCents = r.optInt("subtotal_cents"),
            discountCents = r.optInt("discount_cents"),
            deliveryFeeCents = r.optInt("delivery_fee_cents"),
            totalCents = r.optInt("total_cents"),
            paidCents = r.optInt("paid_cents"),
            createdAt = r.optString("created_at"),
            items = items,
            payments = payments,
        )
    }

    fun shareText(receipt: OrderReceipt): String = buildString {
        appendLine(receipt.tenantName)
        appendLine("EVENTMENU GO · COMPROVANTE")
        appendLine(receipt.receiptNumber)
        appendLine("Pedido #${receipt.orderId} · ${channelLabel(receipt.channel)}")
        appendLine("Data: ${receipt.createdAt}")
        if (receipt.customerName.isNotBlank()) appendLine("Cliente: ${receipt.customerName}")
        if (receipt.tableName.isNotBlank()) appendLine("Mesa: ${receipt.tableName}")
        appendLine("--------------------------------")
        receipt.items.forEach { item ->
            val qty = if (item.quantity % 1.0 == 0.0) item.quantity.toInt().toString() else item.quantity.toString()
            appendLine("${qty}x ${item.name}  ${money(item.totalCents)}")
        }
        appendLine("--------------------------------")
        appendLine("Subtotal: ${money(receipt.subtotalCents)}")
        if (receipt.discountCents > 0) appendLine("Desconto: -${money(receipt.discountCents)}")
        if (receipt.deliveryFeeCents > 0) appendLine("Entrega: ${money(receipt.deliveryFeeCents)}")
        appendLine("TOTAL: ${money(receipt.totalCents)}")
        appendLine("Pago confirmado: ${money(receipt.paidCents)}")
        appendLine("--------------------------------")
        appendLine("Pagamentos")
        receipt.payments.forEach { payment ->
            appendLine("${methodLabel(payment.method, payment.provider)} · ${money(payment.amountCents)} · ${statusLabel(payment.status)}")
        }
        appendLine("--------------------------------")
        appendLine("Confirmação gerada pelo servidor EventMenu.")
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
    private fun money(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
    private fun channelLabel(channel: String) = when (channel) { "counter" -> "Balcão"; "pickup" -> "Retirada"; "table" -> "Mesa"; "delivery" -> "Delivery"; "event" -> "Evento"; else -> channel }
    private fun methodLabel(method: String, provider: String) = when (method.lowercase()) { "cash" -> "Dinheiro"; "pix" -> "PIX"; "nfc" -> "Cartão NFC"; "card" -> "Cartão"; else -> provider.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
    private fun statusLabel(status: String) = when (status) { "paid" -> "Confirmado"; "refunded" -> "Estornado"; "partially_refunded" -> "Estorno parcial"; "duplicate_paid" -> "Duplicado"; else -> status }
}
