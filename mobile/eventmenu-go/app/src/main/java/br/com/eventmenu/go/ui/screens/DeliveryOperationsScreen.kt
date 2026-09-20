package br.com.eventmenu.go.ui.screens

import android.Manifest
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
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
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.CancellationViewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.OperationalText
import br.com.eventmenu.go.OrderOperationsViewModel
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.PixCharge
import br.com.eventmenu.go.delivery.DeliveryLocationService
import br.com.eventmenu.go.ui.theme.EventMenuUi
import com.google.zxing.BarcodeFormat
import com.google.zxing.MultiFormatWriter
import kotlinx.coroutines.delay

@Composable
fun DeliveryOperationsScreen(
    orders: List<Order>,
    progress: Map<Int, DeliveryProgress>,
    pixCharge: PixCharge?,
    onRefreshProgress: () -> Unit,
    onPickup: (Int) -> Unit,
    onStartRoute: (Int) -> Unit,
    onArrive: (Int) -> Unit,
    onComplete: (Int) -> Unit,
    onPix: (Int, String) -> Unit,
    onNfc: (Int) -> Unit,
    onCash: (Int, Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onPollPix: () -> Unit,
    onDismissPix: () -> Unit,
) {
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val detailViewModel: OrderOperationsViewModel = viewModel(factory = OrderOperationsViewModel.Factory(app.orderOperationsRepository))
    val detailState by detailViewModel.state.collectAsState()
    val cancellationViewModel: CancellationViewModel = viewModel(factory = CancellationViewModel.Factory(app.cancellationRepository))
    val cancellationState by cancellationViewModel.state.collectAsState()
    var pixOrder by remember { mutableStateOf<Order?>(null) }
    var cashOrder by remember { mutableStateOf<Order?>(null) }
    var cancelOrder by remember { mutableStateOf<Order?>(null) }
    var pendingRouteOrderId by remember { mutableStateOf<Int?>(null) }
    var locationDenied by remember { mutableStateOf(false) }
    val deliveries = orders
        .filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }
        .sortedWith(compareBy<Order> { deliveryPriority(it, progress[it.id]) }.thenBy { it.id })
    val activeTrackingOrderId = deliveries.firstOrNull { order ->
        order.status == "out_for_delivery" && progress[order.id]?.arrived != true
    }?.id
    val toPickup = deliveries.count { it.status == "ready" && progress[it.id]?.pickedUp != true }
    val inRoute = deliveries.count { it.status == "out_for_delivery" && progress[it.id]?.arrived != true }
    val toReceive = deliveries.count { it.status == "out_for_delivery" && progress[it.id]?.arrived == true && it.paymentStatus != "paid" }

    val locationPermissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        val orderId = pendingRouteOrderId
        pendingRouteOrderId = null
        val granted = result[Manifest.permission.ACCESS_FINE_LOCATION] == true || result[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        locationDenied = !granted
        if (orderId != null) onStartRoute(orderId)
    }

    LaunchedEffect(deliveries.map { it.id }) { onRefreshProgress() }
    LaunchedEffect(activeTrackingOrderId) {
        val orderId = activeTrackingOrderId
        if (orderId != null && DeliveryLocationService.hasLocationPermission(context)) {
            DeliveryLocationService.start(context, orderId)
            locationDenied = false
        } else if (orderId == null) {
            DeliveryLocationService.stop(context)
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
                Text("Minhas entregas", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                Text("A tela prioriza automaticamente o que precisa da sua ação agora.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                DeliveryOverviewCard(deliveries.size, toPickup, inRoute, toReceive)
                when {
                    activeTrackingOrderId != null && DeliveryLocationService.hasLocationPermission(context) -> DeliveryTrackingBanner(activeTrackingOrderId, true)
                    locationDenied -> DeliveryTrackingBanner(null, false)
                }
            }
        }

        items(deliveries, key = { it.id }) { order ->
            val step = progress[order.id]
            val pickedUp = step?.pickedUp == true
            val routeStarted = step?.routeStarted == true || order.status == "out_for_delivery"
            val arrived = step?.arrived == true
            val customer = OperationalText.useful(order.customerName)?.takeIf { !it.equals("Consumidor", true) }
            val address = OperationalText.useful(order.deliveryAddress)
            val phone = OperationalText.useful(order.customerPhone)
            val nextAction = deliveryNextAction(order, pickedUp, routeStarted, arrived)
            val stage = deliveryStage(order, pickedUp, routeStarted, arrived)

            Card(
                modifier = Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text("Pedido #${order.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
                            customer?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                        }
                        Text(moneyDelivery(order.totalCents), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    }

                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        DeliveryStagePill(stage, urgent = arrived && order.paymentStatus != "paid")
                        DeliveryPaymentPill(order.paymentStatus)
                    }
                    if (order.fromEventMenuDelivery) DeliveryOriginPill()

                    address?.let {
                        Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium, modifier = Modifier.fillMaxWidth()) {
                            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(9.dp), verticalAlignment = Alignment.Top) {
                                Icon(Icons.Default.LocationOn, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                                Text(it, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                            }
                        }
                    }

                    if (phone != null) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { openDialer(context, phone) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) {
                                Icon(Icons.Default.Phone, contentDescription = null)
                                Spacer(Modifier.width(7.dp))
                                Text("Ligar")
                            }
                            OutlinedButton(onClick = { openMessage(context, phone, order.id) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) {
                                Icon(Icons.Default.ChatBubbleOutline, contentDescription = null)
                                Spacer(Modifier.width(7.dp))
                                Text("Mensagem")
                            }
                        }
                    }

                    DeliveryStepIndicator(pickedUp, routeStarted, arrived)

                    Surface(
                        color = MaterialTheme.colorScheme.primaryContainer,
                        shape = MaterialTheme.shapes.medium,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.Bolt, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text("Faça agora", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onPrimaryContainer)
                                Text(nextAction, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.onPrimaryContainer)
                            }
                        }
                    }

                    if (order.status == "ready" && !pickedUp) {
                        Button(onClick = { onPickup(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.Inventory2, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Retirar pedido")
                        }
                    }
                    if (order.status == "ready" && pickedUp) {
                        Button(
                            onClick = {
                                if (DeliveryLocationService.hasLocationPermission(context)) {
                                    onStartRoute(order.id)
                                } else {
                                    pendingRouteOrderId = order.id
                                    locationPermissionLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                                }
                            },
                            modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
                        ) {
                            Icon(Icons.Default.Navigation, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Iniciar rota")
                        }
                    }

                    if (order.status == "out_for_delivery") {
                        if (!arrived && address != null) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                OutlinedButton(onClick = { openGoogleMaps(context, address) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Google Maps") }
                                OutlinedButton(onClick = { openWaze(context, address) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Waze") }
                            }
                        }

                        if (!arrived) {
                            Button(
                                onClick = { DeliveryLocationService.stop(context); onArrive(order.id) },
                                modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
                            ) {
                                Icon(Icons.Default.Flag, contentDescription = null)
                                Spacer(Modifier.width(8.dp))
                                Text("Cheguei ao cliente")
                            }
                            Text("Ao chegar, o recebimento será liberado somente se ainda houver valor pendente.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                        } else if (order.paymentStatus != "paid") {
                            Text("Receber pagamento", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                            Text("Escolha a forma que o cliente vai usar agora.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Button(onClick = { pixOrder = order }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) { Text("PIX") }
                            Button(onClick = { onNfc(order.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) { Text("Cartão por aproximação") }
                            OutlinedButton(onClick = { cashOrder = order }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) { Text("Dinheiro") }
                        } else {
                            Button(
                                onClick = { DeliveryLocationService.stop(context); onComplete(order.id) },
                                enabled = arrived,
                                modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight),
                            ) {
                                Icon(Icons.Default.CheckCircle, contentDescription = null)
                                Spacer(Modifier.width(8.dp))
                                Text("Confirmar entrega")
                            }
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                OutlinedButton(onClick = { onReceipt(order.id) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Enviar recibo") }
                                OutlinedButton(onClick = { onPrintReceipt(order.id) }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.TouchTarget)) { Text("Imprimir") }
                            }
                        }

                        step?.trackingUrl?.takeIf { it.isNotBlank() }?.let { trackingUrl ->
                            TextButton(onClick = { shareTracking(context, trackingUrl, order.id) }, modifier = Modifier.fillMaxWidth()) {
                                Icon(Icons.Default.Share, contentDescription = null)
                                Spacer(Modifier.width(7.dp))
                                Text("Compartilhar acompanhamento")
                            }
                        }
                    }

                    OutlinedButton(
                        onClick = { detailViewModel.open(order.id) },
                        modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget),
                    ) { Text("Ver detalhes do pedido") }
                    if (order.paymentStatus != "paid") {
                        TextButton(onClick = { cancelOrder = order }, modifier = Modifier.align(Alignment.End)) { Text("Solicitar cancelamento") }
                    }
                }
            }
        }

        if (deliveries.isEmpty()) item {
            Card(Modifier.fillMaxWidth()) {
                Column(
                    Modifier.fillMaxWidth().padding(28.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Icon(Icons.Default.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.secondary, modifier = Modifier.size(36.dp))
                    Text("Tudo em dia por aqui", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Text("Quando uma entrega for atribuída a você, ela aparecerá aqui com a próxima ação destacada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
        cancellationState.message?.let { msg -> item { Text(msg, color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.SemiBold) } }
        cancellationState.error?.let { item { Text("Não foi possível atualizar o cancelamento. Tente novamente.", color = MaterialTheme.colorScheme.error) } }
        item {
            OutlinedButton(onClick = onRefreshProgress, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("Atualizar entregas")
            }
        }
    }

    detailState.detail?.let { detail ->
        OrderDetailDialog(detail = detail, canAccept = false, onAccept = {}, onDismiss = detailViewModel::close)
    }
    detailState.error?.let {
        AlertDialog(
            onDismissRequest = detailViewModel::clearFeedback,
            title = { Text("Não foi possível abrir o pedido") },
            text = { Text("Tente novamente em alguns instantes.") },
            confirmButton = { TextButton(onClick = detailViewModel::clearFeedback) { Text("Fechar") } },
        )
    }
    cancelOrder?.let { order ->
        var reason by remember(order.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { cancelOrder = null },
            title = { Text("Cancelar pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Um responsável precisa aprovar o cancelamento antes que ele seja concluído.")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { cancelOrder = null; cancellationViewModel.request(order.id, reason) }, enabled = reason.isNotBlank()) { Text("Enviar solicitação") } },
            dismissButton = { TextButton(onClick = { cancelOrder = null }) { Text("Voltar") } },
        )
    }
    pixOrder?.let { order ->
        TaxIdDialog(orderId = order.id, amountCents = order.totalCents, onDismiss = { pixOrder = null }, onConfirm = { taxId -> pixOrder = null; onPix(order.id, taxId) })
    }
    cashOrder?.let { order ->
        CashReceiveDialog(order, onDismiss = { cashOrder = null }, onConfirm = { received -> cashOrder = null; onCash(order.id, received) })
    }
    pixCharge?.let { PixWaitingDialog(it, onPollPix, onDismissPix) }
}

@Composable
private fun DeliveryOverviewCard(total: Int, toPickup: Int, inRoute: Int, toReceive: Int) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text("Turno agora", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text("$total ativa(s)", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DeliveryMetric("Retirar", toPickup, Modifier.weight(1f))
                DeliveryMetric("Em rota", inRoute, Modifier.weight(1f))
                DeliveryMetric("Receber", toReceive, Modifier.weight(1f), warn = toReceive > 0)
            }
        }
    }
}

@Composable
private fun DeliveryMetric(label: String, value: Int, modifier: Modifier = Modifier, warn: Boolean = false) {
    Surface(modifier = modifier, color = if (warn) MaterialTheme.colorScheme.tertiaryContainer else MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.small) {
        Column(Modifier.padding(horizontal = 10.dp, vertical = 9.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(value.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, color = if (warn) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSurface)
            Text(label, style = MaterialTheme.typography.bodyMedium, color = if (warn) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DeliveryTrackingBanner(orderId: Int?, active: Boolean) {
    Surface(
        color = if (active) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.errorContainer,
        shape = MaterialTheme.shapes.medium,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(9.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(if (active) Icons.Default.MyLocation else Icons.Default.LocationOff, contentDescription = null, tint = if (active) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onErrorContainer)
            Text(
                if (active) "Localização compartilhada na rota do pedido #$orderId." else "A rota foi iniciada, mas a localização não está liberada. Ative a permissão do aplicativo.",
                modifier = Modifier.weight(1f),
                color = if (active) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onErrorContainer,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

private fun deliveryPriority(order: Order, progress: DeliveryProgress?): Int = when {
    order.status == "out_for_delivery" && progress?.arrived == true && order.paymentStatus != "paid" -> 0
    order.status == "out_for_delivery" && progress?.arrived == true -> 1
    order.status == "out_for_delivery" -> 2
    order.status == "ready" && progress?.pickedUp == true -> 3
    order.status == "ready" -> 4
    else -> 5
}

private fun deliveryStage(order: Order, pickedUp: Boolean, routeStarted: Boolean, arrived: Boolean): String = when {
    order.status == "ready" && !pickedUp -> "Aguardando retirada"
    order.status == "ready" && pickedUp && !routeStarted -> "Pronto para sair"
    order.status == "out_for_delivery" && !arrived -> "Em rota"
    order.status == "out_for_delivery" && arrived && order.paymentStatus != "paid" -> "Receber agora"
    order.status == "out_for_delivery" && arrived -> "Concluir entrega"
    else -> OperationalText.orderStatus(order.status)
}

private fun deliveryNextAction(order: Order, pickedUp: Boolean, routeStarted: Boolean, arrived: Boolean): String = when {
    order.status == "ready" && !pickedUp -> "Retirar o pedido"
    order.status == "ready" && pickedUp && !routeStarted -> "Iniciar a rota"
    order.status == "out_for_delivery" && !arrived -> "Confirmar quando chegar ao cliente"
    order.status == "out_for_delivery" && arrived && order.paymentStatus != "paid" -> "Receber o pagamento"
    order.status == "out_for_delivery" && arrived -> "Confirmar a entrega"
    else -> "Aguardar liberação do pedido"
}

@Composable
private fun DeliveryOriginPill() {
    Surface(
        color = MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            "DELYVRE",
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun DeliveryStagePill(label: String, urgent: Boolean) {
    Surface(
        color = if (urgent) MaterialTheme.colorScheme.tertiaryContainer else MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (urgent) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
            fontWeight = FontWeight.Bold,
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
private fun DeliveryPaymentPill(status: String) {
    val paid = status == "paid"
    Surface(
        color = if (paid) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.tertiaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            (if (paid) "✓ " else "") + OperationalText.paymentStatus(status),
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (paid) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onTertiaryContainer,
            fontWeight = FontWeight.SemiBold,
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
private fun DeliveryStepIndicator(pickedUp: Boolean, routeStarted: Boolean, arrived: Boolean) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text("Etapas da entrega", style = MaterialTheme.typography.labelLarge)
        DeliveryStepRow("1", "Atribuído", done = true, current = !pickedUp)
        DeliveryStepRow("2", "Retirado", done = pickedUp, current = pickedUp && !routeStarted)
        DeliveryStepRow("3", "Em rota", done = routeStarted, current = routeStarted && !arrived)
        DeliveryStepRow("4", "Cheguei", done = arrived, current = arrived)
        DeliveryStepRow("5", "Entregue", done = false, current = false)
    }
}

@Composable
private fun DeliveryStepRow(number: String, label: String, done: Boolean, current: Boolean) {
    Surface(
        color = when {
            done -> MaterialTheme.colorScheme.secondaryContainer
            current -> MaterialTheme.colorScheme.primaryContainer
            else -> MaterialTheme.colorScheme.surfaceVariant
        },
        shape = MaterialTheme.shapes.small,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 11.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("$number · $label", fontWeight = if (done || current) FontWeight.Bold else FontWeight.Medium)
            Text(
                when {
                    done -> "✓"
                    current -> "Agora"
                    else -> "Depois"
                },
                color = when {
                    done -> MaterialTheme.colorScheme.onSecondaryContainer
                    current -> MaterialTheme.colorScheme.onPrimaryContainer
                    else -> MaterialTheme.colorScheme.onSurfaceVariant
                },
                fontWeight = FontWeight.Bold,
            )
        }
    }
}

@Composable
private fun CashReceiveDialog(order: Order, onDismiss: () -> Unit, onConfirm: (Int) -> Unit) {
    var received by remember { mutableStateOf("") }
    val receivedCents = ((received.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
    val change = (receivedCents - order.totalCents).coerceAtLeast(0)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Dinheiro · Pedido #${order.id}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Total: ${moneyDelivery(order.totalCents)}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                OutlinedTextField(received, { received = it }, label = { Text("Valor recebido (R$)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Text("Troco: ${moneyDelivery(change)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            }
        },
        confirmButton = { Button(onClick = { onConfirm(receivedCents) }, enabled = receivedCents >= order.totalCents) { Text("Confirmar recebimento") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } },
    )
}

@Composable
private fun TaxIdDialog(orderId: Int, amountCents: Int, onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var taxId by remember { mutableStateOf("") }
    val valid = taxId.isBlank() || taxId.length in setOf(11, 14)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PIX · Pedido #$orderId") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Total: ${moneyDelivery(amountCents)}")
                Text("O EventMenu usa os dados cadastrados da empresa ou do cliente. Informe CPF/CNPJ somente quando necessário.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF ou CNPJ (opcional)") }, singleLine = true)
                if (taxId.isNotBlank() && !valid) Text("Digite um CPF ou CNPJ completo.", color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = { Button(onClick = { onConfirm(taxId) }, enabled = valid) { Text("Gerar PIX") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } },
    )
}

@Composable
private fun PixWaitingDialog(charge: PixCharge, onPoll: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val qr = remember(charge.copyPaste) { qrBitmap(charge.copyPaste) }
    LaunchedEffect(charge.paymentId) {
        var interval = 3_000L
        while (true) {
            delay(interval)
            onPoll()
            interval = (interval + 1_000L).coerceAtMost(10_000L)
        }
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PIX · ${moneyDelivery(charge.amountCents)}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                qr?.let { Image(it.asImageBitmap(), contentDescription = "QR Code PIX", modifier = Modifier.fillMaxWidth()) }
                Surface(color = MaterialTheme.colorScheme.tertiaryContainer, shape = MaterialTheme.shapes.small) {
                    Text(
                        "Aguardando pagamento",
                        modifier = Modifier.fillMaxWidth().padding(10.dp),
                        color = MaterialTheme.colorScheme.onTertiaryContainer,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
                OperationalText.useful(charge.expiresAt)?.let { Text("Válido até ${deliveryFriendlyDateTime(it)}", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                OutlinedButton(onClick = { copy(context, charge.copyPaste) }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) { Text("Copiar código PIX") }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Fechar") } },
    )
}

internal fun qrBitmap(text: String): Bitmap? = runCatching {
    val size = 720
    val matrix = MultiFormatWriter().encode(text, BarcodeFormat.QR_CODE, size, size)
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
    for (x in 0 until size) for (y in 0 until size) bitmap.setPixel(x, y, if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    bitmap
}.getOrNull()

private fun deliveryFriendlyDateTime(value: String): String {
    val clean = value.trim().replace('T', ' ')
    val date = clean.substringBefore(' ')
    val time = clean.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val formattedDate = if (parts.size == 3) "${parts[2]}/${parts[1]}" else date
    return when {
        formattedDate.isNotBlank() && time.isNotBlank() -> "$formattedDate às $time"
        time.isNotBlank() -> time
        else -> formattedDate
    }
}

private fun copy(context: Context, text: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager)
        .setPrimaryClip(ClipData.newPlainText("PIX pedido", text))
}

private fun shareTracking(context: Context, url: String, orderId: Int) {
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_SUBJECT, "Acompanhe o pedido #$orderId")
        putExtra(Intent.EXTRA_TEXT, "Acompanhe sua entrega em tempo real:\n$url")
    }
    context.startActivity(Intent.createChooser(intent, "Compartilhar acompanhamento"))
}

private fun openDialer(context: Context, phone: String) {
    context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(phone))))
}

private fun openMessage(context: Context, phone: String, orderId: Int) {
    context.startActivity(
        Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:" + Uri.encode(phone)))
            .putExtra("sms_body", "Olá! Estou chegando com seu pedido #$orderId.")
    )
}

private fun openGoogleMaps(context: Context, address: String) {
    val uri = Uri.parse("google.navigation:q=" + Uri.encode(address))
    val intent = Intent(Intent.ACTION_VIEW, uri).setPackage("com.google.android.apps.maps")
    runCatching { context.startActivity(intent) }
        .onFailure { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:0,0?q=" + Uri.encode(address)))) }
}

private fun openWaze(context: Context, address: String) {
    val uri = Uri.parse("https://waze.com/ul?q=" + Uri.encode(address) + "&navigate=yes")
    context.startActivity(Intent(Intent.ACTION_VIEW, uri))
}

internal fun moneyDelivery(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')