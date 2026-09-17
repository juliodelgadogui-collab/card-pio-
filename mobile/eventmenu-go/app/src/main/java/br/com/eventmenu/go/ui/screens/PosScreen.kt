package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.DiscountRequest
import br.com.eventmenu.go.data.LoyaltyOrderSummary
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
    var channel by remember(table?.id) { mutableStateOf(if (table != null) "table" else "counter") }
    var customer by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val categories = listOf("Todos") + state.products.map { it.categoryName }.distinct()
    val visible = state.products.filter { category == "Todos" || it.categoryName == category }
    val cartLines = state.cart.mapNotNull { (id, qty) -> state.products.firstOrNull { it.id == id }?.let { it to qty } }
    val previewTotal = cartLines.sumOf { (product, qty) -> product.priceCents * qty }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text(if (table != null) "Venda · ${table.name}" else "Nova venda", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            if (table != null) {
                table.tabLabel.takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                Text("Os itens serão adicionados à conta da mesa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedButton(onClick = onClearTable, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("Voltar às mesas") }
            } else {
                Text("Escolha os produtos e finalize o pedido.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }

        item {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                items(categories) { name -> FilterChip(selected = category == name, onClick = { category = name }, label = { Text(name) }) }
            }
        }

        items(visible, key = { it.id }) { product ->
            Card(Modifier.fillMaxWidth()) {
                Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                    Column(Modifier.weight(1f)) {
                        Text(product.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(product.categoryName, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        if (product.description.isNotBlank()) Text(product.description, maxLines = 2)
                        Text(posMoney(product.priceCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        if (product.trackStock) Text("Disponível: ${product.stockQty}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Button(onClick = { onAdd(product.id) }, enabled = !product.trackStock || product.stockQty > 0) { Text("+") }
                }
            }
        }

        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Carrinho", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        if (cartLines.isNotEmpty()) TextButton(onClick = onClear) { Text("Limpar") }
                    }
                    if (cartLines.isEmpty()) Text("Nenhum item.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    cartLines.forEach { (product, qty) ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("${qty}× ${product.name}")
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text(posMoney(product.priceCents * qty), fontWeight = FontWeight.Bold)
                                OutlinedButton(onClick = { onRemove(product.id) }) { Text("−") }
                                OutlinedButton(onClick = { onAdd(product.id) }) { Text("+") }
                            }
                        }
                    }
                    HorizontalDivider()
                    Text("Total ${posMoney(previewTotal)}", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                }
            }
        }

        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                    if (table == null) {
                        Text("Tipo do pedido", fontWeight = FontWeight.Bold)
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            FilterChip(selected = channel == "counter", onClick = { channel = "counter" }, label = { Text("Balcão") })
                            FilterChip(selected = channel == "pickup", onClick = { channel = "pickup" }, label = { Text("Retirada") })
                            FilterChip(selected = channel == "delivery", onClick = { channel = "delivery" }, label = { Text("Delivery") })
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
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text(if (table != null) "Adicionar à mesa" else "Finalizar pedido") }
                }
            }
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
    val permissions = state.session?.permissions.orEmpty()
    val canManagePayments = "payments" in permissions
    val canCash = canManagePayments && "cash" in permissions
    val canPix = canManagePayments
    val canNfc = "nfc_collect" in permissions
    val canCollectHere = canCash || canPix || canNfc
    val canRedeemLoyalty = "loyalty_redeem" in permissions
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
                .onFailure { loyaltyError = it.message ?: "Não foi possível consultar os pontos." }
            loyaltyBusy = false
        }
    }

    LaunchedEffect(order.id, canRedeemLoyalty) {
        if (canRedeemLoyalty) {
            runCatching { app.orderOperationsRepository.detail(order.id).loyalty }
                .onSuccess { loyalty = it; loyaltyLoaded = true }
                .onFailure { loyaltyError = it.message ?: "Não foi possível consultar os pontos."; loyaltyLoaded = true }
        }
    }

    LazyColumn(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("Receber · Pedido #${order.id}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            if (state.posReturnScreen == AppScreen.TABLE_ACCOUNT) Text("Pagamento da conta da mesa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("Total: ${posMoney(balance?.totalCents ?: order.totalCents)}")
            if ((balance?.paidCents ?: 0) > 0) Text("Recebido: ${posMoney(balance?.paidCents ?: 0)}")
            Text("Falta receber: ${posMoney(remaining)}", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
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
                        Text("${current.available} pontos disponíveis", fontWeight = FontWeight.Bold)
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
                                            .onFailure { loyaltyError = it.message ?: "Não foi possível remover os pontos." }
                                        loyaltyBusy = false
                                    }
                                },
                                enabled = !loyaltyBusy,
                                modifier = Modifier.fillMaxWidth(),
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
                                            .onFailure { loyaltyError = it.message ?: "Não foi possível usar os pontos." }
                                        loyaltyBusy = false
                                    }
                                },
                                enabled = canApply,
                                modifier = Modifier.fillMaxWidth(),
                            ) { Text(if (loyaltyBusy) "Aplicando..." else "Usar pontos") }
                        }
                        loyaltyError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                        OutlinedButton(onClick = ::refreshLoyalty, enabled = !loyaltyBusy, modifier = Modifier.fillMaxWidth()) { Text("Atualizar saldo") }
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
                                OutlinedButton(onClick = { onRefreshDiscount(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") }
                            }
                            "approved" -> Text("Desconto aprovado · ${posMoney(discountRequest.requestedCents)}", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
                            "rejected" -> Text("A última solicitação de desconto não foi aprovada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            else -> Text("Você pode solicitar um desconto para aprovação.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (discountRequest?.status != "pending") OutlinedButton(onClick = { discountDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("Solicitar desconto") }
                    }
                }
            }
        }

        if (balance?.payments?.isNotEmpty() == true) {
            item { Text("Recebimentos", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            items(balance.payments, key = { it.id }) { part ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column {
                            Text(paymentLabel(part.provider), fontWeight = FontWeight.Bold)
                            Text(paymentStatusLabel(part.status), color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text(posMoney(part.amountCents), fontWeight = FontWeight.Black)
                    }
                }
            }
        }

        if (remaining > 0 && canCollectHere) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("Receber", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Você pode receber o valor inteiro ou apenas uma parte.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        OutlinedTextField(amountText, { amountText = it }, label = { Text("Valor (R$)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                        Text("Disponível para receber: ${posMoney(remaining)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        if (canCash) {
                            Button(onClick = { onCash(amount) }, enabled = state.cashOpen && amount in 1..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth()) { Text("Dinheiro") }
                            if (!state.cashOpen) Text("Abra o caixa para receber em dinheiro.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (canPix) Button(onClick = { pixTaxDialog = true }, enabled = amount in 1..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth()) { Text("PIX") }
                        if (canNfc) Button(onClick = { onNfc(amount) }, enabled = amount in 100..remaining && discountRequest?.status != "pending", modifier = Modifier.fillMaxWidth()) { Text("Cartão por aproximação") }
                        if (discountRequest?.status == "pending") Text("Aguarde a aprovação do desconto para continuar.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        } else if (remaining > 0) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.small) {
                            Text("Pedido criado", modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onSecondaryContainer, fontWeight = FontWeight.Bold)
                        }
                        Text("Sua função não recebe pagamentos. O pedido foi salvo e pode ser recebido pelo caixa ou por um operador autorizado.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text("Pedido #${order.id} · Pendente ${posMoney(remaining)}", fontWeight = FontWeight.Bold)
                        Button(onClick = onFinishFlow, modifier = Modifier.fillMaxWidth()) { Text(if (state.posReturnScreen == AppScreen.TABLE_ACCOUNT) "Voltar à conta" else "Novo pedido") }
                    }
                }
            }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.small) {
                            Text("Pagamento concluído", modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onSecondaryContainer, fontWeight = FontWeight.Bold)
                        }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { onReceipt(order.id) }, modifier = Modifier.weight(1f)) { Text("Enviar recibo") }
                            OutlinedButton(onClick = { onPrintReceipt(order.id) }, modifier = Modifier.weight(1f)) { Text("Imprimir") }
                        }
                        Button(onClick = onFinishFlow, modifier = Modifier.fillMaxWidth()) { Text(if (state.posReturnScreen == AppScreen.TABLE_ACCOUNT) "Voltar à conta" else "Novo pedido") }
                    }
                }
            }
        }

        item { OutlinedButton(onClick = { onRefresh(); onRefreshDiscount(order.id); refreshLoyalty() }, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") } }
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

    if (pixTaxDialog && canPix) {
        var taxId by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { pixTaxDialog = false },
            title = { Text("PIX · ${posMoney(amount)}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Informe CPF ou CNPJ para gerar o PIX.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF ou CNPJ") }, singleLine = true)
                }
            },
            confirmButton = { Button(onClick = { pixTaxDialog = false; onPix(amount, taxId) }, enabled = taxId.length in setOf(11, 14)) { Text("Gerar PIX") } },
            dismissButton = { TextButton(onClick = { pixTaxDialog = false }) { Text("Cancelar") } },
        )
    }
}

private fun paymentLabel(provider: String) = when (provider.lowercase()) {
    "manual" -> "Dinheiro"
    "pagbank", "stripe", "mercadopago" -> "Pagamento eletrônico"
    else -> "Pagamento"
}

private fun paymentStatusLabel(status: String) = when (status.lowercase()) {
    "paid", "approved", "confirmed" -> "Confirmado"
    "pending", "processing" -> "Processando"
    "refunded" -> "Estornado"
    "failed", "cancelled" -> "Não concluído"
    else -> "Em andamento"
}

private fun posMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
