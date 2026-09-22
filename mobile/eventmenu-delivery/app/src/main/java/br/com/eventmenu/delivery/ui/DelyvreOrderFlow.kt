package br.com.eventmenu.delivery.ui

import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.OrderSummary
import br.com.eventmenu.delivery.money
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter

@Composable
fun DelyvreExperienceRoot(vm: DeliveryViewModel) {
    when (vm.screen) {
        Screen.Cart, Screen.Checkout, Screen.Orders, Screen.OrderDetail -> DelyvreTheme {
            when (vm.screen) {
                Screen.Cart -> DelyvreCartScreen(vm)
                Screen.Checkout -> DelyvreCheckoutScreen(vm)
                Screen.Orders -> DelyvreOrdersScreen(vm)
                Screen.OrderDetail -> DelyvreOrderDetailScreen(vm)
                else -> Unit
            }
        }
        else -> DelyvreAppRoot(vm)
    }
}

@Composable
private fun DelyvreCartScreen(vm: DeliveryViewModel) {
    val customer = vm.customer
    val store = vm.catalog?.store
    val subtotal = vm.cart.sumOf { it.totalCents() }
    val discount = (vm.couponQuote?.discountCents ?: 0).coerceIn(0, subtotal)
    val fee = store?.deliveryFeeCents ?: 0
    val total = (subtotal - discount).coerceAtLeast(0) + fee
    val minimum = store?.minimumOrderCents ?: 0
    val storeOpen = store?.acceptingOrders != false
    var couponInput by remember(store?.tenantId, vm.couponCode) { mutableStateOf(vm.couponCode) }
    var selectedAddress by remember(customer) { mutableIntStateOf(customer?.addresses?.firstOrNull { it.isDefault }?.id ?: customer?.addresses?.firstOrNull()?.id ?: 0) }
    var editingIndex by remember { mutableStateOf<Int?>(null) }
    editingIndex?.let { index ->
        vm.cart.getOrNull(index)?.let { item ->
            DelyvreProductSheet(
                product = item.product,
                storeOpen = storeOpen,
                onDismiss = { editingIndex = null },
                onAdd = { quantity, options, notes -> vm.replaceCartItem(index, quantity, options, notes); editingIndex = null },
                initialQuantity = item.quantity,
                initialOptionIds = item.optionIds,
                initialNotes = item.notes,
            )
        } ?: run { editingIndex = null }
    }
    val snack = remember { SnackbarHostState() }
    DelyvreMessages(vm, snack)

    Scaffold(topBar = { DelyvreFlowTopBar("Seu pedido") { vm.navigate(Screen.Catalog) } }, snackbarHost = { SnackbarHost(snack) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (vm.cart.isEmpty()) item { DelyvreFlowNotice(Icons.Default.ShoppingBag, "Seu carrinho está vazio", "Volte ao cardápio e escolha o que deseja pedir.") }
            items(vm.cart.size) { index ->
                val item = vm.cart[index]
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text(item.product.name, fontWeight = FontWeight.Bold)
                            if (item.optionIds.isNotEmpty()) Text("${item.optionIds.size} adicional(is)", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            if (item.notes.isNotBlank()) Text("Obs.: ${item.notes}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text(money(item.totalCents()), fontWeight = FontWeight.Black)
                        }
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                IconButton({ vm.updateCart(index, item.quantity - 1) }) { Icon(Icons.Default.Remove, "Diminuir") }
                                Text(item.quantity.toString(), fontWeight = FontWeight.Bold)
                                IconButton({ vm.updateCart(index, item.quantity + 1) }) { Icon(Icons.Default.Add, "Aumentar") }
                            }
                            Row {
                                TextButton({ editingIndex = index }) { Icon(Icons.Default.Edit, null); Spacer(Modifier.width(4.dp)); Text("Editar") }
                                IconButton({ vm.removeCartItem(index) }) { Icon(Icons.Default.DeleteOutline, "Remover") }
                            }
                        }
                    }
                }
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
                            Text("Cupom e benefícios", style = MaterialTheme.typography.titleMedium)
                        }
                        if (!vm.isAuthenticated) {
                            Text("Entre para aplicar cupons, usar benefícios e finalizar o pedido.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            OutlinedButton({ vm.navigate(Screen.Login) }, Modifier.fillMaxWidth()) { Text("Entrar") }
                        } else if (vm.couponQuote != null && vm.couponCode.isNotBlank()) {
                            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(16.dp)) {
                                Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Default.CheckCircle, null, tint = DelyvreSuccess); Spacer(Modifier.width(8.dp))
                                    Column(Modifier.weight(1f)) { Text("${vm.couponCode} aplicado", fontWeight = FontWeight.Bold); Text("Você economiza ${money(discount)}", style = MaterialTheme.typography.bodySmall) }
                                    TextButton({ vm.clearCoupon(); couponInput = "" }) { Text("Remover") }
                                }
                            }
                        } else {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                OutlinedTextField(couponInput, { couponInput = it.uppercase().filter { c -> c.isLetterOrDigit() || c == '-' || c == '_' }.take(80) }, Modifier.weight(1f), label = { Text("Código") }, singleLine = true)
                                Button({ vm.validateCoupon(couponInput) }, enabled = couponInput.isNotBlank() && subtotal > 0) { Text("Aplicar") }
                            }
                        }
                    }
                }
            }
            item {
                Card(colors = CardDefaults.cardColors(containerColor = DelyvreSurfaceMuted)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Resumo", style = MaterialTheme.typography.titleMedium)
                        DelyvreFlowSummary("Subtotal", money(subtotal))
                        if (discount > 0) DelyvreFlowSummary("Desconto", "- ${money(discount)}")
                        DelyvreFlowSummary("Entrega", if (fee <= 0) "Grátis" else money(fee))
                        HorizontalDivider(); DelyvreFlowSummary("Total", money(total), true)
                    }
                }
            }
            if (!storeOpen) item { DelyvreFlowNotice(Icons.Default.Storefront, "Restaurante fechado", "Novos pedidos estão pausados agora. Seu carrinho continua salvo.") }
            if (subtotal < minimum) item { DelyvreFlowNotice(Icons.Default.Info, "Pedido mínimo", "Adicione ${money(minimum - subtotal)} para atingir o mínimo de ${money(minimum)}.") }
            item {
                Text("Como você quer receber?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = true, onClick = {}, label = { Text("Entrega") }, leadingIcon = { Icon(Icons.Default.DeliveryDining, null) })
                    if (store?.pickupEnabled == true) FilterChip(selected = false, onClick = {}, enabled = false, label = { Text("Retirada") }, leadingIcon = { Icon(Icons.Default.Storefront, null) })
                }
                if (store?.pickupEnabled == true) Text("A loja informa que aceita retirada, mas o endpoint atual do DELYVRE ainda exige endereço de entrega. A retirada ficará bloqueada até o servidor expor esse contrato com segurança.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (vm.isAuthenticated) {
                item { Text("Endereço de entrega", style = MaterialTheme.typography.titleLarge) }
                if (customer?.addresses.isNullOrEmpty()) {
                    item { DelyvreFlowNotice(Icons.Default.LocationOn, "Cadastre um endereço", "Precisamos saber onde entregar antes de finalizar."); Button({ vm.editAddress() }, Modifier.fillMaxWidth()) { Text("Adicionar endereço") } }
                } else {
                    items(customer?.addresses.orEmpty(), key = { it.id }) { address ->
                        Card(Modifier.fillMaxWidth().clickable { selectedAddress = address.id }, colors = CardDefaults.cardColors(containerColor = if (selectedAddress == address.id) MaterialTheme.colorScheme.primaryContainer else Color.White), border = CardDefaults.outlinedCardBorder()) {
                            Row(Modifier.padding(13.dp), verticalAlignment = Alignment.CenterVertically) { RadioButton(selectedAddress == address.id, { selectedAddress = address.id }); Column { Text(address.label, fontWeight = FontWeight.Bold); Text("${address.street}, ${address.number} · ${address.city}/${address.state}", style = MaterialTheme.typography.bodySmall) } }
                        }
                    }
                }
            }
            item {
                val canFinish = vm.cart.isNotEmpty() && storeOpen && subtotal >= minimum && (!vm.isAuthenticated || selectedAddress > 0)
                Button({ if (vm.isAuthenticated) vm.checkoutCart(selectedAddress) else vm.navigate(Screen.Login) }, Modifier.fillMaxWidth().heightIn(min = 54.dp), enabled = canFinish) {
                    Text(if (vm.isAuthenticated) "Continuar · ${money(total)}" else "Entrar para finalizar")
                }
            }
        }
    }
}

@Composable
private fun DelyvreCheckoutScreen(vm: DeliveryViewModel) {
    val order = vm.selectedOrder ?: return
    val methods = vm.paymentMethods
    val customer = vm.customer
    val clipboard = LocalClipboardManager.current
    var showCard by remember { mutableStateOf(false) }
    var showCash by remember { mutableStateOf(false) }
    var changeText by remember { mutableStateOf("") }
    val snack = remember { SnackbarHostState() }
    DelyvreMessages(vm, snack)
    LaunchedEffect(showCard, customer?.cpfConfigured) { if (showCard && customer?.cpfConfigured == true && vm.payerCpf.isBlank()) vm.prepareCardIdentification() }

    Scaffold(topBar = { DelyvreFlowTopBar("Pagamento") { vm.openOrder(order.orderNumber) } }, snackbarHost = { SnackbarHost(snack) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Surface(color = DelyvreInk, contentColor = Color.White, shape = RoundedCornerShape(24.dp)) {
                    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(order.storeName, color = Color.White.copy(alpha = .76f)); Text("Pedido #${order.orderNumber}", fontWeight = FontWeight.Bold)
                        Text(money(order.totalCents), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black); Text("Escolha como pagar", color = Color.White.copy(alpha = .82f))
                    }
                }
            }
            if (customer?.cpfConfigured != true) item { DelyvreFlowNotice(Icons.Default.Person, "Complete seu cadastro", "Informe seu CPF uma única vez para liberar Pix e cartão."); Button(vm::openProfile, Modifier.fillMaxWidth()) { Text("Completar cadastro") } }
            if (vm.paymentWaiting) item {
                Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(18.dp)) {
                    Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.size(26.dp), strokeWidth = 3.dp); Column { Text("Aguardando confirmação", fontWeight = FontWeight.Bold); Text("Não faça um segundo pagamento enquanto este estiver pendente.", style = MaterialTheme.typography.bodySmall) } }
                }
            }
            vm.pixPayment?.let { pix -> item {
                Card(border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.fillMaxWidth().padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Text("Pix gerado", style = MaterialTheme.typography.titleMedium); DelyvreFlowQr(pix.copyPaste)?.let { Image(it, "QR Code Pix", Modifier.size(230.dp)) }
                        Text("Pix Copia e Cola", Modifier.align(Alignment.Start), fontWeight = FontWeight.Bold)
                        Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(14.dp)) { Text(pix.copyPaste, Modifier.fillMaxWidth().padding(12.dp), style = MaterialTheme.typography.bodySmall, maxLines = 5) }
                        Button({ clipboard.setText(AnnotatedString(pix.copyPaste)) }, Modifier.fillMaxWidth()) { Icon(Icons.Default.ContentCopy, null); Spacer(Modifier.width(8.dp)); Text("Copiar código Pix") }
                    }
                }
            } }
            if (methods == null) item { LinearProgressIndicator(Modifier.fillMaxWidth()); TextButton(vm::reloadPaymentMethods) { Text("Atualizar formas de pagamento") } }
            else if (!vm.paymentWaiting) {
                methods.pixProviders.firstOrNull()?.let { provider -> item {
                    DelyvreFlowPaymentCard(Icons.Default.QrCode2, "Pix", "Gere o QR Code e pague sem sair do DELYVRE.") { Button({ vm.payPix(provider, "") }, Modifier.fillMaxWidth(), enabled = customer?.cpfConfigured == true) { Text("Gerar Pix") } }
                } }
                methods.cards.firstOrNull()?.let { card -> item {
                    Card(border = CardDefaults.outlinedCardBorder()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            Row(Modifier.fillMaxWidth().clickable(enabled = customer?.cpfConfigured == true) { showCard = !showCard }, verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Default.CreditCard, null, tint = DelyvreBrand); Spacer(Modifier.width(10.dp)); Column(Modifier.weight(1f)) { Text("Cartão", fontWeight = FontWeight.Bold); Text("Crédito ou débito, quando habilitado pelo restaurante.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }; Icon(if (showCard) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null)
                            }
                            if (showCard) {
                                if (vm.payerCpf.length == 11) SavedCpfCardPaymentForm(card.publicKey, card.maxInstallments, customer?.name.orEmpty(), vm.payerCpf, card.paymentTypes) { token, methodId, paymentType, installments -> vm.payCardToken(token, methodId, paymentType, installments, "") }
                                else { LinearProgressIndicator(Modifier.fillMaxWidth()); Text("Preparando pagamento seguro…", style = MaterialTheme.typography.bodySmall) }
                            }
                        }
                    }
                } }
                if (methods.cash) item {
                    Card(border = CardDefaults.outlinedCardBorder()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            Row(Modifier.fillMaxWidth().clickable { showCash = !showCash }, verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.Payments, null, tint = DelyvreBrand); Spacer(Modifier.width(10.dp)); Column(Modifier.weight(1f)) { Text("Dinheiro", fontWeight = FontWeight.Bold); Text("Pague na entrega e informe troco, se precisar.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }; Icon(if (showCash) Icons.Default.ExpandLess else Icons.Default.ExpandMore, null) }
                            if (showCash) { OutlinedTextField(changeText, { changeText = it.filter { c -> c.isDigit() || c == ',' || c == '.' } }, label = { Text("Troco para · opcional") }, modifier = Modifier.fillMaxWidth()); Button({ vm.payCash(changeText.replace(',', '.').toDoubleOrNull()?.let { (it * 100).toInt() }) }, Modifier.fillMaxWidth()) { Text("Pagar na entrega") } }
                        }
                    }
                }
                if (methods.pixProviders.isEmpty() && methods.cards.isEmpty() && !methods.cash) item { DelyvreFlowNotice(Icons.Default.Info, "Pagamento indisponível", "Este restaurante ainda não habilitou uma forma de pagamento.") }
            }
            item { OutlinedButton({ vm.openOrder(order.orderNumber) }, Modifier.fillMaxWidth()) { Text("Acompanhar pedido") } }
        }
    }
}

@Composable
private fun DelyvreOrdersScreen(vm: DeliveryViewModel) {
    var benefitsOpen by remember { mutableStateOf(false) }
    val snack = remember { SnackbarHostState() }
    DelyvreMessages(vm, snack); LaunchedEffect(Unit) { if (vm.orders.isEmpty()) vm.loadOrders() }
    if (benefitsOpen) DelyvreFlowBenefits { benefitsOpen = false }
    Scaffold(topBar = { DelyvreFlowTopBar("Pedidos") }, snackbarHost = { SnackbarHost(snack) }, bottomBar = { DelyvreFlowBottomBar(vm, Screen.Orders) { benefitsOpen = true } }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item { Text("Acompanhe seus pedidos", style = MaterialTheme.typography.headlineSmall) }
            if (vm.orders.isEmpty()) item { DelyvreFlowNotice(Icons.Default.ReceiptLong, "Nenhum pedido ainda", "Quando você pedir, o acompanhamento aparecerá aqui.") }
            items(vm.orders, key = { it.orderNumber }) { order ->
                Card(Modifier.fillMaxWidth().clickable { vm.openOrder(order.orderNumber) }, colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text(order.storeName, fontWeight = FontWeight.Bold); Text(money(order.totalCents), fontWeight = FontWeight.Black) }; Text("Pedido #${order.orderNumber}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(order.statusLabel, color = DelyvreBrandStrong, fontWeight = FontWeight.Bold); Text(delyvreFlowPaymentStatus(order.paymentStatus), style = MaterialTheme.typography.bodySmall) }
                }
            }
        }
    }
}

@Composable
private fun DelyvreOrderDetailScreen(vm: DeliveryViewModel) {
    val order = vm.selectedOrder ?: return
    val context = LocalContext.current
    var rating by remember { mutableIntStateOf(5) }; var comment by remember { mutableStateOf("") }
    val snack = remember { SnackbarHostState() }; DelyvreMessages(vm, snack)
    Scaffold(topBar = { DelyvreFlowTopBar("Pedido #${order.orderNumber}", vm::loadOrders) }, snackbarHost = { SnackbarHost(snack) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            item { Surface(color = DelyvreInk, contentColor = Color.White, shape = RoundedCornerShape(24.dp)) { Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) { Text(order.storeName, style = MaterialTheme.typography.titleLarge); Text(order.statusLabel, color = Color(0xFFFFB0A4), fontWeight = FontWeight.Bold); Text("Total ${money(order.totalCents)}"); Text(delyvreFlowPaymentStatus(order.paymentStatus), color = Color.White.copy(alpha = .78f), style = MaterialTheme.typography.bodySmall) } } }
            item { DelyvreFlowTimeline(order) }
            vm.tracking?.let { tracking -> item {
                Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) { Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) { Icon(Icons.Default.DeliveryDining, null, tint = DelyvreBrand); Text("Entrega", fontWeight = FontWeight.Bold) }; Text(tracking.statusLabel); if (tracking.latitude != null && tracking.longitude != null) { Text("Localização aproximada disponível.", style = MaterialTheme.typography.bodySmall); Button({ val geo = Uri.parse("geo:${tracking.latitude},${tracking.longitude}?q=${tracking.latitude},${tracking.longitude}(Entrega)"); runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, geo)) } }, Modifier.fillMaxWidth()) { Icon(Icons.Default.Map, null); Spacer(Modifier.width(8.dp)); Text("Ver no mapa") }; tracking.recordedAt?.takeIf { it.isNotBlank() }?.let { Text("Última atualização: $it", style = MaterialTheme.typography.bodySmall) } } else Text("O mapa aparece quando a rota de entrega começar.", style = MaterialTheme.typography.bodySmall) }
                }
            } }
            if (order.status.lowercase() in setOf("completed", "delivered")) item {
                Card(border = CardDefaults.outlinedCardBorder()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Como foi seu pedido?", style = MaterialTheme.typography.titleMedium); Row { (1..5).forEach { star -> IconButton({ rating = star }) { Icon(if (star <= rating) Icons.Default.Star else Icons.Default.StarBorder, null, tint = Color(0xFFFFA000)) } } }; OutlinedTextField(comment, { comment = it.take(1000) }, label = { Text("Comentário · opcional") }, modifier = Modifier.fillMaxWidth()); Button({ vm.submitReview(order.orderNumber, rating, comment) }, Modifier.fillMaxWidth()) { Text("Enviar avaliação") } } }
            }
            item { OutlinedButton({ vm.repeatOrder(order.orderNumber) }, Modifier.fillMaxWidth()) { Icon(Icons.Default.Refresh, null); Spacer(Modifier.width(8.dp)); Text("Pedir novamente") } }
            item { OutlinedButton(vm::refreshCurrentOrder, Modifier.fillMaxWidth()) { Text("Atualizar pedido") } }
        }
    }
}

@Composable private fun DelyvreFlowTimeline(order: OrderSummary) {
    val status = order.status.lowercase(); val paid = order.paymentStatus.lowercase() == "paid"; val completed = status in setOf("completed", "delivered"); val delivery = status in setOf("out_for_delivery", "delivery", "delivering", "on_the_way") || completed; val ready = status in setOf("ready", "ready_for_delivery", "ready_for_pickup") || delivery; val preparing = status in setOf("preparing", "in_preparation", "production", "accepted") || ready; val cancelled = status in setOf("cancelled", "canceled")
    val steps = listOf("Pedido recebido" to true, "Pagamento confirmado" to paid, "Preparando" to preparing, "Pronto" to ready, "Saiu para entrega" to delivery, "Entregue" to completed)
    Card(border = CardDefaults.outlinedCardBorder()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) { Text("Acompanhamento", style = MaterialTheme.typography.titleMedium); if (cancelled) Surface(color = MaterialTheme.colorScheme.errorContainer, shape = RoundedCornerShape(14.dp)) { Text("Pedido cancelado", Modifier.fillMaxWidth().padding(12.dp), color = MaterialTheme.colorScheme.onErrorContainer, fontWeight = FontWeight.Bold) } else steps.forEach { (label, done) -> Row(verticalAlignment = Alignment.CenterVertically) { Icon(if (done) Icons.Default.CheckCircle else Icons.Default.RadioButtonUnchecked, null, tint = if (done) DelyvreSuccess else DelyvreMuted); Spacer(Modifier.width(10.dp)); Text(label, fontWeight = if (done) FontWeight.Bold else FontWeight.Normal, color = if (done) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant) } } } }
}

@Composable private fun DelyvreFlowPaymentCard(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, subtitle: String, content: @Composable ColumnScope.() -> Unit) { Card(border = CardDefaults.outlinedCardBorder()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) { Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) { Icon(icon, null, tint = DelyvreBrand); Column { Text(title, fontWeight = FontWeight.Bold); Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) } }; content() } } }
@Composable private fun DelyvreFlowNotice(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, text: String) { Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(18.dp)) { Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) { Icon(icon, null, tint = DelyvreBrand); Column(Modifier.weight(1f)) { Text(title, fontWeight = FontWeight.Bold); Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) } } } }
@Composable private fun DelyvreFlowSummary(label: String, value: String, strong: Boolean = false) { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text(label, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal); Text(value, fontWeight = if (strong) FontWeight.Black else FontWeight.SemiBold, color = if (strong) DelyvreBrandStrong else MaterialTheme.colorScheme.onSurface) } }
@OptIn(ExperimentalMaterial3Api::class) @Composable private fun DelyvreFlowTopBar(title: String, onBack: (() -> Unit)? = null) { TopAppBar(title = { Text(title, fontWeight = FontWeight.Bold) }, navigationIcon = { if (onBack != null) IconButton(onBack) { Icon(Icons.Default.ArrowBack, "Voltar") } }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) }
@Composable private fun DelyvreFlowBottomBar(vm: DeliveryViewModel, current: Screen, onBenefits: () -> Unit) { NavigationBar(containerColor = Color.White) { NavigationBarItem(false, { vm.navigate(Screen.Home); vm.loadStores() }, { Icon(Icons.Default.Home, null) }, label = { Text("Início") }); NavigationBarItem(false, { vm.navigate(Screen.Home); vm.loadStores() }, { Icon(Icons.Default.Search, null) }, label = { Text("Buscar") }); NavigationBarItem(current == Screen.Orders, vm::loadOrders, { Icon(Icons.Default.ReceiptLong, null) }, label = { Text("Pedidos") }); NavigationBarItem(false, onBenefits, { Icon(Icons.Default.LocalOffer, null) }, label = { Text("Benefícios") }); NavigationBarItem(false, vm::openProfile, { Icon(Icons.Default.Person, null) }, label = { Text("Perfil") }) } }
@Composable private fun DelyvreFlowBenefits(onDismiss: () -> Unit) { AlertDialog(onDismissRequest = onDismiss, confirmButton = { TextButton(onDismiss) { Text("Entendi") } }, icon = { Icon(Icons.Default.LocalOffer, null, tint = DelyvreBrand) }, title = { Text("Benefícios") }, text = { Text("Cupons, descontos e vantagens disponíveis aparecem no carrinho e são conferidos antes da finalização.") }) }
@Composable private fun DelyvreMessages(vm: DeliveryViewModel, host: SnackbarHostState) { LaunchedEffect(vm.message) { vm.message?.let { host.showSnackbar(delyvrePublicMessage(it)); vm.clearMessage() } } }
private fun delyvrePublicMessage(value: String): String = value.replace("EventMenu Delivery", "DELYVRE").replace("EventMenu", "DELYVRE").replace("Mercado Pago", "pagamento")
private fun delyvreFlowPaymentStatus(value: String): String = when (value.lowercase()) { "paid" -> "Pagamento confirmado"; "pending", "created", "processing", "authorized" -> "Pagamento pendente"; "failed", "rejected" -> "Pagamento recusado"; "cancelled", "canceled" -> "Pagamento cancelado"; else -> "Pagamento em atualização" }
@Composable private fun DelyvreFlowQr(text: String): ImageBitmap? = remember(text) { if (text.isBlank()) null else runCatching { val size = 600; val matrix = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size); val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888); for (y in 0 until size) for (x in 0 until size) bitmap.setPixel(x, y, if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE); bitmap.asImageBitmap() }.getOrNull() }
