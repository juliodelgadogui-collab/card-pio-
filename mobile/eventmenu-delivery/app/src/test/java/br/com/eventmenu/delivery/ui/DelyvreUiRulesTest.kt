package br.com.eventmenu.delivery.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class DelyvreUiRulesTest {
    @Test
    fun `remote images accept https only`() {
        assertTrue(isSafeDelyvreImageUrl("https://cdn.exemplo.com/produto.jpg"))
        assertTrue(isSafeDelyvreImageUrl("HTTPS://cdn.exemplo.com/capa.webp"))
        assertFalse(isSafeDelyvreImageUrl("http://cdn.exemplo.com/produto.jpg"))
        assertFalse(isSafeDelyvreImageUrl("javascript:alert(1)"))
        assertFalse(isSafeDelyvreImageUrl("/uploads/produto.jpg"))
    }

    @Test
    fun `store labels use consumer language`() {
        assertEquals("Aberto", delyvreStoreStatusLabel(true))
        assertEquals("Fechado agora", delyvreStoreStatusLabel(false))
        assertEquals("Grátis", delyvreDeliveryFeeLabel(0))
        assertEquals("R$ 5,90", delyvreDeliveryFeeLabel(590))
    }
}
