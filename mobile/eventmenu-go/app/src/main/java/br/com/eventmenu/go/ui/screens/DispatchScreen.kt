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
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
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
    val ready = orders
        .filter { it.status == "ready" && it.channel in setOf("counter", "pickup", "table", "delivery") }
        .sortedWith(compareByDescending<Order> { focusOrderId != null && it.id == focusOrderId }.thenBy { it.id })
    var assigning by remember { mutableStateOf<Order?>(null) }
    var routing by remember { mutableStateOf<UnassignedUnitDelivery?>(null) }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Balcão · Pedidos prontos", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Esta área só despacha pedidos. Pagamentos continuam protegidos pelo Caixa/Pay.")
        }

        if (canRouteUnit && unassignedUnitOrders.isNotEmpty()) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("⚠️ Delivery sem unidade", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("${unassignedUnitOrders.size} pedido(s) público(s) aguardam direcionamento para uma filial antes de entrar na operação.")
                    }
                }
            }
            items(unassignedUnitOrders, key = { "unit-${it.id}" }) { order ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("Pedido #${order.id}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                            Text(dispatchMoney(order.totalCents), fontWeight = FontWeight.Black)
                        }
                        if (order.customerName.isNotBlank()) Text(order.customerName)
                        if (order.deliveryAddress.isNotBlank()) Text(order.deliveryAddress)
                        if (order.customerPhone.isNotBlank()) Text(order.customerPhone)
                        Text("Pagamento: ${if (order.paymentStatus == "paid") "confirmado" else order.paymentStatus}")
                        Button(onClick = { routing = order }, enabled = units.isNotEmpty(), modifier = Modifier.fillMaxWidth()) {
                            Text(if (units.isEmpty()) "SEM UNIDADE DISPONÍVEL" else "ESCOLHER UNIDADE")
                        }
                    }
                }
            }
        }

        items(ready, key = { it.id }) { order ->
            val focused = focusOrderId == order.id
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (focused) Text("📷 LIDO NO QR", color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Black)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column {
                            Text("#${order.id} · ${dispatchChannel(order)}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            if (order.tableName.isNotBlank()) Text(order.tableName)
                            if (order.customerName.isNotBlank() && order.customerName != "Consumidor") Text(order.customerName)
                        }
                        Text(dispatchMoney(order.totalCents), fontWeight = FontWeight.Black)
                    }
                    Text(if (order.paymentStatus == "paid") "✅ Pagamento confirmado" else "⏳ Pagamento ainda não concluído")

                    when (order.channel) {
                        "table" -> Button(onClick = { onDispatch(order) }, modifier = Modifier.fillMaxWidth()) { Text("MARCAR COMO SERVIDO") }
                        "counter", "pickup" -> {
                            Button(onClick = { onDispatch(order) }, enabled = order.paymentStatus == "paid", modifier = Modifier.fillMaxWidth()) { Text("ENTREGAR AO CLIENTE") }
                            if (order.paymentStatus != "paid") Text("O Balcão não pode confirmar pagamento. Finalize o recebimento no Caixa/Pay.")
                        }
                        "delivery" -> {
                            if (order.deliveryName.isNotBlank()) {
                                Text("🛵 Entregador: ${order.deliveryName}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                if (canAssignDelivery) OutlinedButton(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth()) { Text("TROCAR ENTREGADOR") }
                            } else if (canAssignDelivery) {
                                Button(onClick = { assigning = order }, modifier = Modifier.fillMaxWidth()) { Text("ESCOLHER ENTREGADOR") }
                            } else {
                                Text("Aguardando um operador autorizado atribuir o entregador.")
                            }
                            Text("Depois da atribuição, o pedido aparece no app do entregador. Só ele poderá retirar e iniciar a rota.")
                        }
                    }
                }
            }
        }

        if (ready.isEmpty() && unassignedUnitOrders.isEmpty()) item { Text("Nenhum pedido aguardando ação do balcão.") }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR FILA") } }
    }

    routing?.let { order ->
        AlertDialog(
            onDismissRequest = { routing = null },
            title = { Text("Direcionar pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(order.deliveryAddress.ifBlank { "Endereço não informado" })
                    Text("Escolha a unidade que assumirá este Delivery:")
                    units.forEach { unit ->
                        OutlinedButton(
                            onClick = { routing = null; onAssignUnit(order.id, unit.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Column {
                                Text(unit.name, fontWeight = FontWeight.Bold)
                                if (unit.address.isNotBlank()) Text(unit.address)
                            }
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { routing = null }) { Text("FECHAR") } },
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
                    ) { Text("📷 LER QR DO ENTREGADOR") }
                    Text("ou escolha na lista:")
                    if (available.isEmpty()) Text("Nenhum entregador está com turno de Delivery aberto agora.")
                    available.forEach { user ->
                        OutlinedButton(
                            onClick = { assigning = null; onAssignDelivery(order.id, user.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Column {
                                Text(user.name, fontWeight = FontWeight.Bold)
                                if (user.startedAt.isNotBlank()) Text("Turno desde ${user.startedAt}")
                            }
                        }
                    }
                    val offline = deliveryUsers.count { !it.onShift }
                    if (offline > 0) Text("$offline entregador(es) ativo(s) estão sem turno aberto e não podem receber pedido.")
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { assigning = null }) { Text("FECHAR") } },
        )
    }
}

private fun dispatchChannel(order: Order): String = when (order.channel) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "delivery" -> "Delivery"
    else -> order.channel
}

private fun dispatchMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
