package br.com.eventmenu.delivery.ui

import br.com.eventmenu.delivery.data.Address
import br.com.eventmenu.delivery.data.Store
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DelyvreUiRulesTest {
    @Test
    fun `remote images accept valid absolute https only`() {
        assertTrue(isSafeDelyvreImageUrl("https://cdn.exemplo.com/produto.jpg"))
        assertTrue(isSafeDelyvreImageUrl("HTTPS://cdn.exemplo.com/capa.webp"))
        assertFalse(isSafeDelyvreImageUrl("http://cdn.exemplo.com/produto.jpg"))
        assertFalse(isSafeDelyvreImageUrl("javascript:alert(1)"))
        assertFalse(isSafeDelyvreImageUrl("/uploads/produto.jpg"))
        assertFalse(isSafeDelyvreImageUrl("https:///sem-host.png"))
        assertFalse(isSafeDelyvreImageUrl("https://"))
        assertFalse(isSafeDelyvreImageUrl("https://usuario@cdn.exemplo.com/produto.jpg"))
    }

    @Test
    fun `image resolver keeps absolute https and normalizes EventMenu relative paths`() {
        val base = "https://go.gestao2.store/1/"
        assertEquals(
            "https://cdn.exemplo.com/produto.jpg",
            resolveDelyvreImageUrl("https://cdn.exemplo.com/produto.jpg", base),
        )
        assertEquals(
            "https://go.gestao2.store/1/uploads/produto.jpg",
            resolveDelyvreImageUrl("/uploads/produto.jpg", base),
        )
        assertEquals(
            "https://go.gestao2.store/1/uploads/cardapio/lanche%20especial.jpg",
            resolveDelyvreImageUrl("uploads/cardapio/lanche especial.jpg", base),
        )
        assertEquals(
            "https://go.gestao2.store/1/uploads/cardapio/a%C3%A7a%C3%AD.webp",
            resolveDelyvreImageUrl("uploads/cardapio/açaí.webp", base),
        )
    }

    @Test
    fun `image resolver never downgrades or accepts hostile schemes`() {
        val base = "https://go.gestao2.store/1/"
        assertNull(resolveDelyvreImageUrl("http://cdn.exemplo.com/produto.jpg", base))
        assertNull(resolveDelyvreImageUrl("//malicioso.exemplo/foto.jpg", base))
        assertNull(resolveDelyvreImageUrl("javascript:alert(1)", base))
        assertNull(resolveDelyvreImageUrl("data:image/png;base64,abc", base))
        assertNull(resolveDelyvreImageUrl("uploads/foto.jpg", "http://go.gestao2.store/1/"))
    }

    @Test
    fun `store labels use consumer language`() {
        assertEquals("Aberto", delyvreStoreStatusLabel(true))
        assertEquals("Fechado agora", delyvreStoreStatusLabel(false))
        assertEquals("Grátis", delyvreDeliveryFeeLabel(0))
        assertEquals("Grátis", delyvreDeliveryFeeLabel(-1))
        assertEquals("R$ 5,90", delyvreDeliveryFeeLabel(590))
    }

    @Test
    fun `home address uses useful customer facing summary`() {
        assertEquals("Informe onde deseja receber", delyvreAddressSummary(null))
        assertEquals(
            "Rua das Flores, 25 · Centro · Bom Jesus",
            delyvreAddressSummary(Address(label = "Casa", street = "Rua das Flores", number = "25", neighborhood = "Centro", city = "Bom Jesus")),
        )
        assertEquals("Trabalho", delyvreAddressSummary(Address(label = "Trabalho")))
    }

    @Test
    fun `home filters only use real store capabilities`() {
        val store = Store(
            tenantId = 1,
            unitId = 2,
            name = "Lanches",
            description = "",
            city = "",
            state = "",
            logoUrl = "",
            coverUrl = "",
            deliveryFeeCents = 0,
            minimumOrderCents = 0,
            favorite = false,
            acceptingOrders = true,
            pickupEnabled = true,
            categories = listOf("Hambúrguer", "Lanches"),
        )
        assertTrue(delyvreMatchesHomeFilters(store, openOnly = true, freeOnly = true, pickupOnly = true, category = "hambúrguer"))
        assertFalse(delyvreMatchesHomeFilters(store.copy(acceptingOrders = false), openOnly = true, freeOnly = false, pickupOnly = false, category = null))
        assertFalse(delyvreMatchesHomeFilters(store.copy(deliveryFeeCents = 500), openOnly = false, freeOnly = true, pickupOnly = false, category = null))
        assertFalse(delyvreMatchesHomeFilters(store, openOnly = false, freeOnly = false, pickupOnly = false, category = "Pizza"))
    }
}
