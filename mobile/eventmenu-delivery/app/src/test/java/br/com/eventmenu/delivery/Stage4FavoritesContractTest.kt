package br.com.eventmenu.delivery

import br.com.eventmenu.delivery.data.Store
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class Stage4FavoritesContractTest {
    @Test
    fun `restaurant favorite state is explicit in store model`() {
        val store = Store(1, 1, "Loja", "", "", "", "", "", 0, 0, false)
        assertFalse(store.favorite)
        assertTrue(store.copy(favorite = true).favorite)
    }
}
