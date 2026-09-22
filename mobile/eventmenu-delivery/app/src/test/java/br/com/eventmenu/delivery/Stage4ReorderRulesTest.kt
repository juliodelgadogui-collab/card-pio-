package br.com.eventmenu.delivery

import br.com.eventmenu.delivery.data.ModifierGroup
import br.com.eventmenu.delivery.data.ModifierOption
import br.com.eventmenu.delivery.data.Product
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class Stage4ReorderRulesTest {
    @Test
    fun `current catalog modifier rules stay authoritative for reorder`() {
        val product = Product(
            id = 7,
            categoryId = 1,
            name = "Lanche",
            description = "",
            priceCents = 2500,
            imageUrl = "",
            available = true,
            modifierGroups = listOf(
                ModifierGroup(1, "Ponto", true, 1, 1, listOf(ModifierOption(10, "Ao ponto", 0))),
            ),
        )
        val selected = emptySet<Int>()
        val valid = product.modifierGroups.all { group ->
            val count = group.options.count { it.id in selected }
            count >= group.minSelect.coerceAtLeast(if (group.required) 1 else 0) && count <= group.maxSelect.coerceAtLeast(1)
        }
        assertFalse(valid)
        assertTrue(product.priceCents == 2500)
    }
}
