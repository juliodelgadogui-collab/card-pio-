package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.ModifierGroup
import br.com.eventmenu.delivery.data.Product
import br.com.eventmenu.delivery.data.Store
import br.com.eventmenu.delivery.money
import coil3.compose.AsyncImage

@Composable
fun DelyvreAppRoot(vm: DeliveryViewModel) {
    DelyvreTheme {
        when (vm.screen) {
            Screen.Login -> DelyvreLoginScreen(vm)
            Screen.Home -> DelyvreHomeScreen(vm)
            Screen.Catalog -> DelyvreCatalogScreen(vm)
            else -> DeliveryAppRootV2(vm)
        }
    }
}

@Composable
private fun DelyvreLoginScreen(vm: DeliveryViewModel) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it)
            vm.clearMessage()
        }
    }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 22.dp),
            verticalArrangement = Arrangement.Center,
        ) {
            DelyvreWordmark()
            Spacer(Modifier.height(28.dp))
            Text("Entre quando precisar", style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(8.dp))
            Text(
                "Use sua conta para finalizar pedidos, salvar endereços, acessar favoritos e acompanhar benefícios.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(24.dp))
            OutlinedTextField(
                value = email,
                onValueChange = { email = it.trimStart() },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("E-mail") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                singleLine = true,
            )
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Senha") },
                visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                trailingIcon = {
                    IconButton(onClick = { visible = !visible }) {
                        Icon(if (visible) Icons.Default.VisibilityOff else Icons.Default.Visibility, contentDescription = if (visible) "Ocultar senha" else "Mostrar senha")
                    }
                },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                singleLine = true,
            )
            Spacer(Modifier.height(16.dp))
            Button(
                onClick = { vm.login(email, password) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
                enabled = email.isNotBlank() && password.isNotBlank() && !vm.busy,
            ) { Text("Entrar") }
            TextButton(onClick = { vm.forgotPassword(email) }, enabled = email.isNotBlank()) {
                Text("Esqueci minha senha")
            }
            HorizontalDivider(Modifier.padding(vertical = 8.dp))
            OutlinedButton(
                onClick = { vm.navigate(Screen.Register) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 50.dp),
            ) { Text("Criar minha conta") }
            TextButton(
                onClick = { vm.navigate(Screen.Home) },
                modifier = Modifier.align(Alignment.CenterHorizontally),
            ) { Text("Continuar explorando") }
        }
    }
}

@Composable
private fun DelyvreHomeScreen(vm: DeliveryViewModel) {
    var search by remember { mutableStateOf("") }
    var freeOnly by remember { mutableStateOf(false) }
    var openOnly by remember { mutableStateOf(false) }
    var pickupOnly by remember { mutableStateOf(false) }
    var benefitsOpen by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(Unit) { vm.loadStores() }
    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it)
            vm.clearMessage()
        }
    }

    val filtered = vm.stores.filter { store ->
        (!freeOnly || store.deliveryFeeCents <= 0) &&
            (!openOnly || store.acceptingOrders) &&
            (!pickupOnly || store.pickupEnabled)
    }
    val freeDelivery = filtered.filter { it.deliveryFeeCents <= 0 }.take(6)

    if (benefitsOpen) {
        AlertDialog(
            onDismissRequest = { benefitsOpen = false },
            confirmButton = { TextButton(onClick = { benefitsOpen = false }) { Text("Entendi") } },
            icon = { Icon(Icons.Default.LocalOffer, contentDescription = null, tint = DelyvreBrand) },
            title = { Text("Benefícios") },
            text = { Text("Cupons e descontos aparecem no carrinho quando forem válidos para o restaurante e para o seu pedido. Restaurantes com entrega grátis também ficam destacados na Home.") },
        )
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            DelyvreBottomBar(
                onHome = { vm.loadStores(search) },
                onSearch = { },
                onOrders = { vm.loadOrders() },
                onBenefits = { benefitsOpen = true },
                onProfile = { vm.openProfile() },
            )
        },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    DelyvreWordmark(compact = true)
                    Spacer(Modifier.weight(1f))
                    if (vm.isAuthenticated) {
                        IconButton(onClick = { vm.openProfile() }) { Icon(Icons.Default.AccountCircle, contentDescription = "Perfil") }
                    } else {
                        TextButton(onClick = { vm.navigate(Screen.Login) }) { Text("Entrar") }
                    }
                }
            }
            item {
                Surface(
                    modifier = Modifier.fillMaxWidth(),
                    color = DelyvreInk,
                    contentColor = Color.White,
                    shape = RoundedCornerShape(28.dp),
                ) {
                    Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("DELYVRE", style = MaterialTheme.typography.labelLarge, color = Color(0xFFFFA89C))
                        Text("Escolha. Peça. Receba.", style = MaterialTheme.typography.headlineMedium)
                        Text("Descubra restaurantes, monte seu pedido e acompanhe tudo em um só lugar.", color = Color.White.copy(alpha = .78f))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            DelyvreHeroChip("Comida")
                            DelyvreHeroChip("Delivery")
                            DelyvreHeroChip("Retirada")
                        }
                    }
                }
            }
            item {
                OutlinedTextField(
                    value = search,
                    onValueChange = {
                        search = it
                        vm.loadStores(it)
                    },
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                    placeholder = { Text("Buscar comida ou restaurante") },
                    singleLine = true,
                    shape = RoundedCornerShape(18.dp),
                )
            }
            item {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item { FilterChip(selected = openOnly, onClick = { openOnly = !openOnly }, label = { Text("Abertos") }, leadingIcon = { Icon(Icons.Default.Storefront, null, Modifier.size(18.dp)) }) }
                    item { FilterChip(selected = freeOnly, onClick = { freeOnly = !freeOnly }, label = { Text("Frete grátis") }, leadingIcon = { Icon(Icons.Default.LocalShipping, null, Modifier.size(18.dp)) }) }
                    item { FilterChip(selected = pickupOnly, onClick = { pickupOnly = !pickupOnly }, label = { Text("Retirada") }, leadingIcon = { Icon(Icons.Default.ShoppingBag, null, Modifier.size(18.dp)) }) }
                }
            }
            if (freeDelivery.isNotEmpty()) {
                item { DelyvreSectionTitle("Frete grátis", "Boas escolhas sem taxa de entrega.") }
                item {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp), contentPadding = PaddingValues(end = 8.dp)) {
                        items(freeDelivery, key = { "free-${it.tenantId}-${it.unitId}" }) { store ->
                            DelyvreCompactStoreCard(store, onOpen = { vm.openStore(store) })
                        }
                    }
                }
            }
            item { DelyvreSectionTitle(if (search.isBlank()) "Restaurantes para você" else "Resultados", "${filtered.size} ${if (filtered.size == 1) "opção" else "opções"}") }
            if (filtered.isEmpty()) {
                item { DelyvreEmptyState("Nenhum restaurante encontrado", "Tente outro nome ou remova algum filtro.") }
            } else {
                items(filtered, key = { "store-${it.tenantId}-${it.unitId}" }) { store ->
                    DelyvreStoreCard(
                        store = store,
                        onOpen = { vm.openStore(store) },
                        onFavorite = { vm.toggleFavorite(store) },
                    )
                }
            }
        }
    }
}

@Composable
private fun DelyvreCatalogScreen(vm: DeliveryViewModel) {
    val catalog = vm.catalog ?: return
    var search by remember { mutableStateOf("") }
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var productToCustomize by remember { mutableStateOf<Product?>(null) }
    val cartCount = vm.cart.sumOf { it.quantity }
    val cartTotal = vm.cart.sumOf { it.totalCents() }

    productToCustomize?.let { product ->
        DelyvreProductDialog(
            product = product,
            onDismiss = { productToCustomize = null },
            onAdd = { quantity, options, notes ->
                vm.addToCart(product, quantity, options, notes)
                productToCustomize = null
            },
        )
    }

    val products = catalog.products.filter { product ->
        (categoryId == null || product.categoryId == categoryId) &&
            (search.isBlank() || product.name.contains(search, true) || product.description.contains(search, true))
    }

    Scaffold(
        bottomBar = {
            if (cartCount > 0) {
                Surface(shadowElevation = 14.dp, tonalElevation = 2.dp) {
                    Button(
                        onClick = { vm.navigate(Screen.Cart) },
                        modifier = Modifier.fillMaxWidth().padding(12.dp).heightIn(min = 52.dp),
                    ) {
                        Text("Ver pedido · $cartCount ${if (cartCount == 1) "item" else "itens"} · ${money(cartTotal)}")
                    }
                }
            }
        },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(bottom = 24.dp),
        ) {
            item {
                Box {
                    DelyvreRemoteImage(
                        url = catalog.store.coverUrl,
                        contentDescription = null,
                        modifier = Modifier.fillMaxWidth().height(220.dp),
                        icon = Icons.Default.Restaurant,
                    )
                    IconButton(
                        onClick = { vm.navigate(Screen.Home) },
                        modifier = Modifier.padding(12.dp).background(Color.White.copy(alpha = .94f), RoundedCornerShape(14.dp)),
                    ) { Icon(Icons.Default.ArrowBack, contentDescription = "Voltar") }
                }
            }
            item {
                Column(Modifier.padding(horizontal = 16.dp, vertical = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        DelyvreRemoteImage(
                            url = catalog.store.logoUrl,
                            contentDescription = "Logo ${catalog.store.name}",
                            modifier = Modifier.size(64.dp).clip(RoundedCornerShape(18.dp)),
                            icon = Icons.Default.Storefront,
                        )
                        Column(Modifier.weight(1f)) {
                            Text(catalog.store.name, style = MaterialTheme.typography.headlineSmall)
                            Text(delyvreStoreStatusLabel(catalog.store.acceptingOrders), color = if (catalog.store.acceptingOrders) DelyvreSuccess else MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold)
                        }
                    }
                    if (catalog.store.description.isNotBlank()) Text(catalog.store.description, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        DelyvreInfoPill("${catalog.store.deliveryEtaMinutes}–${catalog.store.deliveryEtaMinutes + 15} min")
                        DelyvreInfoPill("Entrega ${delyvreDeliveryFeeLabel(catalog.store.deliveryFeeCents)}")
                    }
                    if (catalog.store.minimumOrderCents > 0) Text("Pedido mínimo ${money(catalog.store.minimumOrderCents)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if (!catalog.store.acceptingOrders) {
                        Surface(color = MaterialTheme.colorScheme.errorContainer, shape = RoundedCornerShape(16.dp)) {
                            Text("O restaurante está fechado para novos pedidos agora. Você ainda pode consultar o cardápio.", Modifier.padding(13.dp), color = MaterialTheme.colorScheme.onErrorContainer)
                        }
                    }
                }
            }
            item {
                OutlinedTextField(
                    value = search,
                    onValueChange = { search = it },
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                    leadingIcon = { Icon(Icons.Default.Search, null) },
                    placeholder = { Text("Buscar no cardápio") },
                    singleLine = true,
                    shape = RoundedCornerShape(18.dp),
                )
            }
            if (catalog.categories.isNotEmpty()) {
                item {
                    LazyRow(
                        modifier = Modifier.padding(top = 12.dp),
                        contentPadding = PaddingValues(horizontal = 16.dp),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        item { FilterChip(selected = categoryId == null, onClick = { categoryId = null }, label = { Text("Tudo") }) }
                        items(catalog.categories, key = { it.id }) { category ->
                            FilterChip(selected = categoryId == category.id, onClick = { categoryId = category.id }, label = { Text(category.name) })
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(14.dp)) }
            if (products.isEmpty()) {
                item { Box(Modifier.padding(horizontal = 16.dp)) { DelyvreEmptyState("Nada por aqui", "Tente outra busca ou categoria.") } }
            } else {
                items(products, key = { it.id }) { product ->
                    DelyvreProductCard(product = product, onOpen = { productToCustomize = product })
                }
            }
        }
    }
}

@Composable
private fun DelyvreProductCard(product: Product, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = CardDefaults.outlinedCardBorder(),
        onClick = onOpen,
        enabled = product.available,
    ) {
        Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            DelyvreRemoteImage(
                url = product.imageUrl,
                contentDescription = product.name,
                modifier = Modifier.size(104.dp).clip(RoundedCornerShape(16.dp)),
                icon = Icons.Default.Fastfood,
            )
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text(product.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                if (product.description.isNotBlank()) Text(product.description, maxLines = 2, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
                Text(money(product.priceCents), fontWeight = FontWeight.Black)
                if (!product.available) Text("Indisponível", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.labelMedium)
            }
            FilledTonalIconButton(onClick = onOpen, enabled = product.available) {
                Icon(Icons.Default.Add, contentDescription = "Adicionar ${product.name}")
            }
        }
    }
}

@Composable
private fun DelyvreProductDialog(product: Product, onDismiss: () -> Unit, onAdd: (Int, Set<Int>, String) -> Unit) {
    var quantity by remember(product.id) { mutableIntStateOf(1) }
    var selected by remember(product.id) { mutableStateOf(setOf<Int>()) }
    var notes by remember(product.id) { mutableStateOf("") }
    var error by remember(product.id) { mutableStateOf<String?>(null) }

    fun toggle(group: ModifierGroup, optionId: Int) {
        val groupOptionIds = group.options.map { it.id }.toSet()
        val inGroup = selected.intersect(groupOptionIds)
        selected = if (selected.contains(optionId)) {
            selected - optionId
        } else if (group.maxSelect <= 1) {
            (selected - groupOptionIds) + optionId
        } else if (inGroup.size < group.maxSelect) {
            selected + optionId
        } else selected
        error = null
    }

    val optionDelta = product.modifierGroups.flatMap { it.options }.filter { selected.contains(it.id) }.sumOf { it.priceDeltaCents }
    val total = (product.priceCents + optionDelta) * quantity

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(product.name) },
        text = {
            Column(
                modifier = Modifier.fillMaxWidth().heightIn(max = 520.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                if (product.description.isNotBlank()) Text(product.description, color = MaterialTheme.colorScheme.onSurfaceVariant)
                product.modifierGroups.forEach { group ->
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(group.name, fontWeight = FontWeight.Bold)
                        Text(
                            if (group.required) "Obrigatório · escolha ${group.minSelect}${if (group.maxSelect > group.minSelect) " a ${group.maxSelect}" else ""}" else "Opcional · até ${group.maxSelect}",
                            style = MaterialTheme.typography.labelSmall,
                            color = if (group.required) DelyvreBrandStrong else MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        group.options.forEach { option ->
                            Surface(
                                modifier = Modifier.fillMaxWidth().clickable { toggle(group, option.id) },
                                color = if (selected.contains(option.id)) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant,
                                shape = RoundedCornerShape(14.dp),
                            ) {
                                Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                                    if (group.maxSelect <= 1) RadioButton(selected = selected.contains(option.id), onClick = { toggle(group, option.id) })
                                    else Checkbox(checked = selected.contains(option.id), onCheckedChange = { toggle(group, option.id) })
                                    Text(option.name, Modifier.weight(1f))
                                    if (option.priceDeltaCents > 0) Text("+ ${money(option.priceDeltaCents)}", fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                    }
                }
                OutlinedTextField(
                    value = notes,
                    onValueChange = { notes = it.take(300) },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("Observação") },
                    placeholder = { Text("Ex.: sem cebola") },
                    minLines = 2,
                )
                error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
                    IconButton(onClick = { quantity = (quantity - 1).coerceAtLeast(1) }) { Icon(Icons.Default.Remove, "Diminuir") }
                    Text(quantity.toString(), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                    IconButton(onClick = { quantity = (quantity + 1).coerceAtMost(99) }) { Icon(Icons.Default.Add, "Aumentar") }
                }
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } },
        confirmButton = {
            Button(onClick = {
                val invalid = product.modifierGroups.firstOrNull { group ->
                    val count = group.options.count { selected.contains(it.id) }
                    count < group.minSelect || count > group.maxSelect
                }
                if (invalid != null) error = "Revise ${invalid.name}: selecione entre ${invalid.minSelect} e ${invalid.maxSelect}."
                else onAdd(quantity, selected, notes.trim())
            }) { Text("Adicionar · ${money(total)}") }
        },
    )
}

@Composable
private fun DelyvreStoreCard(store: Store, onOpen: () -> Unit, onFavorite: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column {
            DelyvreRemoteImage(store.coverUrl, null, Modifier.fillMaxWidth().height(150.dp), Icons.Default.Restaurant)
            Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                DelyvreRemoteImage(store.logoUrl, null, Modifier.size(58.dp).clip(RoundedCornerShape(17.dp)), Icons.Default.Storefront)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(store.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    Text(delyvreStoreStatusLabel(store.acceptingOrders), color = if (store.acceptingOrders) DelyvreSuccess else MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
                    Text("${store.deliveryEtaMinutes}–${store.deliveryEtaMinutes + 15} min · ${delyvreDeliveryFeeLabel(store.deliveryFeeCents)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                IconButton(onClick = onFavorite) {
                    Icon(if (store.favorite) Icons.Default.Favorite else Icons.Default.FavoriteBorder, contentDescription = "Favoritar", tint = if (store.favorite) DelyvreBrand else MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
    }
}

@Composable
private fun DelyvreCompactStoreCard(store: Store, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.width(270.dp),
        onClick = onOpen,
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column {
            DelyvreRemoteImage(store.coverUrl, null, Modifier.fillMaxWidth().height(116.dp), Icons.Default.Restaurant)
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(store.name, fontWeight = FontWeight.Bold, maxLines = 1)
                Text("${store.deliveryEtaMinutes}–${store.deliveryEtaMinutes + 15} min · Frete grátis", style = MaterialTheme.typography.bodySmall, color = DelyvreSuccess)
            }
        }
    }
}

@Composable
private fun DelyvreRemoteImage(url: String, contentDescription: String?, modifier: Modifier, icon: androidx.compose.ui.graphics.vector.ImageVector) {
    Box(modifier.background(DelyvreSurfaceMuted), contentAlignment = Alignment.Center) {
        Icon(icon, contentDescription = null, tint = DelyvreMuted.copy(alpha = .55f), modifier = Modifier.size(30.dp))
        if (isSafeDelyvreImageUrl(url)) {
            AsyncImage(
                model = url,
                contentDescription = contentDescription,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Crop,
            )
        }
    }
}

@Composable
private fun DelyvreBottomBar(onHome: () -> Unit, onSearch: () -> Unit, onOrders: () -> Unit, onBenefits: () -> Unit, onProfile: () -> Unit) {
    NavigationBar(containerColor = Color.White) {
        NavigationBarItem(selected = true, onClick = onHome, icon = { Icon(Icons.Default.Home, null) }, label = { Text("Início") })
        NavigationBarItem(selected = false, onClick = onSearch, icon = { Icon(Icons.Default.Search, null) }, label = { Text("Buscar") })
        NavigationBarItem(selected = false, onClick = onOrders, icon = { Icon(Icons.Default.ReceiptLong, null) }, label = { Text("Pedidos") })
        NavigationBarItem(selected = false, onClick = onBenefits, icon = { Icon(Icons.Default.LocalOffer, null) }, label = { Text("Benefícios") })
        NavigationBarItem(selected = false, onClick = onProfile, icon = { Icon(Icons.Default.Person, null) }, label = { Text("Perfil") })
    }
}

@Composable
private fun DelyvreWordmark(compact: Boolean = false) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Surface(color = DelyvreBrand, contentColor = Color.White, shape = RoundedCornerShape(14.dp), modifier = Modifier.size(if (compact) 40.dp else 48.dp)) {
            Box(contentAlignment = Alignment.Center) { Text("D", fontWeight = FontWeight.Black, style = if (compact) MaterialTheme.typography.titleLarge else MaterialTheme.typography.headlineSmall) }
        }
        Column {
            Text("DELYVRE", fontWeight = FontWeight.Black, style = if (compact) MaterialTheme.typography.titleLarge else MaterialTheme.typography.headlineSmall)
            if (!compact) Text("Escolha. Peça. Receba.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun DelyvreHeroChip(label: String) {
    Surface(color = Color.White.copy(alpha = .12f), contentColor = Color.White, shape = RoundedCornerShape(999.dp)) {
        Text(label, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun DelyvreInfoPill(label: String) {
    Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(999.dp)) {
        Text(label, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun DelyvreSectionTitle(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun DelyvreEmptyState(title: String, message: String) {
    Surface(color = Color.White, shape = RoundedCornerShape(22.dp), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Default.SearchOff, null, tint = DelyvreMuted, modifier = Modifier.size(36.dp))
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
        }
    }
}
