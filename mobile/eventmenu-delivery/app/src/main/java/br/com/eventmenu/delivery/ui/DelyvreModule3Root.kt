package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.MainDestination
import br.com.eventmenu.delivery.MarketplaceLoadIssue
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.Address
import br.com.eventmenu.delivery.data.OrderSummary
import br.com.eventmenu.delivery.data.Store
import br.com.eventmenu.delivery.money
import kotlinx.coroutines.flow.distinctUntilChanged

/**
 * Module 3 shell. Main customer destinations live here so the four-item
 * navigation is consistent without changing the existing order/cart flows.
 */
@Composable
fun DelyvreModule3Root(vm: DeliveryViewModel) {
    when {
        vm.screen == Screen.Home -> DelyvreTheme {
            if (vm.mainDestination == MainDestination.Search) DelyvreSearchTab(vm) else DelyvreHomeTab(vm)
        }
        vm.screen == Screen.Orders -> DelyvreTheme { DelyvreOrdersTab(vm) }
        vm.screen == Screen.Profile -> DelyvreTheme { DelyvreProfileTab(vm) }
        else -> DelyvreAccountExperienceRoot(vm)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreHomeTab(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    DelyvreModule3Messages(vm, snackbar)
    val listState = rememberLazyListState(vm.homeScrollIndex, vm.homeScrollOffset)
    val address = vm.customer?.addresses?.firstOrNull { it.isDefault } ?: vm.customer?.addresses?.firstOrNull()
    val categories = remember(vm.stores) {
        vm.stores.flatMap { it.categories }.map(String::trim).filter(String::isNotBlank).distinctBy(String::lowercase).sorted()
    }
    val filtered = remember(vm.stores, vm.homeOpenOnly, vm.homeFreeOnly, vm.homePickupOnly, vm.homeCategory) {
        vm.stores.filter {
            delyvreMatchesHomeFilters(it, vm.homeOpenOnly, vm.homeFreeOnly, vm.homePickupOnly, vm.homeCategory)
        }
    }
    val favorites = filtered.filter { it.favorite }.take(8)
    val openNow = filtered.filter { it.acceptingOrders }.take(8)

    LaunchedEffect(Unit) { vm.loadHome() }
    LaunchedEffect(listState) {
        snapshotFlow { listState.firstVisibleItemIndex to listState.firstVisibleItemScrollOffset }
            .distinctUntilChanged()
            .collect { (index, offset) -> vm.rememberHomeScroll(index, offset) }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = { DelyvreMainBottomBar(vm, MainDestination.Home) },
    ) { padding ->
        LazyColumn(
            state = listState,
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 10.dp, bottom = 26.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item {
                DelyvreHomeHeader(
                    address = address,
                    authenticated = vm.isAuthenticated,
                    onAddress = {
                        if (!vm.isAuthenticated) vm.navigate(Screen.Login)
                        else if (address == null) vm.editAddress()
                        else vm.openProfile()
                    },
                    onProfile = { vm.navigateMain(MainDestination.Profile) },
                )
            }
            item {
                DelyvreSearchLauncher { vm.navigateMain(MainDestination.Search) }
            }
            if (categories.isNotEmpty()) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("Categorias", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(categories, key = { it.lowercase() }) { category ->
                                FilterChip(
                                    selected = vm.homeCategory?.equals(category, ignoreCase = true) == true,
                                    onClick = { vm.selectHomeCategory(category) },
                                    label = { Text(category) },
                                )
                            }
                        }
                    }
                }
            }
            item {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item {
                        FilterChip(
                            selected = vm.homeOpenOnly,
                            onClick = vm::toggleHomeOpenOnly,
                            label = { Text("Abertos") },
                            leadingIcon = { Icon(Icons.Default.Storefront, null, Modifier.size(18.dp)) },
                        )
                    }
                    item {
                        FilterChip(
                            selected = vm.homeFreeOnly,
                            onClick = vm::toggleHomeFreeOnly,
                            label = { Text("Entrega grátis") },
                            leadingIcon = { Icon(Icons.Default.LocalShipping, null, Modifier.size(18.dp)) },
                        )
                    }
                    item {
                        FilterChip(
                            selected = vm.homePickupOnly,
                            onClick = vm::toggleHomePickupOnly,
                            label = { Text("Retirada") },
                            leadingIcon = { Icon(Icons.Default.ShoppingBag, null, Modifier.size(18.dp)) },
                        )
                    }
                }
            }

            if (vm.marketplaceLoading && vm.stores.isEmpty()) {
                items(3) { DelyvreStoreSkeleton() }
            } else if (vm.marketplaceIssue != null && vm.stores.isEmpty()) {
                item { DelyvreMarketplaceProblem(vm.marketplaceIssue, vm::retryMarketplace) }
            } else if (vm.stores.isEmpty()) {
                item { DelyvreStateCard(Icons.Default.Storefront, "Ainda não há restaurantes por aqui", "Assim que houver estabelecimentos disponíveis, eles aparecerão nesta tela.") }
            } else {
                if (favorites.isNotEmpty()) {
                    item { DelyvreSectionHeading("Seus favoritos", "Acesso rápido aos restaurantes que você salvou.") }
                    item {
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp), contentPadding = PaddingValues(end = 4.dp)) {
                            items(favorites, key = { "fav-${it.tenantId}-${it.unitId}" }) { store ->
                                DelyvreCompactStoreV3(store) { vm.openStore(store) }
                            }
                        }
                    }
                }
                if (vm.isAuthenticated && vm.orders.isNotEmpty()) {
                    item { DelyvreSectionHeading("Pedidos recentes", "Acompanhe ou consulte seus últimos pedidos.") }
                    item {
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp), contentPadding = PaddingValues(end = 4.dp)) {
                            items(vm.orders.take(5), key = { "recent-${it.orderNumber}" }) { order ->
                                DelyvreRecentOrderCard(order) { vm.openOrder(order.orderNumber) }
                            }
                        }
                    }
                }
                if (openNow.isNotEmpty()) {
                    item { DelyvreSectionHeading("Abertos agora", "Restaurantes aceitando pedidos neste momento.") }
                    item {
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp), contentPadding = PaddingValues(end = 4.dp)) {
                            items(openNow, key = { "open-${it.tenantId}-${it.unitId}" }) { store ->
                                DelyvreCompactStoreV3(store) { vm.openStore(store) }
                            }
                        }
                    }
                }
                item {
                    DelyvreSectionHeading(
                        if (vm.homeCategory.isNullOrBlank()) "Restaurantes" else vm.homeCategory.orEmpty(),
                        if (filtered.size == 1) "1 opção encontrada" else "${filtered.size} opções encontradas",
                    )
                }
                if (filtered.isEmpty()) {
                    item { DelyvreStateCard(Icons.Default.SearchOff, "Nenhuma opção com esses filtros", "Remova um filtro ou escolha outra categoria para continuar.") }
                } else {
                    items(filtered, key = { "store-${it.tenantId}-${it.unitId}" }) { store ->
                        DelyvreStoreCardV3(store, { vm.openStore(store) }, { vm.toggleFavorite(store) })
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreSearchTab(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    DelyvreModule3Messages(vm, snackbar)
    val results = if (vm.storeSearchQuery.isBlank()) vm.stores else vm.storeSearchResults

    LaunchedEffect(Unit) {
        if (vm.stores.isEmpty() && !vm.marketplaceLoading) vm.loadStores()
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Buscar", fontWeight = FontWeight.Bold) }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) },
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = { DelyvreMainBottomBar(vm, MainDestination.Search) },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                OutlinedTextField(
                    value = vm.storeSearchQuery,
                    onValueChange = vm::updateMarketplaceSearch,
                    modifier = Modifier.fillMaxWidth(),
                    leadingIcon = { Icon(Icons.Default.Search, null) },
                    trailingIcon = {
                        if (vm.storeSearchQuery.isNotBlank()) IconButton(vm::clearMarketplaceSearch) { Icon(Icons.Default.Close, "Limpar busca") }
                    },
                    placeholder = { Text("Buscar restaurantes ou produtos") },
                    supportingText = { Text("A busca aguarda você terminar de digitar antes de consultar.") },
                    singleLine = true,
                    shape = RoundedCornerShape(18.dp),
                )
            }
            if (vm.storeSearchLoading) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
            if (vm.storeSearchIssue != null) {
                item { DelyvreMarketplaceProblem(vm.storeSearchIssue, vm::retryMarketplaceSearch) }
            } else if (!vm.storeSearchLoading && vm.storeSearchQuery.isNotBlank() && results.isEmpty()) {
                item { DelyvreStateCard(Icons.Default.SearchOff, "Nada encontrado", "Tente outro nome de restaurante, produto ou termo de busca.") }
            } else if (vm.marketplaceIssue != null && vm.storeSearchQuery.isBlank() && results.isEmpty()) {
                item { DelyvreMarketplaceProblem(vm.marketplaceIssue, vm::retryMarketplace) }
            } else if (!vm.marketplaceLoading) {
                item {
                    DelyvreSectionHeading(
                        if (vm.storeSearchQuery.isBlank()) "Explore restaurantes" else "Resultados",
                        if (results.size == 1) "1 opção" else "${results.size} opções",
                    )
                }
                items(results, key = { "search-${it.tenantId}-${it.unitId}" }) { store ->
                    DelyvreStoreCardV3(store, { vm.openStore(store) }, { vm.toggleFavorite(store) })
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreOrdersTab(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    DelyvreModule3Messages(vm, snackbar)
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Pedidos", fontWeight = FontWeight.Bold) },
                actions = { IconButton(vm::refreshOrders) { Icon(Icons.Default.Refresh, "Atualizar pedidos") } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas),
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = { DelyvreMainBottomBar(vm, MainDestination.Orders) },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item { DelyvreSectionHeading("Acompanhe seus pedidos", "Status e pagamentos em um só lugar.") }
                if (vm.orders.isEmpty() && !vm.busy) {
                    item { DelyvreStateCard(Icons.Default.ReceiptLong, "Nenhum pedido ainda", "Quando você fizer um pedido, ele aparecerá aqui.") }
                } else {
                    items(vm.orders, key = { it.orderNumber }) { order ->
                        DelyvreOrderCardV3(order) { vm.openOrder(order.orderNumber) }
                    }
                }
            }
            if (vm.busy) DelyvreBusyOverlay()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreProfileTab(vm: DeliveryViewModel) {
    val customer = vm.customer
    val snackbar = remember { SnackbarHostState() }
    var editing by remember { mutableStateOf(false) }
    DelyvreModule3Messages(vm, snackbar)

    if (editing && customer != null) {
        DelyvreProfileEditor(
            customerName = customer.name,
            customerPhone = customer.phone,
            cpfConfigured = customer.cpfConfigured,
            cpfMasked = customer.cpfMasked,
            onDismiss = { editing = false },
            onSave = { name, phone, cpf ->
                if (customer.cpfConfigured) vm.saveProfile(name, phone) else vm.saveProfile(name, phone, cpf)
                editing = false
            },
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Perfil", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    if (vm.canReturnFromProfile) IconButton(vm::returnFromProfile) { Icon(Icons.Default.ArrowBack, "Voltar ao pagamento") }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas),
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = { DelyvreMainBottomBar(vm, MainDestination.Profile) },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                if (customer == null && !vm.busy) {
                    item { DelyvreStateCard(Icons.Default.Person, "Não foi possível carregar seu perfil", "Tente entrar novamente para acessar seus dados.") }
                } else if (customer != null) {
                    item {
                        Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = CardDefaults.outlinedCardBorder()) {
                            Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) {
                                        Icon(Icons.Default.Person, null, Modifier.padding(12.dp).size(28.dp), tint = DelyvreBrand)
                                    }
                                    Column(Modifier.weight(1f)) {
                                        Text(customer.name.ifBlank { "Minha conta" }, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                                        Text(customer.email, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    IconButton({ editing = true }) { Icon(Icons.Default.Edit, "Editar dados") }
                                }
                                if (customer.phone.isNotBlank()) Text(customer.phone, style = MaterialTheme.typography.bodyMedium)
                                Text(
                                    if (customer.cpfConfigured) "CPF ${customer.cpfMasked.ifBlank { "configurado" }}" else "CPF pendente para alguns pagamentos",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                    }
                    item {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("Endereços", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                                Text("Escolha e mantenha seus locais de entrega atualizados.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            FilledTonalIconButton({ vm.editAddress() }) { Icon(Icons.Default.Add, "Adicionar endereço") }
                        }
                    }
                    if (customer.addresses.isEmpty()) {
                        item { DelyvreStateCard(Icons.Default.LocationOn, "Nenhum endereço salvo", "Você pode explorar o DELYVRE sem endereço e cadastrar um somente quando precisar.") }
                    } else {
                        items(customer.addresses, key = { it.id }) { address ->
                            DelyvreAddressCard(address, { vm.editAddress(address) }, { vm.deleteAddress(address.id) })
                        }
                    }
                    item {
                        OutlinedButton(vm::logout, Modifier.fillMaxWidth()) {
                            Icon(Icons.Default.Logout, null); Spacer(Modifier.width(8.dp)); Text("Sair da conta")
                        }
                    }
                }
            }
            if (vm.busy) DelyvreBusyOverlay()
        }
    }
}

@Composable
private fun DelyvreHomeHeader(address: Address?, authenticated: Boolean, onAddress: () -> Unit, onProfile: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Surface(
            modifier = Modifier.weight(1f).clickable(onClick = onAddress),
            color = MaterialTheme.colorScheme.surface,
            shape = RoundedCornerShape(18.dp),
            border = CardDefaults.outlinedCardBorder(),
        ) {
            Row(Modifier.padding(horizontal = 14.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Icon(Icons.Default.LocationOn, null, tint = DelyvreBrand)
                Column(Modifier.weight(1f)) {
                    Text(if (address == null) "Entregar em" else address.label.ifBlank { "Entregar em" }, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(delyvreAddressSummary(address), fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Icon(Icons.Default.KeyboardArrowDown, "Trocar endereço")
            }
        }
        if (authenticated) FilledTonalIconButton(onProfile) { Icon(Icons.Default.Person, "Perfil") }
        else TextButton(onProfile) { Text("Entrar") }
    }
}

@Composable
private fun DelyvreSearchLauncher(onClick: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        color = MaterialTheme.colorScheme.surface,
        shape = RoundedCornerShape(18.dp),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 15.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(Icons.Default.Search, null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("Buscar restaurantes ou produtos", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DelyvreStoreCardV3(store: Store, onOpen: () -> Unit, onFavorite: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column {
            DelyvreRemoteImage(store.coverUrl, store.name, Modifier.fillMaxWidth().height(150.dp), Icons.Default.Restaurant)
            Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                DelyvreRemoteImage(store.logoUrl, null, Modifier.size(58.dp).clip(RoundedCornerShape(17.dp)), Icons.Default.Storefront)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(store.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        delyvreStoreStatusLabel(store.acceptingOrders),
                        color = if (store.acceptingOrders) DelyvreSuccess else MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    val eta = if (store.deliveryEtaMinutes > 0) "${store.deliveryEtaMinutes}–${store.deliveryEtaMinutes + 15} min" else null
                    val fee = "Entrega ${delyvreDeliveryFeeLabel(store.deliveryFeeCents)}"
                    Text(listOfNotNull(eta, fee).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                IconButton(onFavorite) {
                    Icon(if (store.favorite) Icons.Default.Favorite else Icons.Default.FavoriteBorder, "Favoritar ${store.name}", tint = if (store.favorite) DelyvreBrand else MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            if (store.description.isNotBlank() || store.minimumOrderCents > 0 || store.pickupEnabled || store.scheduleNote.isNotBlank()) {
                Column(Modifier.padding(start = 14.dp, end = 14.dp, bottom = 14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                    if (store.description.isNotBlank()) Text(store.description, maxLines = 2, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall)
                    val details = buildList {
                        if (store.minimumOrderCents > 0) add("Mínimo ${money(store.minimumOrderCents)}")
                        if (store.pickupEnabled) add("Retirada disponível")
                    }
                    if (details.isNotEmpty()) Text(details.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if (store.scheduleNote.isNotBlank()) Text(store.scheduleNote, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}

@Composable
private fun DelyvreCompactStoreV3(store: Store, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.width(250.dp),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column {
            DelyvreRemoteImage(store.coverUrl, store.name, Modifier.fillMaxWidth().height(105.dp), Icons.Default.Restaurant)
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(store.name, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    "${if (store.deliveryEtaMinutes > 0) "${store.deliveryEtaMinutes} min · " else ""}${delyvreDeliveryFeeLabel(store.deliveryFeeCents)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@Composable
private fun DelyvreRecentOrderCard(order: OrderSummary, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.width(240.dp),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(order.storeName, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("Pedido #${order.orderNumber}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(order.statusLabel, color = DelyvreBrandStrong, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(money(order.totalCents), fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun DelyvreOrderCardV3(order: OrderSummary, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = onOpen,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = CardDefaults.outlinedCardBorder(),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(order.storeName, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.width(8.dp)); Text(money(order.totalCents), fontWeight = FontWeight.Black)
            }
            Text("Pedido #${order.orderNumber}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(order.statusLabel, color = DelyvreBrandStrong, fontWeight = FontWeight.Bold)
            Text(delyvrePaymentLabelV3(order.paymentStatus), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DelyvreAddressCard(address: Address, onEdit: () -> Unit, onDelete: () -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = CardDefaults.outlinedCardBorder()) {
        Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(Icons.Default.LocationOn, null, tint = DelyvreBrand)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(address.label.ifBlank { "Endereço" }, fontWeight = FontWeight.Bold)
                    if (address.isDefault) { Spacer(Modifier.width(6.dp)); Badge { Text("Principal") } }
                }
                Text(delyvreAddressSummary(address), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            IconButton(onEdit) { Icon(Icons.Default.Edit, "Editar endereço") }
            IconButton(onDelete) { Icon(Icons.Default.Delete, "Excluir endereço") }
        }
    }
}

@Composable
private fun DelyvreProfileEditor(
    customerName: String,
    customerPhone: String,
    cpfConfigured: Boolean,
    cpfMasked: String,
    onDismiss: () -> Unit,
    onSave: (String, String, String) -> Unit,
) {
    var name by remember(customerName) { mutableStateOf(customerName) }
    var phone by remember(customerPhone) { mutableStateOf(customerPhone) }
    var cpf by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Editar dados") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(name, { name = it.take(160) }, label = { Text("Nome") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                OutlinedTextField(phone, { phone = it.take(24) }, label = { Text("Celular") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                if (cpfConfigured) {
                    OutlinedTextField(cpfMasked, {}, label = { Text("CPF") }, modifier = Modifier.fillMaxWidth(), readOnly = true, singleLine = true)
                } else {
                    OutlinedTextField(cpf, { cpf = it.filter(Char::isDigit).take(11) }, label = { Text("CPF") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                }
            }
        },
        dismissButton = { TextButton(onDismiss) { Text("Cancelar") } },
        confirmButton = {
            Button(
                onClick = { onSave(name.trim(), phone.trim(), cpf) },
                enabled = name.isNotBlank() && (cpfConfigured || cpf.length == 11),
            ) { Text("Salvar") }
        },
    )
}

@Composable
private fun DelyvreMainBottomBar(vm: DeliveryViewModel, current: MainDestination) {
    NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
        NavigationBarItem(current == MainDestination.Home, { vm.navigateMain(MainDestination.Home) }, { Icon(Icons.Default.Home, null) }, label = { Text("Início") })
        NavigationBarItem(current == MainDestination.Search, { vm.navigateMain(MainDestination.Search) }, { Icon(Icons.Default.Search, null) }, label = { Text("Buscar") })
        NavigationBarItem(current == MainDestination.Orders, { vm.navigateMain(MainDestination.Orders) }, { Icon(Icons.Default.ReceiptLong, null) }, label = { Text("Pedidos") })
        NavigationBarItem(current == MainDestination.Profile, { vm.navigateMain(MainDestination.Profile) }, { Icon(Icons.Default.Person, null) }, label = { Text("Perfil") })
    }
}

@Composable
private fun DelyvreMarketplaceProblem(issue: MarketplaceLoadIssue?, onRetry: () -> Unit) {
    val title: String
    val message: String
    val icon = when (issue) {
        MarketplaceLoadIssue.Offline -> { title = "Você está sem conexão"; message = "Confira sua internet e tente novamente."; Icons.Default.WifiOff }
        MarketplaceLoadIssue.ServerUnavailable -> { title = "Serviço temporariamente indisponível"; message = "Não conseguimos carregar os restaurantes agora. Tente novamente em instantes."; Icons.Default.CloudOff }
        else -> { title = "Não foi possível carregar"; message = "Houve uma falha temporária. Tente novamente."; Icons.Default.Refresh }
    }
    Surface(color = MaterialTheme.colorScheme.surface, shape = RoundedCornerShape(22.dp), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Icon(icon, null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(36.dp))
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            OutlinedButton(onRetry) { Text("Tentar novamente") }
        }
    }
}

@Composable
private fun DelyvreStateCard(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, message: String) {
    Surface(color = MaterialTheme.colorScheme.surface, shape = RoundedCornerShape(22.dp), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.fillMaxWidth().padding(26.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(34.dp))
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DelyvreStoreSkeleton() {
    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = CardDefaults.outlinedCardBorder()) {
        Column {
            Box(Modifier.fillMaxWidth().height(135.dp).background(DelyvreSurfaceMuted))
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.fillMaxWidth(.55f).height(18.dp).clip(RoundedCornerShape(6.dp)).background(DelyvreLine))
                Box(Modifier.fillMaxWidth(.35f).height(13.dp).clip(RoundedCornerShape(6.dp)).background(DelyvreLine))
            }
        }
    }
}

@Composable
private fun DelyvreSectionHeading(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun DelyvreBusyOverlay() {
    Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.scrim.copy(alpha = .12f)), contentAlignment = Alignment.Center) {
        CircularProgressIndicator()
    }
}

@Composable
private fun DelyvreModule3Messages(vm: DeliveryViewModel, host: SnackbarHostState) {
    LaunchedEffect(vm.message) {
        vm.message?.let {
            host.showSnackbar(it.replace("EventMenu Delivery", "DELYVRE").replace("EventMenu", "DELYVRE"))
            vm.clearMessage()
        }
    }
}

private fun delyvrePaymentLabelV3(value: String): String = when (value.lowercase()) {
    "paid" -> "Pagamento confirmado"
    "pending", "created", "processing", "authorized" -> "Pagamento pendente"
    "failed", "rejected" -> "Pagamento recusado"
    "cancelled", "canceled" -> "Pagamento cancelado"
    else -> "Pagamento em atualização"
}
