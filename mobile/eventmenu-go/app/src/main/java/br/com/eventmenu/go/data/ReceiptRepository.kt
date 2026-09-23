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

    suspend fun group(groupId: Int): GroupReceipt {
        val r = api.getReceipt("group", requireToken(), mapOf("group_id" to groupId.toString())).getJSONObject("receipt")
        val allocationsJson = r.optJSONArray("allocations") ?: JSONArray()
        val itemsJson = r.optJSONArray("items") ?: JSONArray()
        val allocations = buildList {
            for (i in 0 until allocationsJson.length()) {
                val a = allocationsJson.getJSONObject(i)
                add(
                    GroupReceiptAllocation(
                        orderId = a.optInt("order_id"),
                        paymentId = a.optInt("payment_id"),
                        amountCents = a.optInt("amount_cents"),
                        paymentStatus = a.optString("payment_status"),
                        verifiedAt = a.optString("verified_at"),
                    )
                )
            }
        }
        val items = buildList {
            for (i in 0 until itemsJson.length()) {
                val item = itemsJson.getJSONObject(i)
                add(
                    GroupReceiptItem(
                        orderItemId = item.optInt("order_item_id"),
                        orderId = item.optInt("order_id"),
                        name = item.optString("name_snapshot"),
                        quantity = item.optDouble("quantity", 1.0),
                        amountCents = item.optInt("amount_cents"),
                    )
                )
            }
        }
        return GroupReceipt(
            receiptNumber = r.optString("receipt_number"),
            tenantName = r.optString("tenant_name"),
            groupId = r.optInt("group_id"),
            tabId = if (r.isNull("tab_id")) null else r.optInt("tab_id"),
            tableName = r.optString("table_name"),
            tabLabel = r.optString("tab_label"),
            operatorName = r.optString("operator_name"),
            splitType = r.optString("split_type"),
            method = r.optString("method"),
            provider = r.optString("provider"),
            status = r.optString("status"),
            amountCents = r.optInt("amount_cents"),
            confirmedCents = r.optInt("confirmed_cents"),
            providerPaymentId = r.optString("provider_payment_id"),
            verifiedAt = r.optString("verified_at"),
            createdAt = r.optString("created_at"),
            allocations = allocations,
            items = items,
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

    fun shareText(receipt: GroupReceipt): String = buildString {
        appendLine(receipt.tenantName)
        appendLine("EVENTMENU GO · COMPROVANTE DA DIVISAO")
        appendLine(receipt.receiptNumber)
        if (receipt.tableName.isNotBlank()) appendLine("Mesa: ${receipt.tableName}")
        receipt.tabId?.let { appendLine("Comanda #$it${if (receipt.tabLabel.isNotBlank()) " · ${receipt.tabLabel}" else ""}") }
        appendLine("Divisao: ${splitLabel(receipt.splitType)}")
        appendLine("Forma: ${methodLabel(receipt.method, receipt.provider)}")
        appendLine("Data: ${receipt.verifiedAt.ifBlank { receipt.createdAt }}")
        if (receipt.operatorName.isNotBlank()) appendLine("Operador: ${receipt.operatorName}")
        appendLine("--------------------------------")
        if (receipt.items.isNotEmpty()) {
            appendLine("Produtos desta parte")
            receipt.items.forEach { item ->
                val qty = if (item.quantity % 1.0 == 0.0) item.quantity.toInt().toString() else item.quantity.toString()
                appendLine("${qty}x ${item.name} · Pedido #${item.orderId} · ${money(item.amountCents)}")
            }
            appendLine("--------------------------------")
        }
        appendLine("Alocacao por pedido")
        receipt.allocations.forEach { allocation ->
            appendLine("Pedido #${allocation.orderId} · ${money(allocation.amountCents)} · ${statusLabel(allocation.paymentStatus)}")
        }
        appendLine("--------------------------------")
        appendLine("VALOR DESTA PARTE: ${money(receipt.amountCents)}")
        appendLine("Confirmado: ${money(receipt.confirmedCents)}")
        if (receipt.providerPaymentId.isNotBlank()) appendLine("Transacao: ${receipt.providerPaymentId}")
        appendLine("Status: ${statusLabel(receipt.status)}")
        appendLine("--------------------------------")
        appendLine("Pagamento distribuido pelo servidor entre os pedidos originais da comanda.")
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
    private fun money(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
    private fun channelLabel(channel: String) = when (channel) { "counter" -> "Balcão"; "pickup" -> "Retirada"; "table" -> "Mesa"; "delivery" -> "Delivery"; "event" -> "Evento"; "bar" -> "Bar"; else -> channel }
    private fun methodLabel(method: String, provider: String) = when (method.lowercase()) { "cash" -> "Dinheiro"; "pix" -> "PIX"; "nfc" -> "Cartão NFC"; "card" -> "Cartão"; else -> provider.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
    private fun splitLabel(value: String) = when (value) { "value" -> "Por valor"; "percentage" -> "Por percentual"; "person" -> "Por pessoa"; "product" -> "Por produto"; else -> value }
    private fun statusLabel(status: String) = when (status) { "paid" -> "Confirmado"; "refunded" -> "Estornado"; "partially_refunded" -> "Estorno parcial"; "duplicate_paid" -> "Duplicado"; "attention" -> "Atenção"; else -> status }
}
