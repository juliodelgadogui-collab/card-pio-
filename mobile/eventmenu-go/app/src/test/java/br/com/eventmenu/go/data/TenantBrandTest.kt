package br.com.eventmenu.go.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class TenantBrandTest {
    @Test
    fun parsesValidTenantBrand() {
        val brand = TenantBrand.from(
            JSONObject()
                .put("display_name", "Restaurante Exemplo")
                .put("tagline", "Sabor da casa")
                .put("primary_color", "#123456")
                .put("secondary_color", "#abcdef")
                .put("background_color", "#f1f2f3")
                .put("surface_color", "#ffffff")
                .put("text_color", "#111111")
                .put("apply_app", true)
                .put("show_eventmenu_brand", false)
        )

        assertEquals("Restaurante Exemplo", brand.displayName)
        assertEquals("Sabor da casa", brand.tagline)
        assertEquals("#123456", brand.primaryColor)
        assertEquals("#abcdef", brand.secondaryColor)
        assertTrue(brand.applyApp)
        assertFalse(brand.showEventMenuBrand)
    }

    @Test
    fun invalidOrNullLikeValuesFallBackSafely() {
        val brand = TenantBrand.from(
            JSONObject()
                .put("display_name", "null")
                .put("primary_color", "red")
                .put("secondary_color", "#12")
        )

        assertEquals("EventMenu", brand.displayName)
        assertEquals("#5b34d6", brand.primaryColor)
        assertEquals("#159b63", brand.secondaryColor)
    }
}
