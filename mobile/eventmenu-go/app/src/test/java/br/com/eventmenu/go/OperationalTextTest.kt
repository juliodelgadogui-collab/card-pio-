package br.com.eventmenu.go

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class OperationalTextTest {
    @Test
    fun translatesOperationalStatusesWithoutLeakingEnums() {
        assertEquals("Saiu para entrega", OperationalText.orderStatus("out_for_delivery"))
        assertEquals("Pronto", OperationalText.orderStatus("ready"))
        assertEquals("Pago", OperationalText.paymentStatus("paid"))
        assertEquals("Aguardando pagamento", OperationalText.paymentStatus("unpaid"))
        assertEquals("Indisponível", OperationalText.deviceStatus("desktop_offline"))
        assertEquals("Impressora indisponível", OperationalText.deviceStatus("printer_unavailable"))
    }

    @Test
    fun hidesPaymentProviderBrandsFromNormalOperation() {
        assertEquals("PIX", OperationalText.paymentMethod("mercadopago"))
        assertEquals("PIX", OperationalText.paymentMethod("pagbank"))
        assertEquals("Dinheiro", OperationalText.paymentMethod("manual"))
    }

    @Test
    fun sanitizesTechnicalApiErrors() {
        val message = OperationalText.friendlyApiMessage(
            "SQLSTATE[23000] tenant_id=7 order_id=42 provider=mercadopago webhook failed",
            500,
        )

        assertEquals("Não foi possível concluir a operação agora. Tente novamente.", message)
        val lower = message.lowercase()
        assertFalse(lower.contains("sqlstate"))
        assertFalse(lower.contains("tenant"))
        assertFalse(lower.contains("provider"))
        assertFalse(lower.contains("webhook"))
    }

    @Test
    fun sessionAndNullLikeValuesStayFriendly() {
        assertEquals("Sua sessão terminou. Entre novamente.", OperationalText.friendlyApiMessage("Bearer token inválido", 401))
        assertNull(OperationalText.useful("null"))
        assertNull(OperationalText.useful("undefined"))
        assertNull(OperationalText.useful("NaN"))
        assertEquals("Cliente Carlos", OperationalText.useful(" Cliente Carlos "))
        assertTrue(OperationalText.useful("ok") != null)
    }
}