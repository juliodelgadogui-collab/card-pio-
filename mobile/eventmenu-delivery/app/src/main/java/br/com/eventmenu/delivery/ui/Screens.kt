package br.com.eventmenu.delivery.ui

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color as AndroidColor
import android.location.LocationManager
import android.util.Base64
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.*
import br.com.eventmenu.delivery.money
import coil3.compose.AsyncImage
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter

private val DeliveryPrimary = Color(0xFF6D28D9)
private val DeliveryPrimaryDark = Color(0xFF4C1D95)
private val DeliveryAccent = Color(0xFF8B5CF6)
private val DeliveryBackground = Color(0xFFFAF8FF)
private val DeliveryLavender = Color(0xFFF1ECFF)
private val DeliveryGreen = Color(0xFF137A4B)

@Composable
fun EventMenuDeliveryApp(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) {
        vm.message?.let { snackbar.showSnackbar(it); vm.clearMessage() }
    }
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = DeliveryPrimary,
            onPrimary = Color.White,
            primaryContainer = Color(0xFFE9DDFF),
            onPrimaryContainer = DeliveryPrimaryDark,
            secondary = DeliveryAccent,
            secondaryContainer = DeliveryLavender,
            background = DeliveryBackground,
            surface = Color.White,
            surfaceVariant = Color(0xFFF3F0F9),
            outlineVariant = Color(0xFFE2DDEB),
        ),
        shapes = Shapes(
            extraSmall = RoundedCornerShape(8.dp),
            small = RoundedCornerShape(12.dp),
            medium = RoundedCornerShape(20.dp),
            large = RoundedCornerShape(28.dp),
            extraLarge = RoundedCornerShape(32.dp),
        ),
    ) {
        Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            when (vm.screen) {
                Screen.Login -> LoginScreen(vm)
                Screen.Register -> RegisterScreen(vm)
                Screen.VerifyEmail -> VerifyEmailScreen(vm)
                Screen.Home -> HomeScreen(vm)
                Screen.Catalog -> CatalogScreen(vm)
                Screen.Cart -> CartScreen(vm)
                Screen.Checkout -> CheckoutScreen(vm)
                Screen.Orders -> OrdersScreen(vm)
                Screen.OrderDetail -> OrderDetailScreen(vm)
                Screen.Profile -> ProfileScreen(vm)
                Screen.AddressEditor -> AddressEditorScreen(vm)
            }
            if (vm.busy) {
                Box(
                    Modifier.fillMaxSize().background(DeliveryPrimaryDark.copy(alpha = .16f)),
                    contentAlignment = Alignment.Center,
                ) { CircularProgressIndicator() }
            }
            SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter).padding(16.dp))
        }
    }
}

@Composable
private fun LoginScreen(vm: DeliveryViewModel) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    AuthShell("Peça comida sem complicação", "Entre para acompanhar seus pedidos, pagamentos e entregas.") {
        OutlinedTextField(
            email, { email = it }, label = { Text("E-mail") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            singleLine = true, modifier = Modifier.fillMaxWidth(),
        )
        OutlinedTextField(
            password, { password = it }, label = { Text("Senha") },
            visualTransformation = PasswordVisualTransformation(),
            singleLine = true, modifier = Modifier.fillMaxWidth(),
        )
        Button(
            { vm.login(email, password) },
            modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
            enabled = email.isNotBlank() && password.isNotBlank(),
        ) { Text("Entrar", fontWeight = FontWeight.Bold) }
        TextButton({ vm.forgotPassword(email) }, enabled = email.isNotBlank()) { Text("Esqueci minha senha") }
        HorizontalDivider()
        OutlinedButton({ vm.navigate(Screen.Register) }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text("Criar minha conta") }
    }
}

@Composable
private fun RegisterScreen(vm: DeliveryViewModel) {
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var legalAccepted by remember { mutableStateOf(false) }
    AuthShell("Criar conta", "Você precisará confirmar o e-mail antes de entrar e fazer pedidos.") {
        OutlinedTextField(name, { name = it }, label = { Text("Nome") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(phone, { phone = it }, label = { Text("Celular") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(
            password, { password = it }, label = { Text("Senha · mínimo 8 caracteres") },
            visualTransformation = PasswordVisualTransformation(), singleLine = true, modifier = Modifier.fillMaxWidth(),
        )
        Row(
            Modifier.fillMaxWidth().clickable { legalAccepted = !legalAccepted },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Checkbox(legalAccepted, { legalAccepted = it })
            Text("Li e aceito os Termos de Uso e a Política de Privacidade.", style = MaterialTheme.typography.bodySmall)
        }
        Button(
            { vm.register(name, email, phone, password, legalAccepted) },
            modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
            enabled = name.isNotBlank() && email.isNotBlank() && password.length >= 8 && legalAccepted,
        ) { Text("Cadastrar e enviar confirmação", fontWeight = FontWeight.Bold) }
        TextButton({ vm.navigate(Screen.Login) }) { Text("Já tenho conta") }
    }
}

@Composable
private fun VerifyEmailScreen(vm: DeliveryViewModel) {
    AuthShell("Confirme seu e-mail", "Enviamos um link para ${vm.pendingEmail}. Só depois da confirmação o acesso será liberado.") {
        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(24.dp)) {
            Icon(Icons.Default.MarkEmailRead, null, Modifier.padding(18.dp).size(54.dp), tint = DeliveryPrimary)
        }
        Text("Abra sua caixa de entrada e toque em “Confirmar meu e-mail”. O link abre novamente o EventMenu Delivery.", style = MaterialTheme.typography.bodyLarge)
        Button({ vm.resendVerification() }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text("Reenviar confirmação") }
        OutlinedButton({ vm.navigate(Screen.Login) }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text("Já confirmei · entrar") }
    }
}

@Composable
private fun HomeScreen(vm: DeliveryViewModel) {
    var search by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { vm.loadStores() }
    Scaffold(
        topBar = { DeliveryTopBar("EventMenu Delivery") },
        bottomBar = { BottomNav(vm, Screen.Home) },
    ) { pad ->
        LazyColumn(
            Modifier.fillMaxSize().padding(pad),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item { PremiumHomeHero() }
            item {
                OutlinedTextField(
                    search, { search = it; vm.loadStores(it) },
                    leadingIcon = { Icon(Icons.Default.Search, null) },
                    label = { Text("Buscar restaurante") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(18.dp),
                )
            }
            item { Text("Restaurantes", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold) }
            if (vm.stores.isEmpty()) item { EmptyCard("Nenhum restaurante disponível agora.") }
            items(vm.stores, key = { "${it.tenantId}-${it.unitId}" }) { store ->
                StoreCard(store, { vm.openStore(store) }, { vm.toggleFavorite(store) })
            }
        }
    }
}

@Composable
private fun PremiumHomeHero() {
    Card(
        colors = CardDefaults.cardColors(containerColor = DeliveryPrimaryDark),
        shape = RoundedCornerShape(28.dp),
    ) {
        Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Surface(color = Color.White.copy(alpha = .12f), shape = RoundedCornerShape(999.dp)) {
                Text("EVENTMENU DELIVERY", Modifier.padding(horizontal = 11.dp, vertical = 6.dp), color = Color.White, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
            }
            Text("O que vai pedir hoje?", color = Color.White, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
            Text("Escolha seu restaurante, pague com segurança e acompanhe a entrega em tempo real.", color = Color.White.copy(alpha = .86f), style = MaterialTheme.typography.bodyMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PremiumMiniPill(Icons.Default.Bolt, "Rápido")
                PremiumMiniPill(Icons.Default.Lock, "Seguro")
                PremiumMiniPill(Icons.Default.LocationOn, "Ao vivo")
            }
        }
    }
}

@Composable
private fun PremiumMiniPill(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String) {
    Surface(color = Color.White.copy(alpha = .12f), shape = RoundedCornerShape(999.dp)) {
        Row(Modifier.padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            Icon(icon, null, tint = Color.White, modifier = Modifier.size(15.dp)); Text(label, color = Color.White, style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun StoreCard(store: Store, onOpen: () -> Unit, onFavorite: () -> Unit) {
    Card(
        Modifier.fillMaxWidth().clickable(onClick = onOpen),
        shape = RoundedCornerShape(24.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
    ) {
        Column {
            if (store.coverUrl.isNotBlank()) {
                AsyncImage(
                    store.coverUrl, store.name,
                    Modifier.fillMaxWidth().height(150.dp),
                    contentScale = ContentScale.Crop,
                )
            }
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (store.logoUrl.isNotBlank()) AsyncImage(store.logoUrl, null, Modifier.size(56.dp))
                    Column(Modifier.weight(1f)) {
                        Text(store.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                        if (store.description.isNotBlank()) Text(store.description, maxLines = 2)
                        val place = listOfNotNull(store.city.takeIf { it.isNotBlank() }, store.state.takeIf { it.isNotBlank() }).joinToString(" · ")
                        if (place.isNotBlank()) Text(place, style = MaterialTheme.typography.bodySmall)
                    }
                    IconButton(onFavorite) {
                        Icon(
                            if (store.favorite) Icons.Default.Favorite else Icons.Default.FavoriteBorder,
                            null,
                            tint = if (store.favorite) DeliveryPrimary else LocalContentColor.current,
                        )
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    StatusPill(store.acceptingOrders)
                    Text("~${store.deliveryEtaMinutes} min", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                }
                Text(
                    "Entrega ${money(store.deliveryFeeCents)} · mínimo ${money(store.minimumOrderCents)}",
                    style = MaterialTheme.typography.bodySmall,
                )
                if (store.scheduleNote.isNotBlank()) Text(store.scheduleNote, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun StatusPill(open: Boolean) {
    Surface(
        color = if (open) DeliveryGreen.copy(alpha = .12f) else MaterialTheme.colorScheme.errorContainer,
        contentColor = if (open) DeliveryGreen else MaterialTheme.colorScheme.onErrorContainer,
        shape = RoundedCornerShape(999.dp),
    ) {
        Text(if (open) "Aceitando pedidos" else "Pausado", Modifier.padding(horizontal = 10.dp, vertical = 5.dp), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun CatalogScreen(vm: DeliveryViewModel) {
    val cat = vm.catalog ?: return
    var customizing by remember { mutableStateOf<Product?>(null) }
    customizing?.let { product ->
        ProductCustomizerDialog(product, onDismiss = { customizing = null }) { quantity, options, notes ->
            vm.addToCart(product, quantity, options, notes)
            customizing = null
        }
    }
    Scaffold(
        topBar = {
            DeliveryTopBar(
                cat.store.name,
                onBack = { vm.navigate(Screen.Home) },
                actions = {
                    IconButton({ vm.navigate(Screen.Cart) }) {
                        BadgedBox(badge = { if (vm.cart.isNotEmpty()) Badge { Text(vm.cart.sumOf { it.quantity }.toString()) } }) {
                            Icon(Icons.Default.ShoppingCart, null)
                        }
                    }
                },
            )
        },
    ) { pad ->
        LazyColumn(
            Modifier.fillMaxSize().padding(pad),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Card(shape = RoundedCornerShape(24.dp)) {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (cat.store.coverUrl.isNotBlank()) AsyncImage(cat.store.coverUrl, cat.store.name, Modifier.fillMaxWidth().height(170.dp), contentScale = ContentScale.Crop)
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                StatusPill(cat.store.acceptingOrders)
                                Text("~${cat.store.deliveryEtaMinutes} min", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                            }
                            if (cat.store.description.isNotBlank()) Text(cat.store.description)
                            Text("Entrega ${money(cat.store.deliveryFeeCents)} · pedido mínimo ${money(cat.store.minimumOrderCents)}", style = MaterialTheme.typography.bodySmall)
                            if (cat.store.scheduleNote.isNotBlank()) Text(cat.store.scheduleNote, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            if (!cat.store.acceptingOrders) {
                                Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer)) {
                                    Text("O restaurante está com novos pedidos pausados. Você pode consultar o cardápio enquanto isso.", Modifier.padding(14.dp))
                                }
                            }
                        }
                    }
                }
            }
            cat.categories.forEach { category ->
                item(key = "cat-${category.id}") { Text(category.name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp)) }
                items(cat.products.filter { it.categoryId == category.id }, key = { "p-${it.id}" }) { product ->
                    ProductCard(product) { customizing = product }
                }
            }
            val uncategorized = cat.products.filter { it.categoryId == null || cat.categories.none { c -> c.id == it.categoryId } }
            if (uncategorized.isNotEmpty()) {
                item { Text("Outros", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold) }
                items(uncategorized, key = { "other-${it.id}" }) { product -> ProductCard(product) { customizing = product } }
            }
        }
    }
}

@Composable
private fun ProductCard(product: Product, onAdd: () -> Unit) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)) {
        Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            if (product.imageUrl.isNotBlank()) AsyncImage(product.imageUrl, product.name, Modifier.size(92.dp), contentScale = ContentScale.Crop)
            Column(Modifier.weight(1f)) {
                Text(product.name, fontWeight = FontWeight.Bold)
                if (product.description.isNotBlank()) Text(product.description, maxLines = 3, style = MaterialTheme.typography.bodySmall)
                if (product.modifierGroups.isNotEmpty()) Text("Personalizável", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                Text(money(product.priceCents), fontWeight = FontWeight.Bold, color = DeliveryPrimary)
                if (!product.available) Text("Indisponível", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelMedium)
            }
            FilledTonalIconButton(onAdd, enabled = product.available) { Icon(Icons.Default.Add, null) }
        }
    }
}

@Composable
private fun ProductCustomizerDialog(product: Product, onDismiss: () -> Unit, onAdd: (Int, Set<Int>, String) -> Unit) {
    var quantity by remember { mutableIntStateOf(1) }
    var notes by remember { mutableStateOf("") }
    val selected = remember(product.id) { mutableStateMapOf<Int, Set<Int>>() }
    val valid = product.modifierGroups.all { group ->
        val count = selected[group.id]?.size ?: 0
        count >= group.minSelect && count <= group.maxSelect
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(product.name) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (product.description.isNotBlank()) Text(product.description)
                product.modifierGroups.forEach { group ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(group.name, fontWeight = FontWeight.Bold)
                        Text(
                            if (group.required) "Obrigatório · escolha ${group.minSelect}${if (group.maxSelect > group.minSelect) " a ${group.maxSelect}" else ""}" else "Opcional · até ${group.maxSelect}",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        group.options.forEach { option ->
                            val current = selected[group.id].orEmpty()
                            val checked = option.id in current
                            Row(
                                Modifier.fillMaxWidth().clickable {
                                    val next = if (checked) current - option.id else if (group.maxSelect <= 1) setOf(option.id) else if (current.size < group.maxSelect) current + option.id else current
                                    selected[group.id] = next
                                }.padding(vertical = 3.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Checkbox(checked, onCheckedChange = null)
                                Text(option.name, Modifier.weight(1f))
                                if (option.priceDeltaCents != 0) Text("+ ${money(option.priceDeltaCents)}", style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
                OutlinedTextField(notes, { notes = it.take(300) }, label = { Text("Observação · opcional") }, modifier = Modifier.fillMaxWidth(), maxLines = 3)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    IconButton({ quantity = (quantity - 1).coerceAtLeast(1) }) { Icon(Icons.Default.Remove, null) }
                    Text(quantity.toString(), fontWeight = FontWeight.Bold)
                    IconButton({ quantity = (quantity + 1).coerceAtMost(99) }) { Icon(Icons.Default.Add, null) }
                    Spacer(Modifier.weight(1f))
                    val extras = product.modifierGroups.flatMap { it.options }.filter { it.id in selected.values.flatten() }.sumOf { it.priceDeltaCents }
                    Text(money((product.priceCents + extras) * quantity), fontWeight = FontWeight.Bold, color = DeliveryPrimary)
                }
            }
        },
        confirmButton = {
            Button({ onAdd(quantity, selected.values.flatten().toSet(), notes.trim()) }, enabled = valid) { Text("Adicionar") }
        },
        dismissButton = { TextButton(onDismiss) { Text("Cancelar") } },
    )
}

@Composable
private fun CartScreen(vm: DeliveryViewModel) {
    val customer = vm.customer
    val store = vm.catalog?.store
    val subtotal = vm.cart.sumOf { it.totalCents() }
    val discount = (vm.couponQuote?.discountCents ?: 0).coerceIn(0, subtotal)
    val fee = store?.deliveryFeeCents ?: 0
    val estimate = (subtotal - discount).coerceAtLeast(0) + fee
    val minimum = store?.minimumOrderCents ?: 0
    val storeOpen = store?.acceptingOrders != false
    var couponInput by remember(store?.tenantId) { mutableStateOf(vm.couponCode) }
    var selectedAddress by remember(customer) { mutableIntStateOf(customer?.addresses?.firstOrNull { it.isDefault }?.id ?: customer?.addresses?.firstOrNull()?.id ?: 0) }
    LaunchedEffect(vm.couponCode) { if (vm.couponCode.isNotBlank()) couponInput = vm.couponCode }
    Scaffold(topBar = { DeliveryTopBar("Seu carrinho", onBack = { vm.navigate(Screen.Catalog) }) }) { pad ->
        LazyColumn(
            Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (vm.cart.isEmpty()) item { EmptyCard("Seu carrinho está vazio. Volte ao cardápio para escolher seus itens.") }
            items(vm.cart.size) { i ->
                val item = vm.cart[i]
                Card(shape = RoundedCornerShape(20.dp)) {
                    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(item.product.name, fontWeight = FontWeight.Bold)
                            if (item.optionIds.isNotEmpty()) Text("Com ${item.optionIds.size} adicional(is)", style = MaterialTheme.typography.bodySmall)
                            if (item.notes.isNotBlank()) Text("Obs.: ${item.notes}", style = MaterialTheme.typography.bodySmall)
                            Text(money(item.totalCents()), color = DeliveryPrimary, fontWeight = FontWeight.SemiBold)
                        }
                        FilledTonalIconButton({ vm.updateCart(i, item.quantity - 1) }) { Icon(Icons.Default.Remove, null) }
                        Text(item.quantity.toString(), Modifier.padding(horizontal = 8.dp), fontWeight = FontWeight.Bold)
                        FilledTonalIconButton({ vm.updateCart(i, item.quantity + 1) }) { Icon(Icons.Default.Add, null) }
                    }
                }
            }
            item {
                Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Text("Tem um cupom?", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (vm.couponQuote != null && vm.couponCode.isNotBlank()) {
                            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(16.dp)) {
                                Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    Icon(Icons.Default.CheckCircle, null, tint = DeliveryPrimary)
                                    Column(Modifier.weight(1f)) {
                                        Text("Cupom ${vm.couponCode} aplicado", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer)
                                        Text("Desconto: -${money(discount)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onPrimaryContainer)
                                    }
                                    TextButton({ vm.clearCoupon(); couponInput = "" }) { Text("Remover") }
                                }
                            }
                        } else {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                OutlinedTextField(
                                    couponInput,
                                    { couponInput = it.uppercase().filter { ch -> ch.isLetterOrDigit() || ch == '-' || ch == '_' }.take(80) },
                                    label = { Text("Digite o código") },
                                    singleLine = true,
                                    modifier = Modifier.weight(1f),
                                    shape = RoundedCornerShape(16.dp),
                                )
                                Button({ vm.validateCoupon(couponInput) }, enabled = couponInput.isNotBlank() && subtotal > 0) { Text("Aplicar") }
                            }
                        }
                        Text("O desconto é conferido novamente pelo servidor quando o pedido é criado.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            item {
                Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.secondaryContainer)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("Resumo do pedido", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        SummaryRow("Subtotal", money(subtotal))
                        if (discount > 0) SummaryRow("Desconto do cupom", "-${money(discount)}")
                        SummaryRow("Taxa de entrega", if (fee == 0) "Grátis" else money(fee))
                        HorizontalDivider()
                        SummaryRow("TOTAL", money(estimate), strong = true)
                        Text("O valor final é recalculado e confirmado pelo servidor antes do pagamento.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            if (!storeOpen) item { EmptyCard("Este restaurante pausou novos pedidos. O carrinho ficará salvo enquanto você estiver nesta sessão.") }
            if (subtotal < minimum) item { EmptyCard("Faltam ${money(minimum - subtotal)} para atingir o pedido mínimo de ${money(minimum)}.") }
            item { Text("Endereço de entrega", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            if (customer?.addresses.isNullOrEmpty()) {
                item {
                    EmptyCard("Cadastre um endereço antes de finalizar.")
                    Spacer(Modifier.height(8.dp))
                    Button({ vm.editAddress() }, Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text("Cadastrar endereço") }
                }
            } else {
                items(customer!!.addresses) { a ->
                    Card(
                        Modifier.fillMaxWidth().clickable { selectedAddress = a.id },
                        colors = CardDefaults.cardColors(containerColor = if (selectedAddress == a.id) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface),
                        shape = RoundedCornerShape(18.dp),
                    ) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selectedAddress == a.id, { selectedAddress = a.id })
                            Column { Text(a.label, fontWeight = FontWeight.Bold); Text("${a.street}, ${a.number} · ${a.city}/${a.state}") }
                        }
                    }
                }
            }
            item {
                Button(
                    { vm.checkoutCart(selectedAddress) },
                    enabled = vm.cart.isNotEmpty() && selectedAddress > 0 && subtotal >= minimum && storeOpen,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp),
                ) { Text("Confirmar pedido", fontWeight = FontWeight.Bold) }
            }
        }
    }
}

@Composable
private fun SummaryRow(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal)
        Text(value, fontWeight = if (strong) FontWeight.Black else FontWeight.SemiBold, color = if (strong) DeliveryPrimaryDark else LocalContentColor.current)
    }
}

@Composable
private fun CheckoutScreen(vm: DeliveryViewModel) {
    val order = vm.selectedOrder ?: return
    val methods = vm.paymentMethods
    var taxId by remember { mutableStateOf("") }
    var showCard by remember { mutableStateOf(false) }
    var showCash by remember { mutableStateOf(false) }
    var change by remember { mutableStateOf("") }
    val clipboard = LocalClipboardManager.current
    Scaffold(topBar = { DeliveryTopBar("Pagamento", onBack = { vm.navigate(Screen.Orders) }) }) { pad ->
        LazyColumn(
            Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                Card(colors = CardDefaults.cardColors(containerColor = DeliveryPrimaryDark), shape = RoundedCornerShape(24.dp)) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Pedido #${order.orderNumber}", color = Color.White.copy(alpha = .84f), style = MaterialTheme.typography.labelLarge)
                        Text(money(order.totalCents), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, color = Color.White)
                        Text("Escolha como pagar", color = Color.White.copy(alpha = .82f))
                    }
                }
            }
            if (vm.paymentWaiting) {
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)) {
                        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 3.dp)
                            Column {
                                Text("Aguardando confirmação", fontWeight = FontWeight.Bold)
                                Text("O EventMenu está consultando o servidor e o provedor automaticamente. Não faça um segundo pagamento enquanto este estiver pendente.", style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
            vm.pixPayment?.let { pix ->
                item { PixPayloadCard(pix, onCopy = { clipboard.setText(AnnotatedString(pix.copyPaste)) }) }
            }
            if (!vm.paymentWaiting) {
                if (methods == null) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
                else {
                    if (methods.pixProviders.isNotEmpty()) item {
                        Card {
                            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) { Icon(Icons.Default.QrCode2, null, tint = DeliveryPrimary); Text("PIX", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium) }
                                Text("O QR Code e o Copia e Cola ficam dentro do EventMenu. A confirmação usa webhook e consulta direta ao provedor como redundância.", style = MaterialTheme.typography.bodySmall)
                                OutlinedTextField(
                                    taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF/CNPJ") },
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), singleLine = true, modifier = Modifier.fillMaxWidth(),
                                )
                                methods.pixProviders.forEach { provider ->
                                    Button({ vm.payPix(provider, taxId) }, Modifier.fillMaxWidth().heightIn(min = 50.dp), enabled = taxId.length in listOf(11, 14)) {
                                        Text("Gerar PIX · ${providerLabel(provider)}")
                                    }
                                }
                            }
                        }
                    }
                    if (methods.cards.isNotEmpty()) item {
                        val card = methods.cards.first()
                        Card {
                            Column(Modifier.padding(16.dp)) {
                                Row(Modifier.fillMaxWidth().clickable { showCard = !showCard }, verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Default.CreditCard, null, tint = DeliveryPrimary); Spacer(Modifier.width(10.dp)); Text("Cartão de crédito ou débito", Modifier.weight(1f), fontWeight = FontWeight.Bold); Icon(if (showCard) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null)
                                }
                                if (showCard) {
                                    Spacer(Modifier.height(14.dp))
                                    CardPaymentForm(card.publicKey, card.maxInstallments, vm.customer?.name.orEmpty(), card.paymentTypes) { token, methodId, paymentTypeId, installments, doc ->
                                        vm.payCardToken(token, methodId, paymentTypeId, installments, doc)
                                    }
                                }
                            }
                        }
                    }
                    if (methods.cash) item {
                        Card {
                            Column(Modifier.padding(16.dp)) {
                                Row(Modifier.fillMaxWidth().clickable { showCash = !showCash }, verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Default.Payments, null, tint = DeliveryPrimary); Spacer(Modifier.width(10.dp)); Text("Dinheiro na entrega", Modifier.weight(1f), fontWeight = FontWeight.Bold); Icon(if (showCash) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null)
                                }
                                if (showCash) {
                                    Spacer(Modifier.height(10.dp))
                                    OutlinedTextField(change, { change = it.filter { c -> c.isDigit() || c == ',' || c == '.' } }, label = { Text("Troco para quanto? · opcional") }, modifier = Modifier.fillMaxWidth())
                                    Spacer(Modifier.height(8.dp))
                                    Button({ val cents = change.replace(',', '.').toDoubleOrNull()?.let { (it * 100).toInt() }; vm.payCash(cents) }, Modifier.fillMaxWidth()) { Text("Pagar na entrega") }
                                }
                            }
                        }
                    }
                    if (methods.pixProviders.isEmpty() && methods.cards.isEmpty() && !methods.cash) item { EmptyCard("Este restaurante ainda não configurou uma forma de pagamento para o Delivery.") }
                }
            }
            item { OutlinedButton({ vm.openOrder(order.orderNumber) }, Modifier.fillMaxWidth().heightIn(min = 50.dp)) { Text("Acompanhar pedido") } }
        }
    }
}

@Composable
private fun PixPayloadCard(pix: PixPayment, onCopy: () -> Unit) {
    Card(shape = RoundedCornerShape(22.dp)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) { Text("PIX gerado · ${providerLabel(pix.provider)}", Modifier.padding(horizontal = 12.dp, vertical = 7.dp), fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer) }
            PixQrImage(pix.imageUrl, pix.copyPaste)
            Text("PIX copia e cola", fontWeight = FontWeight.Bold, modifier = Modifier.align(Alignment.Start))
            SelectionContainer { Text(pix.copyPaste, maxLines = 5, style = MaterialTheme.typography.bodySmall) }
            Button(onCopy, Modifier.fillMaxWidth()) { Icon(Icons.Default.ContentCopy, null); Spacer(Modifier.width(8.dp)); Text("Copiar código PIX") }
            if (pix.expiresAt.isNotBlank()) Text("Validade: ${pix.expiresAt}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun PixQrImage(source: String, copyPaste: String) {
    when {
        source.startsWith("data:image", ignoreCase = true) -> {
            val bitmap = remember(source) {
                runCatching {
                    val encoded = source.substringAfter("base64,", "")
                    val bytes = Base64.decode(encoded, Base64.DEFAULT)
                    BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap()
                }.getOrNull()
            }
            bitmap?.let { Image(it, "QR Code PIX", Modifier.size(220.dp)) }
        }
        source.startsWith("https://", ignoreCase = true) -> AsyncImage(source, "QR Code PIX", Modifier.size(220.dp), contentScale = ContentScale.Fit)
        copyPaste.isNotBlank() -> {
            val bitmap = remember(copyPaste) { localPixQr(copyPaste) }
            bitmap?.let { Image(it.asImageBitmap(), "QR Code PIX", Modifier.size(220.dp)) }
        }
    }
}

private fun localPixQr(payload: String): Bitmap? = runCatching {
    val size = 720
    val matrix = QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, size, size)
    val pixels = IntArray(size * size)
    for (y in 0 until size) for (x in 0 until size) pixels[y * size + x] = if (matrix[x, y]) AndroidColor.BLACK else AndroidColor.WHITE
    Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888).apply { setPixels(pixels, 0, size, 0, 0, size, size) }
}.getOrNull()

@Composable
private fun OrdersScreen(vm: DeliveryViewModel) {
    LaunchedEffect(Unit) { if (vm.orders.isEmpty()) vm.loadOrders() }
    Scaffold(topBar = { DeliveryTopBar("Meus pedidos") }, bottomBar = { BottomNav(vm, Screen.Orders) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (vm.orders.isEmpty()) item { EmptyCard("Você ainda não fez pedidos.") }
            items(vm.orders, key = { it.orderNumber }) { o ->
                Card(Modifier.fillMaxWidth().clickable { vm.openOrder(o.orderNumber) }, shape = RoundedCornerShape(20.dp)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Pedido #${o.orderNumber}", fontWeight = FontWeight.Bold); Text(money(o.totalCents), fontWeight = FontWeight.Bold) }
                        Text(o.storeName)
                        Text(o.statusLabel, color = DeliveryPrimary, fontWeight = FontWeight.SemiBold)
                        Text(paymentLabel(o.paymentStatus), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

@Composable
private fun OrderDetailScreen(vm: DeliveryViewModel) {
    val o = vm.selectedOrder ?: return
    var rating by remember { mutableIntStateOf(5) }
    var comment by remember { mutableStateOf("") }
    Scaffold(topBar = { DeliveryTopBar("Pedido #${o.orderNumber}", onBack = vm::loadOrders) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            item {
                Card(shape = RoundedCornerShape(22.dp)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(o.storeName, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text(o.statusLabel, color = DeliveryPrimary, fontWeight = FontWeight.Bold)
                        Text(paymentLabel(o.paymentStatus))
                        Text("Total ${money(o.totalCents)}")
                    }
                }
            }
            vm.tracking?.let { t ->
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer), shape = RoundedCornerShape(22.dp)) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) { Icon(Icons.Default.LocationOn, null, tint = DeliveryPrimary); Text("Entrega em tempo real", fontWeight = FontWeight.Bold) }
                            Text(t.statusLabel)
                            if (t.latitude != null && t.longitude != null) {
                                Text("Entregador: %.5f, %.5f".format(t.latitude, t.longitude))
                                Text("Posição atualizada automaticamente a cada 10 segundos.", style = MaterialTheme.typography.bodySmall)
                            } else Text("A localização aparecerá quando o entregador iniciar a rota.")
                        }
                    }
                }
            }
            if (o.status == "completed") item {
                Card {
                    Column(Modifier.padding(16.dp)) {
                        Text("Como foi seu pedido?", fontWeight = FontWeight.Bold)
                        Row { (1..5).forEach { n -> IconButton({ rating = n }) { Icon(if (n <= rating) Icons.Default.Star else Icons.Default.StarBorder, null, tint = Color(0xFFFFA000)) } } }
                        OutlinedTextField(comment, { comment = it }, label = { Text("Comentário · opcional") }, modifier = Modifier.fillMaxWidth())
                        Button({ vm.submitReview(o.orderNumber, rating, comment) }, Modifier.fillMaxWidth()) { Text("Enviar avaliação") }
                    }
                }
            }
            item { OutlinedButton({ vm.repeatOrder(o.orderNumber) }, Modifier.fillMaxWidth()) { Icon(Icons.Default.Refresh, null); Spacer(Modifier.width(8.dp)); Text("Pedir novamente") } }
            item { OutlinedButton(vm::refreshCurrentOrder, Modifier.fillMaxWidth()) { Text("Atualizar status") } }
        }
    }
}

@Composable
private fun ProfileScreen(vm: DeliveryViewModel) {
    val c = vm.customer
    var name by remember(c) { mutableStateOf(c?.name.orEmpty()) }
    var phone by remember(c) { mutableStateOf(c?.phone.orEmpty()) }
    Scaffold(topBar = { DeliveryTopBar("Minha conta") }, bottomBar = { BottomNav(vm, Screen.Profile) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Card(shape = RoundedCornerShape(22.dp)) {
                    Column(Modifier.padding(16.dp)) {
                        OutlinedTextField(name, { name = it }, label = { Text("Nome") }, modifier = Modifier.fillMaxWidth())
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(phone, { phone = it }, label = { Text("Celular") }, modifier = Modifier.fillMaxWidth())
                        Spacer(Modifier.height(8.dp))
                        Text(c?.email.orEmpty(), style = MaterialTheme.typography.bodySmall)
                        Spacer(Modifier.height(8.dp))
                        Button({ vm.saveProfile(name, phone) }, Modifier.fillMaxWidth()) { Text("Salvar perfil") }
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("Meus endereços", Modifier.weight(1f), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    FilledTonalIconButton({ vm.editAddress() }) { Icon(Icons.Default.Add, null) }
                }
            }
            if (c?.addresses.isNullOrEmpty()) item { EmptyCard("Cadastre seu endereço de entrega.") }
            else items(c!!.addresses, key = { it.id }) { a ->
                Card(shape = RoundedCornerShape(18.dp)) {
                    Column(Modifier.padding(16.dp)) {
                        Row(Modifier.fillMaxWidth()) {
                            Column(Modifier.weight(1f)) { Text(a.label, fontWeight = FontWeight.Bold); Text("${a.street}, ${a.number}"); Text("${a.city}/${a.state}") }
                            IconButton({ vm.editAddress(a) }) { Icon(Icons.Default.Edit, null) }
                            IconButton({ vm.deleteAddress(a.id) }) { Icon(Icons.Default.Delete, null) }
                        }
                    }
                }
            }
            item { OutlinedButton(vm::logout, Modifier.fillMaxWidth()) { Text("Sair da conta") } }
        }
    }
}

@Composable
private fun AddressEditorScreen(vm: DeliveryViewModel) {
    val original = vm.editingAddress
    var label by remember { mutableStateOf(original?.label ?: "Casa") }
    var street by remember { mutableStateOf(original?.street.orEmpty()) }
    var number by remember { mutableStateOf(original?.number.orEmpty()) }
    var complement by remember { mutableStateOf(original?.complement.orEmpty()) }
    var neighborhood by remember { mutableStateOf(original?.neighborhood.orEmpty()) }
    var city by remember { mutableStateOf(original?.city.orEmpty()) }
    var state by remember { mutableStateOf(original?.state.orEmpty()) }
    var cep by remember { mutableStateOf(original?.postalCode.orEmpty()) }
    var reference by remember { mutableStateOf(original?.reference.orEmpty()) }
    var lat by remember { mutableStateOf(original?.latitude) }
    var lng by remember { mutableStateOf(original?.longitude) }
    var isDefault by remember { mutableStateOf(original?.isDefault ?: vm.customer?.addresses.isNullOrEmpty()) }
    Scaffold(topBar = { DeliveryTopBar(if (original == null) "Novo endereço" else "Editar endereço", onBack = { vm.navigate(Screen.Profile) }) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { LocationCaptureButton { a, b -> lat = a; lng = b } }
            item {
                Card(shape = RoundedCornerShape(22.dp)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedTextField(label, { label = it }, label = { Text("Nome do endereço") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(cep, { cep = it }, label = { Text("CEP") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(street, { street = it }, label = { Text("Rua") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(number, { number = it }, label = { Text("Número") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(complement, { complement = it }, label = { Text("Complemento") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(neighborhood, { neighborhood = it }, label = { Text("Bairro") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(city, { city = it }, label = { Text("Cidade") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(state, { state = it.uppercase().take(2) }, label = { Text("UF") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(reference, { reference = it }, label = { Text("Referência") }, modifier = Modifier.fillMaxWidth())
                        Row(verticalAlignment = Alignment.CenterVertically) { Checkbox(isDefault, { isDefault = it }); Text("Endereço principal") }
                        if (lat != null && lng != null) Text("GPS salvo: %.5f, %.5f".format(lat, lng), style = MaterialTheme.typography.bodySmall)
                        Button(
                            { vm.saveAddress(Address(original?.id ?: 0, label, street, number, complement, neighborhood, city, state, cep, reference, vm.customer?.phone.orEmpty(), lat, lng, isDefault)) },
                            Modifier.fillMaxWidth().heightIn(min = 52.dp), enabled = street.isNotBlank() && number.isNotBlank() && city.isNotBlank() && state.length == 2,
                        ) { Text("Salvar endereço") }
                    }
                }
            }
        }
    }
}

@Composable
private fun LocationCaptureButton(onLocation: (Double, Double) -> Unit) {
    val context = LocalContext.current
    var status by remember { mutableStateOf("Usar minha localização") }
    fun capture() {
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        val providers = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
        val loc = providers.mapNotNull { p -> runCatching { lm.getLastKnownLocation(p) }.getOrNull() }.maxByOrNull { it.time }
        if (loc != null) { onLocation(loc.latitude, loc.longitude); status = "Localização adicionada ✓" } else status = "Ative o GPS e tente novamente"
    }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants -> if (grants.values.any { it }) capture() else status = "Permissão de localização necessária" }
    OutlinedButton(
        {
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED || ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) capture()
            else launcher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }, Modifier.fillMaxWidth().heightIn(min = 50.dp),
    ) { Icon(Icons.Default.MyLocation, null); Spacer(Modifier.width(8.dp)); Text(status) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DeliveryTopBar(title: String, onBack: (() -> Unit)? = null, actions: @Composable RowScope.() -> Unit = {}) {
    TopAppBar(
        title = { Text(title, fontWeight = FontWeight.Bold) },
        navigationIcon = { if (onBack != null) IconButton(onBack) { Icon(Icons.Default.ArrowBack, null) } },
        actions = actions,
        colors = TopAppBarDefaults.topAppBarColors(containerColor = DeliveryBackground, titleContentColor = DeliveryPrimaryDark),
    )
}

@Composable
private fun BottomNav(vm: DeliveryViewModel, current: Screen) {
    NavigationBar(containerColor = Color.White) {
        NavigationBarItem(current == Screen.Home, { vm.navigate(Screen.Home); vm.loadStores() }, icon = { Icon(Icons.Default.Home, null) }, label = { Text("Início") })
        NavigationBarItem(current == Screen.Orders, { vm.loadOrders() }, icon = { Icon(Icons.Default.ReceiptLong, null) }, label = { Text("Pedidos") })
        NavigationBarItem(current == Screen.Profile, { vm.openProfile() }, icon = { Icon(Icons.Default.Person, null) }, label = { Text("Conta") })
    }
}

@Composable
private fun AuthShell(title: String, subtitle: String, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Spacer(Modifier.height(30.dp))
        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) {
            Text("EventMenu Delivery", Modifier.padding(horizontal = 14.dp, vertical = 8.dp), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
        Spacer(Modifier.height(16.dp))
        Text(title, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, color = DeliveryPrimaryDark)
        Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant)
        content()
    }
}

@Composable
private fun EmptyCard(text: String) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant), shape = RoundedCornerShape(20.dp)) {
        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.Info, null, tint = DeliveryPrimary); Text(text, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
        }
    }
}

private fun providerLabel(p: String) = when (p) {
    "mercadopago" -> "Mercado Pago"
    "pagbank" -> "PagBank"
    "efi" -> "Efí"
    "inter" -> "Banco Inter"
    else -> p
}
private fun paymentLabel(s: String) = when (s) { "paid" -> "Pagamento confirmado"; "pending", "created", "processing", "authorized" -> "Aguardando pagamento"; "failed", "rejected" -> "Pagamento não concluído"; "cancelled", "canceled" -> "Pagamento cancelado"; else -> s }
