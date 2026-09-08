package br.com.eventmenu.go.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class TenantBrandTest {
    @Test
    fun parsesValidTenantBrand() {
        val brand = TenantBrand.fromValues(
            mapOf(
                "display_name" to "Restaurante Exemplo",
                "tagline" to "Sabor da casa",
                "primary_color" to "#123456",
                "secondary_color" to "#abcdef",
                "background_color" to "#f1f2f3",
                "surface_color" to "#ffffff",
                "text_color" to "#111111",
                "apply_app" to true,
                "show_eventmenu_brand" to false,
            )
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
        val brand = TenantBrand.fromValues(
            mapOf(
                "display_name" to "null",
                "primary_color" to "red",
                "secondary_color" to "#12",
            )
        )

        assertEquals("EventMenu", brand.displayName)
        assertEquals("#5b34d6", brand.primaryColor)
        assertEquals("#159b63", brand.secondaryColor)
    }

    @Test
    fun booleanWireValuesAreNormalized() {
        val brand = TenantBrand.fromValues(
            mapOf(
                "apply_app" to "1",
                "show_eventmenu_brand" to "false",
            )
        )

        assertTrue(brand.applyApp)
        assertFalse(brand.showEventMenuBrand)
    }
}
