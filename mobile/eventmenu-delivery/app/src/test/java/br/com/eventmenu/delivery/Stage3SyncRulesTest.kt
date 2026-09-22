package br.com.eventmenu.delivery

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class Stage3SyncRulesTest {
    @Test
    fun `payment phases follow server states`() {
        assertEquals(PaymentPhase.Confirmed, paymentPhaseFor("paid"))
        assertEquals(PaymentPhase.Waiting, paymentPhaseFor("pending"))
        assertEquals(PaymentPhase.Waiting, paymentPhaseFor("processing"))
        assertEquals(PaymentPhase.Expired, paymentPhaseFor("expired"))
        assertEquals(PaymentPhase.Error, paymentPhaseFor("failed"))
        assertEquals(PaymentPhase.Error, paymentPhaseFor("cancelled"))
        assertEquals(PaymentPhase.Error, paymentPhaseFor("refunded"))
    }

    @Test
    fun `payment polling backs off without a terminal attempt limit`() {
        assertEquals(2_500L, paymentPollDelayMillis(0))
        assertEquals(5_000L, paymentPollDelayMillis(1))
        assertEquals(10_000L, paymentPollDelayMillis(6))
        assertEquals(15_000L, paymentPollDelayMillis(18))
        assertEquals(30_000L, paymentPollDelayMillis(38))
        assertEquals(30_000L, paymentPollDelayMillis(10_000))
    }

    @Test
    fun `tracking backs off and only official terminal order states stop it`() {
        assertEquals(10_000L, trackingPollDelayMillis(0))
        assertEquals(15_000L, trackingPollDelayMillis(6))
        assertEquals(30_000L, trackingPollDelayMillis(18))
        assertTrue(isTerminalOrderStatus("completed"))
        assertTrue(isTerminalOrderStatus("cancelled"))
        assertFalse(isTerminalOrderStatus("ready"))
        assertFalse(isTerminalOrderStatus("out_for_delivery"))
    }
}
