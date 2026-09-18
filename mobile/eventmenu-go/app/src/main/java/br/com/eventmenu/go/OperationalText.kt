package br.com.eventmenu.go

object OperationalText {
    fun orderStatus(value: String): String = when (value.trim().lowercase()) {
        "draft" -> "Rascunho"
        "pending" -> "Aguardando confirmação"
        "confirmed" -> "Confirmado"
        "preparing" -> "Em preparo"
        "ready" -> "Pronto"
        "served" -> "Servido"
        "out_for_delivery" -> "Saiu para entrega"
        "arrived" -> "Entregador chegou"
        "completed" -> "Concluído"
        "delivered" -> "Entregue"
        "cancelled" -> "Cancelado"
        else -> "Em andamento"
    }

    fun paymentStatus(value: String): String = when (value.trim().lowercase()) {
        "unpaid", "created" -> "Aguardando pagamento"
        "pending", "processing" -> "Aguardando confirmação"
        "authorized" -> "Autorizado"
        "paid", "approved", "confirmed" -> "Pago"
        "failed" -> "Não foi possível concluir"
        "cancelled" -> "Cancelado"
        "refunded" -> "Estornado"
        "partially_refunded" -> "Estorno parcial"
        else -> "Em andamento"
    }

    fun paymentMethod(provider: String): String = when (provider.trim().lowercase()) {
        "manual" -> "Dinheiro"
        "pagbank", "mercadopago" -> "PIX"
        "stripe" -> "Cartão"
        else -> "Pagamento"
    }

    fun channel(value: String): String = when (value.trim().lowercase()) {
        "counter" -> "Balcão"
        "pickup" -> "Retirada"
        "delivery" -> "Entrega"
        "table" -> "Mesa / comanda"
        "event_bar" -> "Evento / bar"
        else -> "Atendimento"
    }

    fun deviceStatus(value: String): String = when (value.trim().lowercase()) {
        "online", "active", "ready" -> "Disponível"
        "offline", "disconnected", "desktop_offline" -> "Indisponível"
        "pending" -> "Aguardando autorização"
        "revoked" -> "Acesso revogado"
        "busy" -> "Em uso"
        "printer_unavailable" -> "Impressora indisponível"
        "error", "failed" -> "Precisa de atenção"
        else -> "Verificando"
    }

    fun friendlyApiMessage(message: String?, status: Int = 0): String {
        val raw = message.orEmpty().trim()
        if (status == 401) return "Sua sessão terminou. Entre novamente."
        if (status == 403) return "Você não tem permissão para realizar esta ação."
        if (raw.isBlank()) return "Não foi possível concluir esta operação."

        val lower = raw.lowercase()
        if ("database is locked" in lower || "database table is locked" in lower) {
            return "O sistema está processando outra operação. Aguarde alguns segundos e tente novamente."
        }
        if ("timeout" in lower || "timed out" in lower || "demorou para responder" in lower) {
            return "O servidor demorou para responder. Tente novamente."
        }
        if ("connection refused" in lower || "failed to connect" in lower || "could not resolve" in lower) {
            return "Não foi possível conectar ao EventMenu. Confira a internet e tente novamente."
        }
        if ("acesso negado" in lower || "unauthorized" in lower || "forbidden" in lower) {
            return "Você não tem permissão para realizar esta ação."
        }

        val technicalMarkers = listOf(
            "sqlstate", "pdoexception", "stack trace", "exception", "sqlite", "mysql", "database",
            "constraint failed", "endpoint", "payload", "json inválido", "tenant", "tenant_id", "unit_id",
            "customer_id", "order_id", "payment_id", "provider", "webhook", "bearer", "token", "binding",
            "heartbeat", "polling", "backoff", "idempotency", "namespace", "logcat", "debug", "undefined",
            "nullpointer", "null pointer", " sdk", "driver", "http 4", "http 5"
        )
        return if (technicalMarkers.any(lower::contains)) {
            if (status >= 500 || status == 0) "Não foi possível concluir a operação agora. Tente novamente."
            else "Não foi possível concluir esta ação."
        } else raw
    }

    fun useful(value: String?): String? {
        val clean = value?.trim().orEmpty()
        return clean.takeIf { it.isNotBlank() && !it.equals("null", true) && !it.equals("undefined", true) && !it.equals("nan", true) }
    }
}
