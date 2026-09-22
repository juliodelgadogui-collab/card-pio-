package br.com.eventmenu.delivery

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class Stage4PushRulesTest {
    @Test
    fun `accepts only customer order lifecycle pushes`() {
        assertTrue(isSupportedCustomerOrderPush("customer.order.status", "confirmed"))
        assertTrue(isSupportedCustomerOrderPush("customer.order.status", "preparing"))
        assertTrue(isSupportedCustomerOrderPush("customer.order.status", "ready"))
        assertTrue(isSupportedCustomerOrderPush("customer.order.status", "out_for_delivery"))
        assertTrue(isSupportedCustomerOrderPush("customer.order.status", "completed"))
        assertFalse(isSupportedCustomerOrderPush("marketing.campaign", "confirmed"))
        assertFalse(isSupportedCustomerOrderPush("customer.order.status", "invented_status"))
    }

    @Test
    fun `duplicate push is suppressed only inside short window`() {
        val fp = customerOrderPushFingerprint(42, "ready", "Pedido pronto", "Seu pedido está pronto")
        assertTrue(shouldSuppressDuplicatePush(fp, 1_000L, fp, 50_000L))
        assertFalse(shouldSuppressDuplicatePush(fp, 1_000L, fp, 70_001L))
        assertFalse(shouldSuppressDuplicatePush("different", 1_000L, fp, 2_000L))
    }
}
