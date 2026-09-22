package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.Category
import br.com.eventmenu.delivery.data.ModifierGroup
import br.com.eventmenu.delivery.data.Product
import br.com.eventmenu.delivery.data.Store
import br.com.eventmenu.delivery.money

/**
 * Module 4 shell. Catalog/product screens are replaced here while every other
 * destination continues through the already validated Module 3 shell.
 */
@Composable
fun DelyvreModule4Root(vm: DeliveryViewModel) {
    val catalogKey = vm.catalog?.store?.let { "${it.tenantId}-${it.unitId}" } ?: "none"
    var search by rememberSaveable(catalogKey) { mutableStateOf("") }
    var categoryId by rememberSaveable(catalogKey) { mutableStateOf<Int?>(null) }
    var selectedProductId by rememberSaveable(catalogKey) { mutableStateOf<Int?>(null) }
    val listState = rememberLazyListState()

    LaunchedEffect(catalogKey) {
        if (catalogKey != "none") listState.scrollToItem(0)
    }

    if (vm.screen == Screen.Catalog) {
        DelyvreTheme {
            DelyvreCatalogProductScreen(
                vm = vm,
                search = search,
                onSearchChange = { search = it.take(120) },
                categoryId = categoryId,
                onCategoryChange = { categoryId = it },
                selectedProductId = selectedProductId,
                onProductSelected = { selectedProductId = it },
                listState = listState,
            )
        }
    } else {
        DelyvreModule3Root(vm)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreCatalogProductScreen(
    vm: DeliveryViewModel,
    search: String,
    onSearchChange: (String) -> Unit,
    categoryId: Int?,
    onCategoryChange: (Int?) -> Unit,
    selectedProductId: Int?,
    onProductSelected: (Int?) -> Unit,
    listState: LazyListState,
) {
    val catalog = vm.catalog ?: return
    val snackbar = remember { SnackbarHostState() }
    val selectedProduct = catalog.products.firstOrNull { it.id == selectedProductId }
    val cartCount = vm.cart.sumOf { it.quantity }
    val cartTotal = vm.cart.sumOf { it.totalCents() }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it.replace("EventMenu Delivery", "DELYVRE").replace("EventMenu", "DELYVRE"))
            vm.clearMessage()
        }
    }

    selectedProduct?.let { product ->
        DelyvreProductSheet(
            product = product,
            storeOpen = catalog.store.acceptingOrders,
            onDismiss = { onProductSelected(null) },
            onAdd = { quantity, options, notes ->
                vm.addToCart(product, quantity, options, notes)
                onProductSelected(null)
            },
        )
    }

    val filtered = remember(catalog.products, search, categoryId) {
        catalog.products.filter { delyvreCatalogMatches(it, search, categoryId) }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            if (cartCount > 0) {
                DelyvreCatalogCartBar(
                    count = cartCount,
                    totalCents = cartTotal,
                    minimumOrderCents = catalog.store.minimumOrderCents,
                    onOpenCart = { vm.navigate(Screen.Cart) },
                )
            }
        },
    ) { padding ->
        LazyColumn(
            state = listState,
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(bottom = 24.dp),
        ) {
            item { DelyvreCatalogHero(catalog.store) { vm.navigate(Screen.Home) } }
            item { DelyvreCatalogStoreSummary(catalog.store) }
            item {
                OutlinedTextField(
                    value = search,
                    onValueChange = onSearchChange,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                    leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                    trailingIcon = {
                        if (search.isNotBlank()) {
                            IconButton({ onSearchChange("") }) { Icon(Icons.Default.Close, "Limpar busca") }
                        }
                    },
                    placeholder = { Text("Buscar no cardápio") },
                    singleLine = true,
                    shape = RoundedCornerShape(18.dp),
                )
            }
            if (search.isNotBlank()) {
                item {
                    Text(
                        "${filtered.size} ${if (filtered.size == 1) "resultado" else "resultados"}",
                        modifier = Modifier.padding(horizontal = 18.dp, vertical = 5.dp),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            if (catalog.categories.isNotEmpty()) {
                item {
                    DelyvreCatalogCategories(
                        categories = catalog.categories,
                        selectedId = categoryId,
                        onSelected = onCategoryChange,
                    )
                }
            }
            item { Spacer(Modifier.height(12.dp)) }

            if (catalog.products.isEmpty()) {
                item {
                    Box(Modifier.padding(horizontal = 16.dp)) {
                        DelyvreCatalogEmpty(Icons.Default.RestaurantMenu, "Cardápio ainda não publicado", "Este restaurante ainda não disponibilizou produtos para pedido.")
                    }
                }
            } else if (filtered.isEmpty()) {
                item {
                    Box(Modifier.padding(horizontal = 16.dp)) {
                        DelyvreCatalogEmpty(Icons.Default.SearchOff, "Nada encontrado", "Tente outro nome ou selecione outra categoria.")
                    }
                }
            } else if (search.isNotBlank() || categoryId != null || catalog.categories.isEmpty()) {
                item {
                    DelyvreCatalogSectionHeader(
                        title = when {
                            search.isNotBlank() -> "Resultados"
                            categoryId != null -> catalog.categories.firstOrNull { it.id == categoryId }?.name.orEmpty().ifBlank { "Produtos" }
                            else -> "Cardápio"
                        },
                        subtitle = "${filtered.size} ${if (filtered.size == 1) "item" else "itens"}",
                    )
                }
                items(filtered, key = { "product-${it.id}" }) { product ->
                    DelyvreProductCardV4(product) { onProductSelected(product.id) }
                }
            } else {
                catalog.categories.forEach { category ->
                    val section = catalog.products.filter { it.categoryId == category.id }
                    if (section.isNotEmpty()) {
                        item(key = "category-${category.id}") {
                            DelyvreCatalogSectionHeader(category.name, "${section.size} ${if (section.size == 1) "item" else "itens"}")
                        }
                        items(section, key = { "product-${it.id}" }) { product ->
                            DelyvreProductCardV4(product) { onProductSelected(product.id) }
                        }
                    }
                }
                val knownCategoryIds = catalog.categories.map { it.id }.toSet()
                val others = catalog.products.filter { it.categoryId == null || it.categoryId !in knownCategoryIds }
                if (others.isNotEmpty()) {
                    item(key = "category-others") { DelyvreCatalogSectionHeader("Outros", "${others.size} ${if (others.size == 1) "item" else "itens"}") }
                    items(others, key = { "product-${it.id}" }) { product ->
                        DelyvreProductCardV4(product) { onProductSelected(product.id) }
                    }
                }
            }
        }
    }
}

@Composable
private fun DelyvreCatalogHero(store: Store, onBack: () -> Unit) {
    Box {
        DelyvreRemoteImage(
            url = store.coverUrl,
            contentDescription = "Capa de ${store.name}",
            modifier = Modifier.fillMaxWidth().height(205.dp),
            icon = Icons.Default.Restaurant,
        )
        Surface(
            modifier = Modifier.padding(12.dp),
            color = MaterialTheme.colorScheme.surface.copy(alpha = .95f),
            shape = RoundedCornerShape(14.dp),
            shadowElevation = 3.dp,
        ) {
            IconButton(onBack) { Icon(Icons.Default.ArrowBack, "Voltar") }
        }
        Surface(
            modifier = Modifier.align(Alignment.BottomStart).padding(16.dp),
            color = if (store.acceptingOrders) DelyvreSuccess.copy(alpha = .94f) else MaterialTheme.colorScheme.error.copy(alpha = .94f),
            contentColor = MaterialTheme.colorScheme.surface,
            shape = RoundedCornerShape(999.dp),
        ) {
            Row(Modifier.padding(horizontal = 11.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                Icon(if (store.acceptingOrders) Icons.Default.CheckCircle else Icons.Default.Schedule, null, Modifier.size(16.dp))
                Text(if (store.acceptingOrders) "Aberto para pedidos" else "Fechado agora", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun DelyvreCatalogStoreSummary(store: Store) {
    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            DelyvreRemoteImage(
                url = store.logoUrl,
                contentDescription = "Logo ${store.name}",
                modifier = Modifier.size(68.dp).clip(RoundedCornerShape(20.dp)),
                icon = Icons.Default.Storefront,
            )
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(store.name, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                val place = listOf(store.city, store.state).filter { it.isNotBlank() }.joinToString(" · ")
                if (place.isNotBlank()) Text(place, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        if (store.description.isNotBlank()) Text(store.description, color = MaterialTheme.colorScheme.onSurfaceVariant)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (store.deliveryEtaMinutes > 0) item { DelyvreCatalogPill(Icons.Default.Schedule, "${store.deliveryEtaMinutes}–${store.deliveryEtaMinutes + 15} min") }
            item { DelyvreCatalogPill(Icons.Default.LocalShipping, "Entrega ${delyvreDeliveryFeeLabel(store.deliveryFeeCents)}") }
            if (store.minimumOrderCents > 0) item { DelyvreCatalogPill(Icons.Default.ShoppingBag, "Mínimo ${money(store.minimumOrderCents)}") }
            if (store.pickupEnabled) item { DelyvreCatalogPill(Icons.Default.Storefront, "Retirada") }
        }
        if (store.scheduleNote.isNotBlank()) Text(store.scheduleNote, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        if (!store.acceptingOrders) {
            Surface(color = MaterialTheme.colorScheme.errorContainer, shape = RoundedCornerShape(16.dp)) {
                Row(Modifier.fillMaxWidth().padding(13.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Info, null, tint = MaterialTheme.colorScheme.onErrorContainer)
                    Text("Você pode consultar o cardápio, mas novos pedidos estão pausados agora.", color = MaterialTheme.colorScheme.onErrorContainer, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

@Composable
private fun DelyvreCatalogCategories(categories: List<Category>, selectedId: Int?, onSelected: (Int?) -> Unit) {
    LazyRow(
        modifier = Modifier.padding(top = 12.dp),
        contentPadding = PaddingValues(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        item { FilterChip(selected = selectedId == null, onClick = { onSelected(null) }, label = { Text("Tudo") }) }
        items(categories, key = { it.id }) { category ->
            FilterChip(selected = selectedId == category.id, onClick = { onSelected(category.id) }, label = { Text(category.name) })
        }
    }
}

@Composable
private fun DelyvreProductCardV4(product: Product, onOpen: () -> Unit) {
    val customizable = product.modifierGroups.isNotEmpty()
    Card(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp).alpha(if (product.available) 1f else .64f),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box {
                DelyvreRemoteImage(
                    url = product.imageUrl,
                    contentDescription = product.name,
                    modifier = Modifier.size(112.dp).clip(RoundedCornerShape(18.dp)),
                    icon = Icons.Default.Fastfood,
                )
                if (!product.available) {
                    Surface(
                        modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
                        color = MaterialTheme.colorScheme.errorContainer.copy(alpha = .96f),
                        contentColor = MaterialTheme.colorScheme.onErrorContainer,
                    ) { Text("Indisponível", Modifier.padding(vertical = 4.dp), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold) }
                }
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text(product.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                if (product.description.isNotBlank()) {
                    Text(product.description, maxLines = 2, overflow = TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
                }
                Text(money(product.priceCents), fontWeight = FontWeight.Black, color = DelyvreBrandStrong)
                if (customizable) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        Icon(Icons.Default.Tune, null, Modifier.size(15.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text("Personalizável", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            FilledTonalIconButton(onOpen) {
                Icon(if (customizable) Icons.Default.ChevronRight else Icons.Default.Add, contentDescription = "Ver ${product.name}")
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DelyvreProductSheet(
    product: Product,
    storeOpen: Boolean,
    onDismiss: () -> Unit,
    onAdd: (Int, Set<Int>, String) -> Unit,
) {
    var quantity by remember(product.id) { mutableIntStateOf(1) }
    var selected by remember(product.id) { mutableStateOf(emptySet<Int>()) }
    var notes by remember(product.id) { mutableStateOf("") }
    val selectionError = remember(product, selected) { delyvreModifierSelectionError(product, selected) }
    val unitTotal = remember(product, selected) { delyvreProductUnitTotalCents(product, selected) }
    val total = unitTotal * quantity

    fun toggle(group: ModifierGroup, optionId: Int) {
        val groupIds = group.options.map { it.id }.toSet()
        if (optionId !in groupIds) return
        val selectedInGroup = selected.intersect(groupIds)
        selected = when {
            optionId in selected -> selected - optionId
            group.maxSelect <= 1 -> (selected - groupIds) + optionId
            selectedInGroup.size < group.maxSelect -> selected + optionId
            else -> selected
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, dragHandle = { BottomSheetDefaults.DragHandle() }) {
        Column(Modifier.fillMaxWidth().fillMaxHeight(.92f)) {
            LazyColumn(
                modifier = Modifier.weight(1f),
                contentPadding = PaddingValues(start = 18.dp, end = 18.dp, bottom = 18.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                item {
                    DelyvreRemoteImage(
                        url = product.imageUrl,
                        contentDescription = product.name,
                        modifier = Modifier.fillMaxWidth().height(235.dp).clip(RoundedCornerShape(24.dp)),
                        icon = Icons.Default.Fastfood,
                    )
                }
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            Text(product.name, Modifier.weight(1f), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                            Text(money(product.priceCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, color = DelyvreBrandStrong)
                        }
                        if (product.description.isNotBlank()) Text(product.description, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        if (!product.available) DelyvreProductNotice("Este item está indisponível no momento.", error = true)
                        else if (!storeOpen) DelyvreProductNotice("O restaurante está fechado para novos pedidos agora.", error = true)
                    }
                }
                product.modifierGroups.forEach { group ->
                    item(key = "group-${group.id}") {
                        DelyvreModifierGroupBlock(group = group, selected = selected, onToggle = { toggle(group, it) })
                    }
                }
                item {
                    OutlinedTextField(
                        value = notes,
                        onValueChange = { notes = it.take(300) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Observação · opcional") },
                        placeholder = { Text("Ex.: sem cebola, molho separado") },
                        supportingText = { Text("${notes.length}/300") },
                        minLines = 2,
                        maxLines = 4,
                    )
                }
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Quantidade", fontWeight = FontWeight.Bold)
                        Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(18.dp)) {
                            Row(Modifier.fillMaxWidth().padding(8.dp), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                                FilledTonalIconButton({ quantity = (quantity - 1).coerceAtLeast(1) }, enabled = quantity > 1) { Icon(Icons.Default.Remove, "Diminuir") }
                                Text(quantity.toString(), Modifier.padding(horizontal = 24.dp), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                                FilledTonalIconButton({ quantity = (quantity + 1).coerceAtMost(99) }, enabled = quantity < 99) { Icon(Icons.Default.Add, "Aumentar") }
                            }
                        }
                    }
                }
                if (selectionError != null) item { DelyvreProductNotice(selectionError, error = false) }
            }
            Surface(shadowElevation = 12.dp, tonalElevation = 2.dp) {
                Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Total", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(money(total), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium)
                    }
                    Button(
                        onClick = { onAdd(quantity, selected, notes.trim()) },
                        modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp),
                        enabled = product.available && storeOpen && selectionError == null,
                    ) {
                        Icon(Icons.Default.AddShoppingCart, null)
                        Spacer(Modifier.width(8.dp))
                        Text(
                            when {
                                !product.available -> "Item indisponível"
                                !storeOpen -> "Restaurante fechado"
                                selectionError != null -> "Complete as opções obrigatórias"
                                else -> "Adicionar · ${money(total)}"
                            },
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun DelyvreModifierGroupBlock(group: ModifierGroup, selected: Set<Int>, onToggle: (Int) -> Unit) {
    val groupIds = group.options.map { it.id }.toSet()
    val selectedCount = selected.count { it in groupIds }
    val groupMax = group.maxSelect.coerceAtLeast(1)
    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(group.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    Text(delyvreModifierRequirementLabel(group), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                if (group.required || group.minSelect > 0) {
                    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) {
                        Text("Obrigatório", Modifier.padding(horizontal = 9.dp, vertical = 5.dp), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onPrimaryContainer, fontWeight = FontWeight.Bold)
                    }
                }
            }
            group.options.forEach { option ->
                val checked = option.id in selected
                val maxReached = !checked && selectedCount >= groupMax
                Surface(
                    modifier = Modifier.fillMaxWidth().clickable(enabled = !maxReached) { onToggle(option.id) },
                    color = if (checked) MaterialTheme.colorScheme.primaryContainer else DelyvreSurfaceMuted,
                    shape = RoundedCornerShape(14.dp),
                ) {
                    Row(Modifier.padding(horizontal = 10.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        if (groupMax <= 1) RadioButton(checked, { onToggle(option.id) }, enabled = !maxReached)
                        else Checkbox(checked, { onToggle(option.id) }, enabled = !maxReached)
                        Text(option.name, Modifier.weight(1f), fontWeight = if (checked) FontWeight.SemiBold else FontWeight.Normal)
                        Text(if (option.priceDeltaCents > 0) "+ ${money(option.priceDeltaCents)}" else "Incluso", style = MaterialTheme.typography.bodySmall, color = if (option.priceDeltaCents > 0) DelyvreBrandStrong else MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            groupMaxReachedMessage(group, selectedCount)?.let {
                Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun DelyvreCatalogCartBar(count: Int, totalCents: Int, minimumOrderCents: Int, onOpenCart: () -> Unit) {
    Surface(shadowElevation = 12.dp, tonalElevation = 2.dp) {
        Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (minimumOrderCents > 0 && totalCents < minimumOrderCents) {
                val missing = minimumOrderCents - totalCents
                Text("Faltam ${money(missing)} para o pedido mínimo", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                LinearProgressIndicator(progress = { (totalCents.toFloat() / minimumOrderCents.toFloat()).coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth())
            }
            Button(onOpenCart, Modifier.fillMaxWidth().heightIn(min = 54.dp)) {
                Icon(Icons.Default.ShoppingBag, null)
                Spacer(Modifier.width(8.dp))
                Text("Ver pedido · $count ${if (count == 1) "item" else "itens"} · ${money(totalCents)}", fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun DelyvreCatalogPill(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String) {
    Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(999.dp)) {
        Row(Modifier.padding(horizontal = 10.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            Icon(icon, null, Modifier.size(15.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(text, style = MaterialTheme.typography.labelMedium)
        }
    }
}

@Composable
private fun DelyvreCatalogSectionHeader(title: String, subtitle: String) {
    Column(Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 7.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun DelyvreCatalogEmpty(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, message: String) {
    Surface(color = MaterialTheme.colorScheme.surface, shape = RoundedCornerShape(22.dp), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, null, Modifier.size(36.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DelyvreProductNotice(text: String, error: Boolean) {
    Surface(color = if (error) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.secondaryContainer, shape = RoundedCornerShape(15.dp)) {
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(if (error) Icons.Default.Info else Icons.Default.Tune, null, tint = if (error) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSecondaryContainer)
            Text(text, style = MaterialTheme.typography.bodySmall, color = if (error) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSecondaryContainer)
        }
    }
}

internal fun delyvreCatalogMatches(product: Product, query: String, categoryId: Int?): Boolean {
    if (categoryId != null && product.categoryId != categoryId) return false
    val normalized = query.trim()
    if (normalized.isBlank()) return true
    return product.name.contains(normalized, ignoreCase = true) || product.description.contains(normalized, ignoreCase = true)
}

internal fun delyvreModifierSelectionError(product: Product, selected: Set<Int>): String? {
    for (group in product.modifierGroups) {
        val count = group.options.count { it.id in selected }
        val minimum = group.minSelect.coerceAtLeast(if (group.required) 1 else 0)
        val maximum = group.maxSelect.coerceAtLeast(minimum)
        if (count < minimum) {
            return if (minimum == 1) "Escolha uma opção em ${group.name}." else "Escolha pelo menos $minimum opções em ${group.name}."
        }
        if (count > maximum) return "Escolha no máximo $maximum opções em ${group.name}."
    }
    return null
}

internal fun delyvreProductUnitTotalCents(product: Product, selected: Set<Int>): Int {
    val validOptions = product.modifierGroups.flatMap { it.options }
    return product.priceCents + validOptions.filter { it.id in selected }.sumOf { it.priceDeltaCents }
}

internal fun delyvreModifierRequirementLabel(group: ModifierGroup): String {
    val minimum = group.minSelect.coerceAtLeast(if (group.required) 1 else 0)
    val maximum = group.maxSelect.coerceAtLeast(minimum)
    return when {
        minimum > 0 && minimum == maximum -> "Escolha $minimum"
        minimum > 0 -> "Escolha de $minimum a $maximum"
        maximum <= 1 -> "Opcional · escolha até 1"
        else -> "Opcional · escolha até $maximum"
    }
}

private fun groupMaxReachedMessage(group: ModifierGroup, selectedCount: Int): String? {
    val maximum = group.maxSelect.coerceAtLeast(group.minSelect).coerceAtLeast(1)
    return if (selectedCount >= maximum && group.options.size > maximum) "Limite de $maximum ${if (maximum == 1) "opção" else "opções"} atingido." else null
}
