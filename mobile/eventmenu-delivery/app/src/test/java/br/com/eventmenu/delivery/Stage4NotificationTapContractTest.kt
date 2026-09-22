package br.com.eventmenu.delivery

import org.junit.Assert.assertEquals
import org.junit.Test

class Stage4NotificationTapContractTest {
    @Test
    fun `notification fingerprint keeps order identity`() {
        val fingerprint = customerOrderPushFingerprint(321, "ready", "Pedido pronto", "Seu pedido está pronto")
        assertEquals("321|ready|Pedido pronto|Seu pedido está pronto", fingerprint)
    }
}
