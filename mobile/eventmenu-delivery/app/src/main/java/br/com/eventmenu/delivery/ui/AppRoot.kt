package br.com.eventmenu.delivery.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.BuildConfig
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.money

@Composable
fun DeliveryAppRoot(vm: DeliveryViewModel) {
    when (vm.screen) {
        Screen.Login -> SecureLoginScreen(vm)
        Screen.Register -> SecureRegisterScreen(vm)
        Screen.Cart -> EnhancedCartScreen(vm)
        Screen.OrderDetail -> EnhancedOrderDetailScreen(vm)
        else -> EventMenuDeliveryApp(vm)
    }
}

@Composable
private fun SecureLoginScreen(vm: DeliveryViewModel) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val snack = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.clearMessage() } }
    MaterialTheme {
        Box(Modifier.fillMaxSize()) {
            Column(
                Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                Spacer(Modifier.height(42.dp))
                Text("EventMenu Delivery", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black, color = MaterialTheme.colorScheme.primary)
                Text("Entre para pedir, pagar e acompanhar sua entrega.")
                Spacer(Modifier.height(18.dp))
                OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    password,
                    { password = it },
                    label = { Text("Senha") },
                    visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                    trailingIcon = { IconButton({ visible = !visible }) { Icon(if (visible) Icons.Default.VisibilityOff else Icons.Default.Visibility, null) } },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Button({ vm.login(email, password) }, modifier = Modifier.fillMaxWidth(), enabled = email.isNotBlank() && password.isNotBlank()) { Text("Entrar") }
                TextButton({ vm.forgotPassword(email) }, enabled = email.isNotBlank()) { Text("Esqueci minha senha") }
                HorizontalDivider()
                OutlinedButton({ vm.navigate(Screen.Register) }, modifier = Modifier.fillMaxWidth()) { Text("Criar minha conta") }
                Text("O acesso só é liberado depois da confirmação do e-mail.", style = MaterialTheme.typography.bodySmall)
            }
            if (vm.busy) Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            SnackbarHost(snack, Modifier.align(Alignment.BottomCenter).padding(16.dp))
        }
    }
}

@Composable
private fun SecureRegisterScreen(vm: DeliveryViewModel) {
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var legalAccepted by remember { mutableStateOf(false) }
    val snack = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.clearMessage() } }

    fun openLegal(path: String) {
        val uri = Uri.parse(BuildConfig.API_BASE_URL + path)
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, uri)) }
    }

    MaterialTheme {
        Box(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Spacer(Modifier.height(32.dp))
                Text("Criar conta", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text("Cadastre-se e confirme o link que enviaremos ao seu e-mail antes do primeiro acesso.")
                OutlinedTextField(name, { name = it }, label = { Text("Nome") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(phone, { phone = it.filter { c -> c.isDigit() || c in "+()- " } }, label = { Text("Celular") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    password,
                    { password = it },
                    label = { Text("Senha · mínimo 8 caracteres") },
                    visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                    trailingIcon = { IconButton({ visible = !visible }) { Icon(if (visible) Icons.Default.VisibilityOff else Icons.Default.Visibility, null) } },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = legalAccepted, onCheckedChange = { legalAccepted = it })
                            Text("Li e aceito os Termos de Uso e a Política de Privacidade.", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            TextButton({ openLegal("delivery-terms.php") }) { Text("Ler Termos") }
                            TextButton({ openLegal("delivery-privacy.php") }) { Text("Privacidade") }
                        }
                    }
                }
                Button(
                    { vm.register(name, email, phone, password, legalAccepted) },
                    modifier = Modifier.fillMaxWidth(),
                    enabled = name.isNotBlank() && email.isNotBlank() && password.length >= 8 && legalAccepted,
                ) { Text("Cadastrar e confirmar e-mail") }
                TextButton({ vm.navigate(Screen.Login) }) { Text("Já tenho conta") }
            }
            if (vm.busy) Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            SnackbarHost(snack, Modifier.align(Alignment.BottomCenter).padding(16.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EnhancedCartScreen(vm: DeliveryViewModel) {
    val customer = vm.customer
    val store = vm.catalog?.store
    val subtotal = vm.cart.sumOf { it.totalCents() }
    val fee = store?.deliveryFeeCents ?: 0
    val discount = vm.couponQuote?.discountCents ?: 0
    val estimate = (subtotal + fee - discount).coerceAtLeast(0)
    val minimum = store?.minimumOrderCents ?: 0
    val storeOpen = store?.acceptingOrders != false
    var couponInput by remember(vm.couponCode) { mutableStateOf(vm.couponCode) }
    var selectedAddress by remember(customer) { mutableIntStateOf(customer?.addresses?.firstOrNull { it.isDefault }?.id ?: customer?.addresses?.firstOrNull()?.id ?: 0) }
    val snack = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.clearMessage() } }

    MaterialTheme {
        Box(Modifier.fillMaxSize()) {
            Scaffold(
                topBar = {
                    TopAppBar(
                        title = { Text("Seu carrinho", fontWeight = FontWeight.Bold) },
                        navigationIcon = { IconButton({ vm.navigate(Screen.Catalog) }) { Icon(Icons.Default.ArrowBack, null) } },
                    )
                },
                snackbarHost = { SnackbarHost(snack) },
            ) { pad ->
                LazyColumn(
                    Modifier.fillMaxSize().padding(pad),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    items(vm.cart.size) { i ->
                        val item = vm.cart[i]
                        Card(Modifier.fillMaxWidth()) {
                            Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(item.product.name, fontWeight = FontWeight.Bold)
                                    if (item.optionIds.isNotEmpty()) Text("Com ${item.optionIds.size} adicional(is)", style = MaterialTheme.typography.bodySmall)
                                    if (item.notes.isNotBlank()) Text("Obs.: ${item.notes}", style = MaterialTheme.typography.bodySmall)
                                    Text(money(item.totalCents()), fontWeight = FontWeight.SemiBold)
                                }
                                IconButton({ vm.updateCart(i, item.quantity - 1) }) { Icon(Icons.Default.Remove, null) }
                                Text(item.quantity.toString(), fontWeight = FontWeight.Bold)
                                IconButton({ vm.updateCart(i, item.quantity + 1) }) { Icon(Icons.Default.Add, null) }
                            }
                        }
                    }

                    item {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                Text("Cupom", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                Text("Digite um cupom do EventMenu Delivery ou do próprio restaurante.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                    OutlinedTextField(
                                        value = couponInput,
                                        onValueChange = { couponInput = it.uppercase().filter { c -> c.isLetterOrDigit() || c == '-' || c == '_' }.take(80) },
                                        label = { Text("Código do cupom") },
                                        singleLine = true,
                                        enabled = vm.couponQuote == null,
                                        modifier = Modifier.weight(1f),
                                    )
                                    if (vm.couponQuote == null) {
                                        Button({ vm.validateCoupon(couponInput) }, enabled = couponInput.isNotBlank() && vm.cart.isNotEmpty()) { Text("Aplicar") }
                                    } else {
                                        OutlinedButton({ vm.clearCoupon(); couponInput = "" }) { Text("Remover") }
                                    }
                                }
                                vm.couponQuote?.let { quote ->
                                    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium) {
                                        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                            Column {
                                                Text("Cupom ${quote.code}", fontWeight = FontWeight.Bold)
                                                Text("Desconto aplicado", style = MaterialTheme.typography.bodySmall)
                                            }
                                            Text("- ${money(quote.discountCents)}", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                                        }
                                    }
                                }
                            }
                        }
                    }

                    item {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                CartSummaryRow("Subtotal", money(subtotal))
                                CartSummaryRow("Taxa de entrega", if (fee == 0) "Grátis" else money(fee))
                                if (discount > 0) CartSummaryRow("Cupom", "- ${money(discount)}")
                                HorizontalDivider()
                                CartSummaryRow("Total estimado", money(estimate), strong = true)
                                Text("O servidor valida novamente o cupom e confirma o total antes do pagamento.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }

                    if (!storeOpen) item { CartInfoCard("Este restaurante pausou novos pedidos. O carrinho ficará salvo enquanto você estiver nesta sessão.") }
                    if (subtotal < minimum) item { CartInfoCard("Faltam ${money(minimum - subtotal)} para atingir o pedido mínimo de ${money(minimum)}.") }

                    item { Text("Endereço de entrega", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
                    if (customer?.addresses.isNullOrEmpty()) {
                        item {
                            CartInfoCard("Cadastre um endereço antes de finalizar.")
                            Spacer(Modifier.height(8.dp))
                            Button({ vm.editAddress() }, Modifier.fillMaxWidth()) { Text("Cadastrar endereço") }
                        }
                    } else {
                        items(customer!!.addresses, key = { it.id }) { a ->
                            Card(
                                Modifier.fillMaxWidth().clickable { selectedAddress = a.id },
                                colors = CardDefaults.cardColors(containerColor = if (selectedAddress == a.id) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface),
                            ) {
                                Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                                    RadioButton(selectedAddress == a.id, { selectedAddress = a.id })
                                    Column {
                                        Text(a.label, fontWeight = FontWeight.Bold)
                                        Text("${a.street}, ${a.number} · ${a.city}/${a.state}")
                                    }
                                }
                            }
                        }
                    }

                    item {
                        Button(
                            { vm.checkoutCart(selectedAddress) },
                            enabled = vm.cart.isNotEmpty() && selectedAddress > 0 && subtotal >= minimum && storeOpen,
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text("Confirmar pedido · ${money(estimate)}") }
                    }
                }
            }
            if (vm.busy) Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        }
    }
}

@Composable
private fun CartSummaryRow(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal)
        Text(value, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal)
    }
}

@Composable
private fun CartInfoCard(text: String) {
    Card(Modifier.fillMaxWidth()) { Text(text, Modifier.padding(16.dp), color = MaterialTheme.colorScheme.onSurfaceVariant) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EnhancedOrderDetailScreen(vm: DeliveryViewModel) {
    val order = vm.selectedOrder ?: return
    val context = LocalContext.current
    val snack = remember { SnackbarHostState() }
    var rating by remember { mutableIntStateOf(5) }
    var comment by remember { mutableStateOf("") }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.clearMessage() } }
    MaterialTheme {
        Scaffold(
            topBar = { TopAppBar(title = { Text("Pedido #${order.orderNumber}", fontWeight = FontWeight.Bold) }, navigationIcon = { IconButton({ vm.loadOrders() }) { Icon(Icons.Default.ArrowBack, null) } }) },
            snackbarHost = { SnackbarHost(snack) },
        ) { pad ->
            Column(Modifier.fillMaxSize().padding(pad).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text(order.storeName, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text(order.statusLabel, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                        Text("Total ${money(order.totalCents)}")
                        Text("Pagamento: ${order.paymentStatus}", style = MaterialTheme.typography.bodySmall)
                    }
                }
                vm.tracking?.let { tracking ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.DeliveryDining, null); Spacer(Modifier.width(8.dp)); Text("Acompanhar entregador", fontWeight = FontWeight.Bold) }
                            Text(tracking.statusLabel)
                            if (tracking.latitude != null && tracking.longitude != null) {
                                Text("Localização atualizada em tempo real.")
                                Button(
                                    onClick = {
                                        val lat = tracking.latitude; val lng = tracking.longitude
                                        val geo = Uri.parse("geo:$lat,$lng?q=$lat,$lng(Entregador%20EventMenu)")
                                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, geo)) }
                                    },
                                    modifier = Modifier.fillMaxWidth(),
                                ) { Icon(Icons.Default.Map, null); Spacer(Modifier.width(8.dp)); Text("Ver entregador no mapa") }
                                tracking.recordedAt?.takeIf { it.isNotBlank() }?.let { Text("Última posição: $it", style = MaterialTheme.typography.bodySmall) }
                            } else Text("A localização aparecerá assim que o entregador iniciar a rota.")
                        }
                    }
                }
                if (order.status == "completed") {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Como foi seu pedido?", fontWeight = FontWeight.Bold)
                            Row { (1..5).forEach { n -> IconButton({ rating = n }) { Icon(if (n <= rating) Icons.Default.Star else Icons.Default.StarBorder, null) } } }
                            OutlinedTextField(comment, { comment = it.take(1000) }, label = { Text("Comentário · opcional") }, modifier = Modifier.fillMaxWidth())
                            Button({ vm.submitReview(order.orderNumber, rating, comment) }, modifier = Modifier.fillMaxWidth()) { Text("Enviar avaliação") }
                        }
                    }
                }
                OutlinedButton({ vm.repeatOrder(order.orderNumber) }, modifier = Modifier.fillMaxWidth()) { Icon(Icons.Default.Refresh, null); Spacer(Modifier.width(8.dp)); Text("Pedir novamente") }
                OutlinedButton(vm::refreshCurrentOrder, modifier = Modifier.fillMaxWidth()) { Text("Atualizar status") }
            }
        }
    }
}
