package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Saída de pedidos", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Aceite, organize e libere os pedidos prontos.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            orderState.error?.let { Text("Não foi possível concluir a última ação. Tente novamente.", color = MaterialTheme.colorScheme.error) }
            orderState.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        }

        if (canRouteUnit && unassignedUnitOrders.isNotEmpty()) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Pedidos aguardando unidade", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("${unassignedUnitOrders.size} pedido(s) precisam ser encaminhados para uma unidade.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
            items(unassignedUnitOrders, key = { "unit-${it.id}" }) { order ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${order.id}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(dispatchMoney(order.totalCents), fontWeight = FontWeight.Black)
                        }
                        useful(order.customerName)?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        useful(order.deliveryAddress)?.let { Text(it) }
                        useful(order.customerPhone)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        DispatchPaymentPill(order.paymentStatus)
                        Button(onClick = { routing = order }, enabled = units.isNotEmpty(), modifier = Modifier.fillMaxWidth()) {
                            Text(if (units.isEmpty()) "Nenhuma unidade disponível" else "Escolher unidade")
                        }
                    }
                }
            }
        }

        if (pending.isNotEmpty()) {
            item { Text("Novos pedidos", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            items(pending, key = { "pending-${it.id}" }) { order ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Column {
                                Text("Pedido #${order.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                                Text(dispatchChannel(order), color = MaterialTheme.colorScheme.onSurfaceVariant)
                                useful(order.customerName)?.takeIf { !it.equals("Consumidor", true) }?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                            }
                            Text(dispatchMoney(order.totalCents), fontWeight = FontWeight.Black)
                        }
                        if (order.fromEventMenuDelivery) DispatchOriginPill()
                        useful(order.deliveryAddress)?.let { Text(it) }
                        DispatchPaymentPill(order.paymentStatus)
                        OutlinedButton(onClick = { orderViewModel.open(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("Ver pedido") }
                        Button(onClick = { orderViewModel.accept(order.id) }, enabled = !orderState.loading, modifier = Modifier.fillMaxWidth()) { Text("Aceitar pedido") }
                    }
                }
            }
        }

        if (ready.isNotEmpty()) item { Text("Prontos para sair", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
        items(ready, key = { it.id }) { order ->
            val focused = focusOrderId == order.id
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (focused) Text("Pedido localizado pelo QR", color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column {
                            Text("Pedido #${order.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            Text(dispatchChannel(order), color = MaterialTheme.colorScheme.onSurfaceVariant)
                            useful(order.tableName)?.let { Text(it) }
                            useful(order.customerName)?.takeIf { !it.equals("Consumidor", true) }?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        }
                        Text(dispatchMoney(order.totalCents), fontWeight = FontWeight.Black)
                    }
                    if (order.fromEventMenuDelivery) DispatchOriginPill()
                    DispatchPaymentPill(order.paymentStatus)
                    OutlinedButton(onClick = { orderViewModel.open(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("Ver pedido") }

                    when (order.channel) {
                        "table" -> Button(onClick = { onDispatch(order) }, modifier = Modifier.fillMaxWidth()) { Text("Marcar como servido") }
                        "counter", "pickup" -> {
                            Button(onClick = { onDispatch(order) }, enabled = order.paymentStatus == "paid", modifier = Modifier.fillMaxWidth()) { Text("Entregar ao cliente") }
                            if (order.paymentStatus != "paid") Text("Aguardando recebimento no caixa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        "delivery" -> {
                            if (useful(order.deliveryName) != null) {
                                Text("Entregador: ${useful(order.deliveryName)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                if (canAssignDelivery) OutlinedButton(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth()) { Text("Trocar entregador") }
                            } else if (canAssignDelivery) {
                                Button(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth()) { Text("Escolher entregador") }
                            } else {
                                Text("Aguardando atribuição de entregador.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }

        if (ready.isEmpty() && pending.isEmpty() && unassignedUnitOrders.isEmpty()) item { Text("Nenhum pedido aguardando ação.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") } }
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
                    Button(
                        onClick = { assigning = null; onScanDelivery(order.id) },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Ler QR do entregador") }
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
private fun DispatchOriginPill() {
    Surface(
        color = MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            "EventMenu Delivery",
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun DispatchPaymentPill(status: String) {
    val paid = status == "paid"
    Surface(
        color = if (paid) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            if (paid) "Pago" else if (status == "pending") "Pagamento em processamento" else "A receber",
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (paid) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

private fun dispatchChannel(order: Order): String = when (order.channel) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "delivery" -> "Delivery"
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