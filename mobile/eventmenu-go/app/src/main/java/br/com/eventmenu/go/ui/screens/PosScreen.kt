package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.OperationalText
import br.com.eventmenu.go.data.DiscountRequest
import br.com.eventmenu.go.data.LoyaltyOrderSummary
import br.com.eventmenu.go.ui.theme.EventMenuUi
import kotlinx.coroutines.launch

@Composable
fun PosScreen(
    state: GoState,
    discountRequest: DiscountRequest?,
    canRequestDiscount: Boolean,
    onRequestDiscount: (Int, Int, String) -> Unit,
    onRefreshDiscount: (Int) -> Unit,
    onAdd: (Int) -> Unit,
    onRemove: (Int) -> Unit,
    onClear: () -> Unit,
    onCreate: (String, String, String, String, String) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int, String) -> Unit,
    onNfc: (Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onRefreshPayment: () -> Unit,
    onFinishFlow: () -> Unit,
    onClearTable: () -> Unit,
) {
    val order = state.posOrder
    if (order != null) {
        PosPaymentScreen(state, discountRequest, canRequestDiscount, onRequestDiscount, onRefreshDiscount, onCash, onPix, onNfc, onReceipt, onPrintReceipt, onRefreshPayment, onFinishFlow)
        return
    }

    val table = state.selectedTable
    var category by remember { mutableStateOf("Todos") }
    var search by remember { mutableStateOf("") }
    var channel by remember(table?.id) { mutableStateOf(if (table != null) "table" else "counter") }
    var customer by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val categories = listOf("Todos") + state.products.map { it.categoryName }.distinct()
    val searchTerm = search.trim().lowercase()
    val visible = state.products.filter { product ->
        (category == "Todos" || product.categoryName == category) &&
            (searchTerm.isBlank() || product.name.lowercase().contains(searchTerm) || product.description.lowercase().contains(searchTerm) || product.categoryName.lowercase().contains(searchTerm))
    }
    val cartLines = state.cart.mapNotNull { (id, qty) -> state.products.firstOrNull { it.id == id }?.let { it to qty } }
    val previewTotal = cartLines.sumOf { (product, qty) -> product.priceCents * qty }
    val totalItems = cartLines.sumOf { it.second }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text(if (table != null) "Venda · ${table.name}" else "Nova venda", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    if (table != null) {
                        table.tabLabel.takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold) }
                        Text("Os itens entram direto na conta da mesa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        Text("Busque, adicione e revise antes de cobrar.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                if (totalItems > 0) PosCartPill(totalItems, previewTotal)
            }
        }

        if (table != null) {
            item {
                OutlinedButton(onClick = onClearTable, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) {
                    Icon(Icons.Default.ArrowBack, contentDescription = null)
                    Spacer(Modifier.width(7.dp))
                    Text("Voltar às mesas")
                }
            }
        }

        item {
            OutlinedTextField(
                value = search,
                onValueChange = { search = it.take(80) },
                label = { Text("Buscar produto") },
                placeholder = { Text("Nome, descrição ou categoria") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                trailingIcon = {
                    if (search.isNotBlank()) IconButton(onClick = { search = "" }) { Icon(Icons.Default.Close, contentDescription = "Limpar busca") }
                },
            )
        }

        item {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                items(categories) { name -> FilterChip(selected = category == name, onClick = { category = name }, label = { Text(name) }) }
            }
        }

        if (cartLines.isNotEmpty()) {
            item {
                Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium, modifier = Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(13.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Column {
                            Text("Pedido em andamento", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onPrimaryContainer)
                            Text("$totalItems item(ns) · ${posMoney(previewTotal)}", fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.onPrimaryContainer)
                        }
                        Icon(Icons.Default.ShoppingCart, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    }
                }
            }
        }

        if (visible.isEmpty()) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Icon(Icons.Default.SearchOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(if (state.products.isEmpty()) "Nenhum produto disponível" else "Nada encontrado", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text(if (state.products.isEmpty()) "Atualize o catálogo ou verifique a disponibilidade dos produtos." else "Tente outro nome ou escolha uma categoria diferente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }

        items(visible, key = { it.id }) { product ->
            val qty = state.cart[product.id] ?: 0
            val available = !product.trackStock || product.stockQty > 0
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text(product.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(product.categoryName, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.bodyMedium)
                        if (product.description.isNotBlank()) Text(product.description, maxLines = 2, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(posMoney(product.priceCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        if (product.trackStock) Text(if (product.stockQty > 0) "Estoque: ${product.stockQty}" else "Indisponível agora", color = if (product.stockQty > 0) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                    }
                    if (qty > 0) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                                OutlinedButton(onClick = { onRemove(product.id) }, modifier = Modifier.size(48.dp), contentPadding = PaddingValues(0.dp)) { Text("−", style = MaterialTheme.typography.titleLarge) }
                                Text(qty.toString(), modifier = Modifier.widthIn(min = 24.dp), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                                Button(onClick = { onAdd(product.id) }, enabled = available, modifier = Modifier.size(48.dp), contentPadding = PaddingValues(0.dp)) { Text("+", style = MaterialTheme.typography.titleLarge) }
                            }
                            Text(posMoney(product.priceCents * qty), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    } else {
                        Button(onClick = { onAdd(product.id) }, enabled = available, modifier = Modifier.heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Add, contentDescription = null)
                            Spacer(Modifier.width(5.dp))
                            Text("Adicionar")
                        }
                    }
                }
            }
        }

        item {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Column {
                            Text("Revisar pedido", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            Text(if (cartLines.isEmpty()) "Adicione produtos para continuar." else "$totalItems item(ns) selecionado(s)", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (cartLines.isNotEmpty()) TextButton(onClick = onClear) { Text("Limpar") }
                    }
                    if (cartLines.isEmpty()) {
                        Text("O total aparecerá aqui conforme os produtos forem adicionados.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    cartLines.forEach { (product, qty) ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("${qty}× ${product.name}", fontWeight = FontWeight.SemiBold)
                                Text(posMoney(product.priceCents * qty), color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            Row(horizontalArrangement = Arrangement.spacedBy(5.dp), verticalAlignment = Alignment.CenterVertically) {
                                OutlinedButton(onClick = { onRemove(product.id) }, modifier = Modifier.size(44.dp), contentPadding = PaddingValues(0.dp)) { Text("−") }
                                OutlinedButton(onClick = { onAdd(product.id) }, modifier = Modifier.size(44.dp), contentPadding = PaddingValues(0.dp)) { Text("+") }
                            }
                        }
                    }
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Text("Total", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(posMoney(previewTotal), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                    }
                }
            }
        }

        item {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(if (table == null) "Dados do atendimento" else "Confirmar comanda", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    if (table == null) {
                        Text("Como será o atendimento?", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            item { FilterChip(selected = channel == "counter", onClick = { channel = "counter" }, label = { Text("Balcão") }) }
                            item { FilterChip(selected = channel == "pickup", onClick = { channel = "pickup" }, label = { Text("Retirada") }) }
                            item { FilterChip(selected = channel == "delivery", onClick = { channel = "delivery" }, label = { Text("Entrega") }) }
                        }
                    } else {
                        Text("Pedido para ${table.name}", fontWeight = FontWeight.Bold)
                    }
                    OutlinedTextField(customer, { customer = it }, label = { Text(if (channel == "delivery") "Cliente" else "Cliente (opcional)") }, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(phone, { phone = it }, label = { Text(if (channel == "delivery") "Telefone" else "Telefone (opcional)") }, modifier = Modifier.fillMaxWidth())
                    if (channel == "delivery") OutlinedTextField(address, { address = it }, label = { Text("Endereço") }, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(notes, { notes = it }, label = { Text("Observações") }, modifier = Modifier.fillMaxWidth())
                    Button(
                        onClick = { onCreate(channel, customer, phone, address, notes) },
                        enabled = cartLines.isNotEmpty() && (channel != "delivery" || (customer.isNotBlank() && phone.isNotBlank() && address.isNotBlank())),
                        modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
                    ) {
                        Icon(if (table != null) Icons.Default.TableRestaurant else Icons.Default.ArrowForward, contentDescription = null)
                        Spacer(Modifier.width(8.dp))
                        Text(if (table != null) "Adicionar à mesa" else "Continuar para pagamento")
                    }
                }
            }
        }
    }
}

@Composable
private fun PosCartPill(items: Int, total: Int) {
    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium) {
        Column(Modifier.padding(horizontal = 11.dp, vertical = 8.dp), horizontalAlignment = Alignment.End) {
            Text("$items item(ns)", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
            Text(posMoney(total), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
    }
}

@Composable
private fun PosPaymentScreen(
    state: GoState,
    discountRequest: DiscountRequest?,
    canRequestDiscount: Boolean,
    onRequestDiscount: (Int, Int, String) -> Unit,
    onRefreshDiscount: (Int) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int, String) -> Unit,
    onNfc: (Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onRefresh: () -> Unit,
    onFinishFlow: () -> Unit,
) {
    val order = state.posOrder ?: return
    val balance = state.paymentBalance
    val remaining = balance?.remainingCents ?: order.totalCents
    var amountText by remember(remaining) { mutableStateOf("%.2f".format(remaining / 100.0).replace('.', ',')) }
    val amount = ((amountText.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
    var pixTaxDialog by remember { mutableStateOf(false) }
    var discountDialog by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val scope = rememberCoroutineScope()
    val canRedeemLoyalty = "loyalty_redeem" in state.session?.permissions.orEmpty()
    var loyalty by remember(order.id) { mutableStateOf<LoyaltyOrderSummary?>(null) }
    var loyaltyLoaded by remember(order.id) { mutableStateOf(false) }
    var loyaltyBusy by remember(order.id) { mutableStateOf(false) }
    var loyaltyError by remember(order.id) { mutableStateOf<String?>(null) }
    var loyaltyPointsText by remember(order.id) { mutableStateOf("") }

    fun refreshLoyalty() {
        if (!canRedeemLoyalty || loyaltyBusy) return
        loyaltyBusy = true
        loyaltyError = null
        scope.launch {
            runCatching { app.orderOperationsRepository.detail(order.id).loyalty }
                .onSuccess { loyalty = it; loyaltyLoaded = true }
                .onFailure { loyaltyError = OperationalText.friendlyApiMessage(it.message) }
            loyaltyBusy = false
        }
    }

    LaunchedEffect(order.id, canRedeemLoyalty) {
        if (canRedeemLoyalty) {
            runCatching { app.orderOperationsRepository.detail(order.id).loyalty }
                .onSuccess { loyalty = it; loyaltyLoaded = true }
                .onFailure { loyaltyError = OperationalText.friendlyApiMessage(it.message); loyaltyLoaded = true }
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = if (remaining > 0) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.secondaryContainer)) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Receber · Pedido #${order.id}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    if (state.posReturnScreen == AppScreen.TABLE_ACCOUNT) Text("Pagamento da conta da mesa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text("Total: ${posMoney(balance?.totalCents ?: order.totalCents)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if ((balance?.paidCents ?: 0) > 0) Text("Recebido: ${posMoney(balance?.paidCents ?: 0)}", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.SemiBold)
                    HorizontalDivider()
                    Text(if (remaining > 0) "Falta receber" else "Pagamento", style = MaterialTheme.typography.labelLarge)
                    Text(if (remaining > 0) posMoney(remaining) else "✓ Confirmado", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black)
                }
            }
        }

        if (canRedeemLoyalty && loyaltyLoaded && loyalty != null && remaining > 0) {
            item {
                val current = loyalty!!
                val reservation = current.orderReservation?.takeIf { it.status == "reserved" }
                val requested = loyaltyPointsText.toIntOrNull() ?: 0
                val validBlock = current.redeemPoints > 0 && requested % current.redeemPoints == 0
                val canApply = current.enabled && reservation == null && (balance?.paidCents ?: 0) == 0 && requested >= current.minRedeemPoints && requested <= current.available && validBlock && !loyaltyBusy
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Pontos do cliente", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Você tem ${current.available} pontos", fontWeight = FontWeight.Bold)
                        if (!current.enabled) {
                            Text("O programa de pontos está desativado para esta empresa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else if ((balance?.paidCents ?: 0) > 0) {
                            Text("O pedido já possui valor recebido. Os pontos não podem mais ser alterados.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else if (reservation != null) {
                            Text("${reservation.points} pontos reservados · desconto de ${posMoney(reservation.discountCents)}", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
                            OutlinedButton(
                                onClick = {
                                    loyaltyBusy = true; loyaltyError = null
                                    scope.launch {
                                        runCatching { app.orderOperationsRepository.removeLoyalty(order.id) }
                                            .onSuccess { updated -> loyalty = updated; loyaltyPointsText = ""; onRefresh() }
                                            .onFailure { loyaltyError = OperationalText.friendlyApiMessage(it.message) }
                                        loyaltyBusy = false
                                    }
                                },
                                enabled = !loyaltyBusy,
                                modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget),
                            ) { Text("Remover pontos deste pedido") }
                        } else {
                            Text("${current.redeemPoints} pontos = ${posMoney(current.redeemValueCents)} · mínimo ${current.minRedeemPoints} pontos", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text("O desconto pode cobrir até ${current.maxRedeemPercent}% do pedido.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            OutlinedTextField(
                                loyaltyPointsText,
                                { loyaltyPointsText = it.filter(Char::isDigit).take(8) },
                                label = { Text("Pontos para usar") },
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth(),
                            )
                            Button(
                                onClick = {
                                    loyaltyBusy = true; loyaltyError = null
                                    scope.launch {
                                        runCatching { app.orderOperationsRepository.applyLoyalty(order.id, requested) }
                                            .onSuccess { updated -> loyalty = updated; onRefresh() }
                                            .onFailure { loyaltyError = OperationalText.friendlyApiMessage(it.message) }
                                        loyaltyBusy = false
                                    }
                                },
                                enabled = canApply,
                                modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
                            ) { Text(if (loyaltyBusy) "Aplicando…" else "Usar pontos") }
                        }
                        loyaltyError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                        OutlinedButton(onClick = ::refreshLoyalty, enabled = !loyaltyBusy, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Atualizar saldo") }
                    }
                }
            }
        }

        if (canRequestDiscount && (balance?.paidCents ?: 0) == 0 && remaining > 0) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Text("Desconto", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        when (discountRequest?.status) {
                            "pending" -> {
                                Text("Aguardando aprovação · ${posMoney(discountRequest.requestedCents)}", fontWeight = FontWeight.SemiBold)
                                Text(discountRequest.reason)
                                OutlinedButton(onClick = { onRefreshDiscount(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Atualizar situação") }
                            }
                            "approved" -> Text("✓ Desconto aprovado · ${posMoney(discountRequest.requestedCents)}", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
                            "rejected" -> Text("A última solicitação de desconto não foi aprovada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            else -> Text("Você pode solicitar um desconto para aprovação.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (discountRequest?.status != "pending") OutlinedButton(onClick = { discountDialog = true }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Solicitar desconto") }
                    }
                }
            }
        }

        if (balance?.payments?.isNotEmpty() == true) {
            item { Text("Recebimentos", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            items(balance.payments, key = { it.id }) { part ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Column {
                            Text(OperationalText.paymentMethod(part.provider), fontWeight = FontWeight.Bold)
                            Text(OperationalText.paymentStatus(part.status), color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text(posMoney(part.amountCents), fontWeight = FontWeight.Black)
                    }
                }
            }
        }

        if (remaining > 0) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Text("Receber pagamento", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Receba o valor inteiro ou apenas uma parte.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        OutlinedTextField(amountText, { amountText = it }, label = { Text("Valor (R$)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                        Text("Disponível para receber: ${posMoney(remaining)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { onCash(amount) }, enabled = state.cashOpen && amount in 1..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Payments, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Dinheiro")
                        }
                        if (!state.cashOpen) Text("Abra o caixa para receber em dinheiro.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { pixTaxDialog = true }, enabled = amount in 1..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.QrCode2, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("PIX")
                        }
                        Button(onClick = { onNfc(amount) }, enabled = amount in 100..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Contactless, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Cartão por aproximação")
                        }
                        if (discountRequest?.status == "pending") Text("Aguarde a aprovação do desconto para continuar.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.small) {
                            Text("✓ Pagamento confirmado", modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp), color = MaterialTheme.colorScheme.onSecondaryContainer, fontWeight = FontWeight.Bold)
                        }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { onReceipt(order.id) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Enviar recibo") }
                            OutlinedButton(onClick = { onPrintReceipt(order.id) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Imprimir") }
                        }
                        Button(onClick = onFinishFlow, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) { Text(if (state.posReturnScreen == AppScreen.TABLE_ACCOUNT) "Voltar à conta" else "Novo pedido") }
                    }
                }
            }
        }

        item {
            OutlinedButton(onClick = { onRefresh(); onRefreshDiscount(order.id); refreshLoyalty() }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("Atualizar situação")
            }
        }
    }

    if (discountDialog) {
        var value by remember { mutableStateOf("") }
        var reason by remember { mutableStateOf("") }
        val cents = ((value.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
        AlertDialog(
            onDismissRequest = { discountDialog = false },
            title = { Text("Solicitar desconto") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Pedido #${order.id}")
                    OutlinedTextField(value, { value = it }, label = { Text("Valor do desconto (R$)") }, singleLine = true)
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo") }, modifier = Modifier.fillMaxWidth())
                    Text("O desconto será aplicado somente depois da aprovação.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            },
            confirmButton = { Button(onClick = { discountDialog = false; onRequestDiscount(order.id, cents, reason) }, enabled = cents > 0 && reason.isNotBlank()) { Text("Enviar para aprovação") } },
            dismissButton = { TextButton(onClick = { discountDialog = false }) { Text("Cancelar") } },
        )
    }

    if (pixTaxDialog) {
        var taxId by remember { mutableStateOf("") }
        val taxIdValid = taxId.isEmpty() || taxId.length in setOf(11, 14)
        AlertDialog(
            onDismissRequest = { pixTaxDialog = false },
            title = { Text("PIX · ${posMoney(amount)}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("CPF/CNPJ é opcional. Se ficar vazio, o EventMenu usa os dados disponíveis ou o documento padrão configurado pela empresa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF ou CNPJ (opcional)") }, singleLine = true)
                    if (taxId.isNotEmpty() && !taxIdValid) Text("Informe 11 dígitos para CPF ou 14 para CNPJ.", color = MaterialTheme.colorScheme.error)
                }
            },
            confirmButton = { Button(onClick = { pixTaxDialog = false; onPix(amount, taxId) }, enabled = taxIdValid) { Text("Gerar PIX") } },
            dismissButton = { TextButton(onClick = { pixTaxDialog = false }) { Text("Cancelar") } },
        )
    }
}

private fun posMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
