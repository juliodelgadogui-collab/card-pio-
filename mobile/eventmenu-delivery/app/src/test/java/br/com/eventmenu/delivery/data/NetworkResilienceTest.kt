package br.com.eventmenu.delivery.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

class NetworkResilienceTest {
    @Test
    fun `only idempotent GET reads retry automatically`() {
        val timeout = SocketTimeoutException("technical timeout")
        assertTrue(shouldRetryDeliveryRequest("GET", 0, timeout))
        assertTrue(shouldRetryDeliveryRequest("GET", 1, timeout))
        assertFalse(shouldRetryDeliveryRequest("GET", 2, timeout))
        assertFalse(shouldRetryDeliveryRequest("POST", 0, timeout))
    }

    @Test
    fun `retry backoff is bounded`() {
        assertEquals(300L, retryDelayMillis(0))
        assertEquals(900L, retryDelayMillis(1))
        assertEquals(900L, retryDelayMillis(99))
    }

    @Test
    fun `network failures never expose raw exception messages`() {
        val timeout = friendlyNetworkException(SocketTimeoutException("SocketTimeoutException raw"))
        val offline = friendlyNetworkException(UnknownHostException("host.internal"))
        val unavailable = friendlyNetworkException(ConnectException("Connection refused"))

        assertEquals("NETWORK_TIMEOUT", timeout.code)
        assertEquals("NETWORK_OFFLINE", offline.code)
        assertEquals("NETWORK_UNAVAILABLE", unavailable.code)
        assertFalse(timeout.message.orEmpty().contains("SocketTimeoutException"))
        assertFalse(offline.message.orEmpty().contains("host.internal"))
        assertFalse(unavailable.message.orEmpty().contains("Connection refused"))
    }
}
