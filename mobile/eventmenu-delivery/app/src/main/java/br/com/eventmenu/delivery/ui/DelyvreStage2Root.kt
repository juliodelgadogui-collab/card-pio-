package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.CartItem
import br.com.eventmenu.delivery.money

@Composable
fun DelyvreStage2Root(vm: DeliveryViewModel) {
    if (vm.screen != Screen.Cart) {
        DelyvreModule4Root(vm)
        return
    }
    DelyvreTheme {
        Box(Modifier.fillMaxSize()) {
            DelyvreStage2Cart(vm)
            if (vm.busy) {
                Surface(Modifier.fillMaxSize(), color = DelyvreInk.copy(alpha = .16f)) {
                    Box(contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreStage2Cart(vm: DeliveryViewModel) {
    val store = vm.catalog?.store
    val customer = vm.customer
    val subtotal = vm.cart.sumOf { it.totalCents() }
    val discount = (vm.couponQuote?.discountCents ?: 0).coerceIn(0, subtotal)
    val fee = store?.deliveryFeeCents ?: 0
    val total = (subtotal - discount).coerceAtLeast(0) + fee
    val minimum = store?.minimumOrderCents ?: 0
    val storeOpen = store?.acceptingOrders != false
    var selectedAddress by remember(customer) { mutableIntStateOf(customer?.addresses?.firstOrNull { it.isDefault }?.id ?: customer?.addresses?.firstOrNull()?.id ?: 0) }
    var couponInput by remember(store?.tenantId, vm.couponCode) { mutableStateOf(vm.couponCode) }
    var reviewing by rememberSaveable(store?.tenantId) { mutableStateOf(false) }
    var confirming by rememberSaveable(store?.tenantId) { mutableStateOf(false) }
    var editingIndex by remember { mutableStateOf<Int?>(null) }
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            if (confirming) confirming = false
            snackbar.showSnackbar(it.replace("EventMenu Delivery", "DELYVRE").replace("EventMenu", "DELYVRE"))
            vm.clearMessage()
        }
    }

    if (reviewing) {
        DelyvreStage2Review(
            vm = vm,
            selectedAddress = selectedAddress,
            subtotal = subtotal,
            discount = discount,
            fee = fee,
            total = total,
            confirming = confirming,
            snackbar = snackbar,
            onBack = { reviewing = false },
            onConfirm = {
                if (!confirming && !vm.busy) {
                    confirming = true
                    vm.checkoutCart(selectedAddress)
                }
            },
        )
        return
    }

    editingIndex?.let { index ->
        vm.cart.getOrNull(index)?.let { item ->
            DelyvreProductSheet(
                product = item.product,
                storeOpen = storeOpen,
                onDismiss = { editingIndex = null },
                onAdd = { quantity, options, notes ->
                    vm.replaceCartItem(index, quantity, options, notes)
                    editingIndex = null
                },
                initialQuantity = item.quantity,
                initialOptionIds = item.optionIds,
                initialNotes = item.notes,
            )
        } ?: run { editingIndex = null }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Seu pedido", fontWeight = FontWeight.Bold) },
                navigationIcon = { IconButton({ vm.navigate(Screen.Catalog) }) { Icon(Icons.Default.ArrowBack, "Voltar") } },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas),
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { pad ->
        LazyColumn(
            Modifier.fillMaxSize().padding(pad),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (vm.cart.isEmpty()) item {
                Stage2Notice(Icons.Default.ShoppingBag, "Seu carrinho está vazio", "Volte ao cardápio e escolha o que deseja pedir.")
            }
            items(vm.cart.size) { index ->
                val item = vm.cart[index]
                Stage2CartItem(item, onMinus = { vm.updateCart(index, item.quantity - 1) }, onPlus = { vm.updateCart(index, item.quantity + 1) }, onEdit = { editingIndex = index }, onRemove = { vm.removeCartItem(index) })
            }
            if (vm.cart.isNotEmpty()) item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    TextButton(vm::clearCart) { Icon(Icons.Default.DeleteSweep, null); Spacer(Modifier.width(6.dp)); Text("Limpar carrinho") }
                }
            }
            item {
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Icon(Icons.Default.LocalOffer, null, tint = DelyvreBrand)
                            Text("Cupom e benefícios", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        }
                        if (!vm.isAuthenticated) {
                            Text("Entre para aplicar cupons e finalizar o pedido.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else if (vm.couponQuote != null && vm.couponCode.isNotBlank()) {
                            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(16.dp)) {
                                Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Default.CheckCircle, null, tint = DelyvreSuccess); Spacer(Modifier.width(8.dp))
                                    Column(Modifier.weight(1f)) { Text("${vm.couponCode} aplicado", fontWeight = FontWeight.Bold); Text("Desconto ${money(discount)}", style = MaterialTheme.typography.bodySmall) }
                                    TextButton({ vm.clearCoupon(); couponInput = "" }) { Text("Remover") }
                                }
                            }
                        } else {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                OutlinedTextField(couponInput, { couponInput = it.uppercase().filter { c -> c.isLetterOrDigit() || c == '-' || c == '_' }.take(80) }, Modifier.weight(1f), label = { Text("Código") }, singleLine = true)
                                Button({ vm.validateCoupon(couponInput) }, enabled = couponInput.isNotBlank() && subtotal > 0) { Text("Aplicar") }
                            }
                        }
                        Text("O desconto é validado novamente pelo servidor antes do pedido ser criado.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            item { Stage2Totals(subtotal, discount, fee, total) }
            if (!storeOpen) item { Stage2Notice(Icons.Default.Storefront, "Restaurante fechado", "Novos pedidos estão pausados agora. Seu carrinho continua salvo.") }
            if (subtotal < minimum) item { Stage2Notice(Icons.Default.Info, "Pedido mínimo", "Adicione ${money(minimum - subtotal)} para atingir ${money(minimum)}.") }
            item {
                Text("Como você quer receber?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = true, onClick = {}, label = { Text("Entrega") }, leadingIcon = { Icon(Icons.Default.DeliveryDining, null) })
                    if (store?.pickupEnabled == true) FilterChip(selected = false, enabled = false, onClick = {}, label = { Text("Retirada") }, leadingIcon = { Icon(Icons.Default.Storefront, null) })
                }
                if (store?.pickupEnabled == true) Text("Retirada ainda depende do contrato de pedido do servidor; não enviaremos uma retirada como se fosse entrega.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (vm.isAuthenticated) {
                item { Text("Endereço de entrega", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold) }
                if (customer?.addresses.isNullOrEmpty()) item {
                    Stage2Notice(Icons.Default.LocationOn, "Cadastre um endereço", "Precisamos saber onde entregar antes de revisar o pedido.")
                    Button({ vm.editAddress() }, Modifier.fillMaxWidth()) { Text("Adicionar endereço") }
                } else items(customer?.addresses.orEmpty(), key = { it.id }) { address ->
                    Card(
                        Modifier.fillMaxWidth().clickable { selectedAddress = address.id },
                        colors = CardDefaults.cardColors(containerColor = if (selectedAddress == address.id) MaterialTheme.colorScheme.primaryContainer else Color.White),
                        border = CardDefaults.outlinedCardBorder(),
                    ) {
                        Row(Modifier.padding(13.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selectedAddress == address.id, { selectedAddress = address.id })
                            Column { Text(address.label, fontWeight = FontWeight.Bold); Text("${address.street}, ${address.number} · ${address.city}/${address.state}", style = MaterialTheme.typography.bodySmall) }
                        }
                    }
                }
            }
            item {
                val canReview = vm.cart.isNotEmpty() && storeOpen && subtotal >= minimum && vm.isAuthenticated && selectedAddress > 0
                Button(
                    onClick = { if (!vm.isAuthenticated) vm.navigate(Screen.Login) else reviewing = true },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp),
                    enabled = if (vm.isAuthenticated) canReview else vm.cart.isNotEmpty() && storeOpen && subtotal >= minimum,
                ) { Text(if (vm.isAuthenticated) "Revisar pedido · ${money(total)}" else "Entrar para finalizar") }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreStage2Review(
    vm: DeliveryViewModel,
    selectedAddress: Int,
    subtotal: Int,
    discount: Int,
    fee: Int,
    total: Int,
    confirming: Boolean,
    snackbar: SnackbarHostState,
    onBack: () -> Unit,
    onConfirm: () -> Unit,
) {
    val address = vm.customer?.addresses?.firstOrNull { it.id == selectedAddress }
    Scaffold(
        topBar = { TopAppBar(title = { Text("Revise seu pedido", fontWeight = FontWeight.Bold) }, navigationIcon = { IconButton(onBack) { Icon(Icons.Default.ArrowBack, "Voltar") } }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Surface(color = DelyvreInk, contentColor = Color.White, shape = RoundedCornerShape(24.dp)) {
                    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text(vm.catalog?.store?.name.orEmpty(), color = Color.White.copy(alpha = .8f))
                        Text("Tudo certo antes de confirmar?", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                        Text("O pedido só será criado quando você tocar em Confirmar pedido.", color = Color.White.copy(alpha = .82f), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            items(vm.cart.size) { index ->
                val item = vm.cart[index]
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("${item.quantity}× ${item.product.name}", fontWeight = FontWeight.Bold); Text(money(item.totalCents()), fontWeight = FontWeight.Black) }
                        stage2OptionNames(item).forEach { Text("+ $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        item.notes.takeIf { it.isNotBlank() }?.let { Text("Obs.: $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    }
                }
            }
            item {
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Entrega", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (address != null) {
                            Text(address.label, fontWeight = FontWeight.SemiBold)
                            Text("${address.street}, ${address.number}${address.complement.takeIf { it.isNotBlank() }?.let { " · $it" }.orEmpty()}")
                            Text("${address.neighborhood} · ${address.city}/${address.state}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else Text("Endereço não encontrado. Volte e selecione novamente.", color = MaterialTheme.colorScheme.error)
                    }
                }
            }
            if (vm.couponQuote != null && vm.couponCode.isNotBlank()) item {
                Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(18.dp)) {
                    Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.LocalOffer, null, tint = DelyvreBrand); Spacer(Modifier.width(8.dp)); Column { Text("Cupom ${vm.couponCode}", fontWeight = FontWeight.Bold); Text("Será revalidado no servidor ao confirmar.", style = MaterialTheme.typography.bodySmall) } }
                }
            }
            item { Stage2Totals(subtotal, discount, fee, total) }
            item {
                Text("Ao confirmar, preços, disponibilidade, adicionais, pedido mínimo e cupom serão verificados novamente. Se a conexão cair durante a criação, confira Meus pedidos antes de tentar de novo.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            item {
                Button(onConfirm, Modifier.fillMaxWidth().heightIn(min = 56.dp), enabled = !confirming && !vm.busy && address != null && vm.cart.isNotEmpty()) {
                    if (confirming || vm.busy) { CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)) }
                    Text(if (confirming || vm.busy) "Confirmando…" else "Confirmar pedido · ${money(total)}")
                }
                TextButton(onBack, Modifier.fillMaxWidth(), enabled = !confirming && !vm.busy) { Text("Voltar e editar") }
            }
        }
    }
}

@Composable
private fun Stage2CartItem(item: CartItem, onMinus: () -> Unit, onPlus: () -> Unit, onEdit: () -> Unit, onRemove: () -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text(item.product.name, fontWeight = FontWeight.Bold)
                    stage2OptionNames(item).forEach { Text("+ $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    if (item.notes.isNotBlank()) Text("Obs.: ${item.notes}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(money(item.totalCents()), fontWeight = FontWeight.Black)
                }
                IconButton(onRemove) { Icon(Icons.Default.DeleteOutline, "Remover") }
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                OutlinedButton(onEdit) { Icon(Icons.Default.Edit, null); Spacer(Modifier.width(5.dp)); Text("Editar") }
                Spacer(Modifier.weight(1f))
                IconButton(onMinus) { Icon(Icons.Default.Remove, "Diminuir") }
                Text(item.quantity.toString(), fontWeight = FontWeight.Bold)
                IconButton(onPlus) { Icon(Icons.Default.Add, "Aumentar") }
            }
        }
    }
}

@Composable
private fun Stage2Totals(subtotal: Int, discount: Int, fee: Int, total: Int) {
    Card(colors = CardDefaults.cardColors(containerColor = DelyvreSurfaceMuted)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Resumo", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Stage2Summary("Subtotal", money(subtotal))
            if (discount > 0) Stage2Summary("Desconto", "- ${money(discount)}")
            Stage2Summary("Entrega", if (fee <= 0) "Grátis" else money(fee))
            HorizontalDivider()
            Stage2Summary("Total estimado", money(total), true)
            Text("O total definitivo é calculado pelo servidor ao criar o pedido.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun Stage2Summary(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal)
        Text(value, fontWeight = if (strong) FontWeight.Black else FontWeight.SemiBold, color = if (strong) DelyvreBrandStrong else MaterialTheme.colorScheme.onSurface)
    }
}

@Composable
private fun Stage2Notice(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, text: String) {
    Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(18.dp)) {
        Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
            Icon(icon, null, tint = DelyvreBrand)
            Column(Modifier.weight(1f)) { Text(title, fontWeight = FontWeight.Bold); Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
    }
}

internal fun stage2OptionNames(item: CartItem): List<String> {
    if (item.optionIds.isEmpty()) return emptyList()
    val byId = item.product.modifierGroups.flatMap { it.options }.associateBy { it.id }
    return item.optionIds.mapNotNull { byId[it]?.let { option -> if (option.priceDeltaCents > 0) "${option.name} (${money(option.priceDeltaCents)})" else option.name } }
}
