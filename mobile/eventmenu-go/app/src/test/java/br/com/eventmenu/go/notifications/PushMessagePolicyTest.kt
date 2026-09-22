package br.com.eventmenu.go.notifications

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PushMessagePolicyTest {
    @Test
    fun expiredEpochIsRejected() {
        assertTrue(PushMessagePolicy.isExpired("999", nowEpochSeconds = 1000))
        assertTrue(PushMessagePolicy.isExpired("1000", nowEpochSeconds = 1000))
    }

    @Test
    fun futureOrMissingEpochIsAccepted() {
        assertFalse(PushMessagePolicy.isExpired("1001", nowEpochSeconds = 1000))
        assertFalse(PushMessagePolicy.isExpired(null, nowEpochSeconds = 1000))
        assertFalse(PushMessagePolicy.isExpired("   ", nowEpochSeconds = 1000))
    }

    @Test
    fun malformedExpiryIsRejected() {
        assertTrue(PushMessagePolicy.isExpired("invalid", nowEpochSeconds = 1000))
    }
}
