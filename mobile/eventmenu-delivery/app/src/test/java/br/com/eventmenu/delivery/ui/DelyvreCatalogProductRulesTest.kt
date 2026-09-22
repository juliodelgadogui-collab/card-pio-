package br.com.eventmenu.delivery.ui

import br.com.eventmenu.delivery.data.ModifierGroup
import br.com.eventmenu.delivery.data.ModifierOption
import br.com.eventmenu.delivery.data.Product
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DelyvreCatalogProductRulesTest {
    private val requiredSauce = ModifierGroup(
        id = 1,
        name = "Molho",
        required = true,
        minSelect = 1,
        maxSelect = 1,
        options = listOf(
            ModifierOption(10, "Barbecue", 0),
            ModifierOption(11, "Especial", 250),
        ),
    )

    private val extras = ModifierGroup(
        id = 2,
        name = "Adicionais",
        required = false,
        minSelect = 0,
        maxSelect = 2,
        options = listOf(
            ModifierOption(20, "Bacon", 400),
            ModifierOption(21, "Queijo", 300),
            ModifierOption(22, "Ovo", 200),
        ),
    )

    private val product = Product(
        id = 100,
        categoryId = 7,
        name = "X-Burger Especial",
        description = "Hambúrguer artesanal com queijo",
        priceCents = 2000,
        imageUrl = "https://cdn.exemplo.com/xburger.jpg",
        available = true,
        modifierGroups = listOf(requiredSauce, extras),
    )

    @Test
    fun `catalog filter matches name description and category`() {
        assertTrue(delyvreCatalogMatches(product, "burger", 7))
        assertTrue(delyvreCatalogMatches(product, "artesanal", 7))
        assertFalse(delyvreCatalogMatches(product, "pizza", 7))
        assertFalse(delyvreCatalogMatches(product, "burger", 9))
        assertTrue(delyvreCatalogMatches(product, "", null))
    }

    @Test
    fun `required modifier blocks add until minimum is selected`() {
        assertEquals("Escolha uma opção em Molho.", delyvreModifierSelectionError(product, emptySet()))
        assertNull(delyvreModifierSelectionError(product, setOf(10)))
    }

    @Test
    fun `modifier validation rejects selection above group maximum`() {
        val selected = setOf(10, 20, 21, 22)
        assertEquals("Escolha no máximo 2 opções em Adicionais.", delyvreModifierSelectionError(product, selected))
    }

    @Test
    fun `product total includes only valid selected modifier options`() {
        assertEquals(2000, delyvreProductUnitTotalCents(product, setOf(10)))
        assertEquals(2650, delyvreProductUnitTotalCents(product, setOf(11, 20)))
        assertEquals(2000, delyvreProductUnitTotalCents(product, setOf(9999)))
    }

    @Test
    fun `modifier requirement labels are consumer friendly`() {
        assertEquals("Escolha 1", delyvreModifierRequirementLabel(requiredSauce))
        assertEquals("Opcional · escolha até 2", delyvreModifierRequirementLabel(extras))
    }
}
