package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.OrderOperationsViewModel
import br.com.eventmenu.go.data.DeliveryUser
import br.com.eventmenu.go.data.OperatingUnit
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.UnassignedUnitDelivery
import br.com.eventmenu.go.ui.theme.EventMenuUi

@Composable
fun DispatchScreen(
    orders: List<Order>,
    deliveryUsers: List<DeliveryUser>,
    canAssignDelivery: Boolean,
    focusOrderId: Int?,
    unassignedUnitOrders: List<UnassignedUnitDelivery> = emptyList(),
    units: List<OperatingUnit> = emptyList(),
    canRouteUnit: Boolean = false,
    onRefresh: () -> Unit,
    onDispatch: (Order) -> Unit,
    onAssignDelivery: (Int, Int) -> Unit,
    onScanDelivery: (Int) -> Unit,
    onAssignUnit: (Int, Int) -> Unit = { _, _ -> },
) {
    val app = LocalContext.current.applicationContext as EventMenuGoApplication
    val orderViewModel: OrderOperationsViewModel = viewModel(factory = OrderOperationsViewModel.Factory(app.orderOperationsRepository))
    val orderState by orderViewModel.state.collectAsState()
    val pending = orders.filter { it.status == "pending" && it.channel in setOf("counter", "pickup", "table", "delivery") }.sortedBy { it.id }
    val ready = orders
        .filter { it.status == "ready" && it.channel in setOf("counter", "pickup", "table", "delivery") }
        .sortedWith(compareByDescending<Order> { focusOrderId != null && it.id == focusOrderId }.thenBy { it.id })
    var assigning by remember { mutableStateOf<Order?>(null) }
    var routing by remember { mutableStateOf<UnassignedUnitDelivery?>(null) }

    LaunchedEffect(orderState.changedVersion) {
        if (orderState.changedVersion > 0) onRefresh()
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text("Saída de pedidos", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                        Text("Aceite, direcione e libere o que precisa sair agora.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    OutlinedButton(onClick = onRefresh, modifier = Modifier.heightIn(min = EventMenuUi.TouchTarget)) {
                        Icon(Icons.Default.Refresh, contentDescription = null)
                        Spacer(Modifier.width(7.dp))
                        Text("Atualizar")
                    }
                }
                DispatchOverview(unassignedUnitOrders.size, pending.size, ready.size)
                orderState.error?.let { Text("Não foi possível concluir a última ação. Tente novamente.", color = MaterialTheme.colorScheme.error) }
                orderState.message?.let { Text(it, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold) }
            }
        }

        if (canRouteUnit && unassignedUnitOrders.isNotEmpty()) {
            item { DispatchSectionTitle("Encaminhar para unidade", "Defina onde cada pedido será preparado.", unassignedUnitOrders.size) }
            items(unassignedUnitOrders, key = { "unit-${it.id}" }) { order ->
                Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        DispatchHeader(order.id, order.totalCents, "Aguardando unidade")
                        useful(order.customerName)?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        useful(order.deliveryAddress)?.let { DispatchAddress(it) }
                        useful(order.customerPhone)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        DispatchPaymentPill(order.paymentStatus)
                        Button(onClick = { routing = order }, enabled = units.isNotEmpty(), modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Storefront, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text(if (units.isEmpty()) "Nenhuma unidade disponível" else "Escolher unidade")
                        }
                    }
                }
            }
        }

        if (pending.isNotEmpty()) {
            item { DispatchSectionTitle("Novos pedidos", "Confira e aceite antes de enviar para produção.", pending.size) }
            items(pending, key = { "pending-${it.id}" }) { order ->
                Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        DispatchHeader(order.id, order.totalCents, dispatchChannel(order))
                        useful(order.customerName)?.takeIf { !it.equals("Consumidor", true) }?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        if (order.fromEventMenuDelivery) DispatchOriginPill()
                        useful(order.deliveryAddress)?.let { DispatchAddress(it) }
                        DispatchPaymentPill(order.paymentStatus)
                        OutlinedButton(onClick = { orderViewModel.open(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Ver pedido") }
                        Button(onClick = { orderViewModel.accept(order.id) }, enabled = !orderState.loading, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Check, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Aceitar pedido")
                        }
                    }
                }
            }
        }

        if (ready.isNotEmpty()) {
            item { DispatchSectionTitle("Prontos para sair", "Priorize o pedido destacado e libere conforme o canal.", ready.size) }
        }
        items(ready, key = { it.id }) { order ->
            val focused = focusOrderId == order.id
            Card(
                Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = if (focused) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                    if (focused) {
                        Surface(color = MaterialTheme.colorScheme.primary, shape = MaterialTheme.shapes.small) {
                            Text("Pedido localizado pelo QR", modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold)
                        }
                    }
                    DispatchHeader(order.id, order.totalCents, dispatchChannel(order))
                    useful(order.tableName)?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                    useful(order.customerName)?.takeIf { !it.equals("Consumidor", true) }?.let { Text(it) }
                    if (order.fromEventMenuDelivery) DispatchOriginPill()
                    DispatchPaymentPill(order.paymentStatus)
                    OutlinedButton(onClick = { orderViewModel.open(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Ver pedido") }

                    when (order.channel) {
                        "table" -> Button(onClick = { onDispatch(order) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Restaurant, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Marcar como servido")
                        }
                        "counter", "pickup" -> {
                            Button(onClick = { onDispatch(order) }, enabled = order.paymentStatus == "paid", modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                                Icon(Icons.Default.DoneAll, contentDescription = null)
                                Spacer(Modifier.width(8.dp))
                                Text("Entregar ao cliente")
                            }
                            if (order.paymentStatus != "paid") {
                                Surface(color = MaterialTheme.colorScheme.tertiaryContainer, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth()) {
                                    Text("Aguardando recebimento no caixa.", modifier = Modifier.padding(10.dp), color = MaterialTheme.colorScheme.onTertiaryContainer)
                                }
                            }
                        }
                        "delivery" -> {
                            val deliveryName = useful(order.deliveryName)
                            if (deliveryName != null) {
                                Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth()) {
                                    Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Icon(Icons.Default.DeliveryDining, contentDescription = null, tint = MaterialTheme.colorScheme.onSecondaryContainer)
                                        Text("Entregador: $deliveryName", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onSecondaryContainer)
                                    }
                                }
                                if (canAssignDelivery) OutlinedButton(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) { Text("Trocar entregador") }
                            } else if (canAssignDelivery) {
                                Button(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                                    Icon(Icons.Default.PersonAdd, contentDescription = null)
                                    Spacer(Modifier.width(8.dp))
                                    Text("Escolher entregador")
                                }
                            } else {
                                Text("Aguardando atribuição de entregador.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }

        if (ready.isEmpty() && pending.isEmpty() && unassignedUnitOrders.isEmpty()) {
            item {
                Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                    Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Icon(Icons.Default.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.secondary)
                        Text("Nenhum pedido aguardando ação", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text("Novos pedidos e pedidos prontos aparecerão aqui.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }

    orderState.detail?.let { detail ->
        OrderDetailDialog(
            detail = detail,
            canAccept = detail.status == "pending",
            onAccept = orderViewModel::accept,
            onDismiss = orderViewModel::close,
        )
    }

    routing?.let { order ->
        AlertDialog(
            onDismissRequest = { routing = null },
            title = { Text("Escolher unidade · Pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(useful(order.deliveryAddress) ?: "Endereço não informado")
                    units.forEach { unit ->
                        OutlinedButton(
                            onClick = { routing = null; onAssignUnit(order.id, unit.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Column {
                                Text(unit.name, fontWeight = FontWeight.Bold)
                                useful(unit.address)?.let { Text(it) }
                            }
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { routing = null }) { Text("Fechar") } },
        )
    }

    assigning?.let { order ->
        val available = deliveryUsers.filter { it.onShift }
        AlertDialog(
            onDismissRequest = { assigning = null },
            title = { Text("Entregador · Pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { assigning = null; onScanDelivery(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                        Icon(Icons.Default.QrCodeScanner, contentDescription = null)
                        Spacer(Modifier.width(8.dp))
                        Text("Ler QR do entregador")
                    }
                    if (available.isEmpty()) Text("Nenhum entregador disponível agora.")
                    available.forEach { user ->
                        OutlinedButton(
                            onClick = { assigning = null; onAssignDelivery(order.id, user.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Column {
                                Text(user.name, fontWeight = FontWeight.Bold)
                                useful(user.startedAt)?.let { Text("Em turno desde ${dispatchFriendlyTime(it)}", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            }
                        }
                    }
                    val offline = deliveryUsers.count { !it.onShift }
                    if (offline > 0) Text("$offline entregador(es) estão fora de turno.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { assigning = null }) { Text("Fechar") } },
        )
    }
}

@Composable
private fun DispatchOverview(unassigned: Int, pending: Int, ready: Int) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
        DispatchMetric("Sem unidade", unassigned, Modifier.weight(1f), warn = unassigned > 0)
        DispatchMetric("Novos", pending, Modifier.weight(1f))
        DispatchMetric("Prontos", ready, Modifier.weight(1f), success = ready > 0)
    }
}

@Composable
private fun DispatchMetric(label: String, value: Int, modifier: Modifier = Modifier, warn: Boolean = false, success: Boolean = false) {
    val background = when {
        warn -> MaterialTheme.colorScheme.tertiaryContainer
        success -> MaterialTheme.colorScheme.secondaryContainer
        else -> MaterialTheme.colorScheme.surface
    }
    val content = when {
        warn -> MaterialTheme.colorScheme.onTertiaryContainer
        success -> MaterialTheme.colorScheme.onSecondaryContainer
        else -> MaterialTheme.colorScheme.onSurface
    }
    Surface(modifier = modifier, color = background, shape = MaterialTheme.shapes.medium) {
        Column(Modifier.padding(horizontal = 8.dp, vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, color = content)
            Text(label, style = MaterialTheme.typography.bodyMedium, color = content)
        }
    }
}

@Composable
private fun DispatchSectionTitle(title: String, subtitle: String, count: Int) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
        }
        Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.small) {
            Text(count.toString(), modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun DispatchHeader(id: Int, totalCents: Int, channel: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text("Pedido #$id", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text(channel, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
        }
        Text(dispatchMoney(totalCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
    }
}

@Composable
private fun DispatchAddress(address: String) {
    Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
            Icon(Icons.Default.LocationOn, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Text(address, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun DispatchOriginPill() {
    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.small) {
        Text("DELYVRE", modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onPrimaryContainer, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun DispatchPaymentPill(status: String) {
    val paid = status == "paid"
    Surface(
        color = if (paid) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.tertiaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            if (paid) "Pago" else if (status == "pending") "Pagamento em processamento" else "A receber",
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (paid) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onTertiaryContainer,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

private fun dispatchChannel(order: Order): String = when (order.channel) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "delivery" -> "Entrega"
    else -> "Pedido"
}

private fun useful(value: String?): String? {
    val clean = value?.trim().orEmpty()
    return clean.takeIf { it.isNotBlank() && !it.equals("null", true) && !it.equals("undefined", true) }
}

private fun dispatchFriendlyTime(value: String): String {
    val clean = value.trim().replace('T', ' ')
    val time = clean.substringAfter(' ', "").take(5)
    return time.ifBlank { clean.take(5) }
}

private fun dispatchMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
