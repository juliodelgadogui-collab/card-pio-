package br.com.eventmenu.go.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.foundation.Image
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
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.CancellationViewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.OrderOperationsViewModel
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.PixCharge
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
    val deliveries = orders.filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }

    LaunchedEffect(deliveries.map { it.id }) { onRefreshProgress() }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Minhas entregas", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Retirada, rota, chegada e pagamento são confirmados pela API EventMenu.")
        }
        items(deliveries, key = { it.id }) { order ->
            val step = progress[order.id]
            val pickedUp = step?.pickedUp == true
            val routeStarted = step?.routeStarted == true || order.status == "out_for_delivery"
            val arrived = step?.arrived == true

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("#${order.id} · ${order.customerName}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text(moneyDelivery(order.totalCents), fontWeight = FontWeight.Black)
                    }
                    if (order.deliveryAddress.isNotBlank()) Text(order.deliveryAddress)
                    if (order.customerPhone.isNotBlank()) {
                        Text("Telefone: ${order.customerPhone}")
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { openDialer(context, order.customerPhone) }, modifier = Modifier.weight(1f)) { Text("📞 LIGAR") }
                            OutlinedButton(onClick = { openMessage(context, order.customerPhone, order.id) }, modifier = Modifier.weight(1f)) { Text("💬 MENSAGEM") }
                        }
                    }

                    OutlinedButton(onClick = { detailViewModel.open(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR PEDIDO") }
                    if (order.paymentStatus != "paid") {
                        OutlinedButton(onClick = { cancelOrder = order }, modifier = Modifier.fillMaxWidth()) { Text("SOLICITAR CANCELAMENTO") }
                    }
                    DeliveryStepIndicator(pickedUp, routeStarted, arrived)
                    Text(if (order.paymentStatus == "paid") "✅ Pagamento confirmado" else "🔴 Pagamento pendente")

                    if (order.status == "ready" && !pickedUp) {
                        Button(onClick = { onPickup(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("RETIRAR PEDIDO") }
                    }
                    if (order.status == "ready" && pickedUp) {
                        Button(onClick = { onStartRoute(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("INICIAR ROTA") }
                    }

                    if (order.status == "out_for_delivery") {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { openGoogleMaps(context, order.deliveryAddress) }, modifier = Modifier.weight(1f)) { Text("MAPS") }
                            OutlinedButton(onClick = { openWaze(context, order.deliveryAddress) }, modifier = Modifier.weight(1f)) { Text("WAZE") }
                        }

                        if (!arrived) {
                            Button(onClick = { onArrive(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("CHEGUEI") }
                            Text("PIX, cartão NFC e dinheiro serão liberados somente depois que o servidor registrar sua chegada.")
                        } else if (order.paymentStatus != "paid") {
                            Text("✅ Chegada confirmada · Como o cliente deseja pagar?", fontWeight = FontWeight.Bold)
                            Button(onClick = { pixOrder = order }, modifier = Modifier.fillMaxWidth()) { Text("PIX") }
                            Button(onClick = { onNfc(order.id) }, modifier = Modifier.fillMaxWidth()) { Text("CARTÃO NFC") }
                            OutlinedButton(onClick = { cashOrder = order }, modifier = Modifier.fillMaxWidth()) { Text("DINHEIRO") }
                        } else {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                OutlinedButton(onClick = { onReceipt(order.id) }, modifier = Modifier.weight(1f)) { Text("ENVIAR") }
                                OutlinedButton(onClick = { onPrintReceipt(order.id) }, modifier = Modifier.weight(1f)) { Text("IMPRIMIR") }
                            }
                            Button(onClick = { onComplete(order.id) }, enabled = arrived, modifier = Modifier.fillMaxWidth()) { Text("CONCLUIR ENTREGA") }
                        }
                    }
                }
            }
        }
        if (deliveries.isEmpty()) item { Text("Nenhuma entrega atribuída agora.") }
        cancellationState.message?.let { msg -> item { Text(msg, fontWeight = FontWeight.Bold) } }
        cancellationState.error?.let { error -> item { Text(error) } }
        item { OutlinedButton(onClick = onRefreshProgress, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR ETAPAS") } }
    }

    detailState.detail?.let { detail ->
        OrderDetailDialog(detail = detail, canAccept = false, onAccept = {}, onDismiss = detailViewModel::close)
    }
    detailState.error?.let { error ->
        AlertDialog(
            onDismissRequest = detailViewModel::clearFeedback,
            title = { Text("Não foi possível abrir o pedido") },
            text = { Text(error) },
            confirmButton = { TextButton(onClick = detailViewModel::clearFeedback) { Text("FECHAR") } },
        )
    }
    cancelOrder?.let { order ->
        var reason by remember(order.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { cancelOrder = null },
            title = { Text("Solicitar cancelamento · Pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("O pedido não será cancelado agora. O Gerente/ADM precisará autorizar no servidor.")
                    OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Motivo *") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { cancelOrder = null; cancellationViewModel.request(order.id, reason) }, enabled = reason.isNotBlank()) { Text("ENVIAR SOLICITAÇÃO") } },
            dismissButton = { TextButton(onClick = { cancelOrder = null }) { Text("VOLTAR") } },
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
private fun DeliveryStepIndicator(pickedUp: Boolean, routeStarted: Boolean, arrived: Boolean) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text("Etapas", fontWeight = FontWeight.Bold)
            Text((if (pickedUp) "✅" else "○") + " Pedido retirado")
            Text((if (routeStarted) "✅" else "○") + " Rota iniciada")
            Text((if (arrived) "✅" else "○") + " Cheguei ao cliente")
        }
    }
}

@Composable
private fun CashReceiveDialog(order: Order, onDismiss: () -> Unit, onConfirm: (Int) -> Unit) {
    var received by remember { mutableStateOf("") }
    val receivedCents=((received.replace(',','.').toDoubleOrNull()?:0.0)*100).toInt()
    val change=(receivedCents-order.totalCents).coerceAtLeast(0)
    AlertDialog(
        onDismissRequest=onDismiss,
        title={Text("Dinheiro · Pedido #${order.id}")},
        text={Column(verticalArrangement=Arrangement.spacedBy(10.dp)){
            Text("Total: ${moneyDelivery(order.totalCents)}",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
            OutlinedTextField(received,{received=it},label={Text("Cliente entregou (R$)")},singleLine=true,modifier=Modifier.fillMaxWidth())
            Text("Troco: ${moneyDelivery(change)}",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)
            Text("O valor do pedido será lançado no turno do entregador. O troco não é receita.")
        }},
        confirmButton={Button(onClick={onConfirm(receivedCents)},enabled=receivedCents>=order.totalCents){Text("RECEBI O DINHEIRO")}},
        dismissButton={TextButton(onClick=onDismiss){Text("CANCELAR")}},
    )
}

@Composable
private fun TaxIdDialog(orderId: Int, amountCents: Int, onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var taxId by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PIX · Pedido #$orderId") },
        text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { Text("Total ${moneyDelivery(amountCents)}"); Text("O PagBank exige CPF/CNPJ do pagador para emitir este QR PIX."); OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF ou CNPJ") }, singleLine = true) } },
        confirmButton = { Button(onClick = { onConfirm(taxId) }, enabled = taxId.length in setOf(11,14)) { Text("GERAR PIX") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("CANCELAR") } },
    )
}

@Composable
private fun PixWaitingDialog(charge: PixCharge, onPoll: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val qr = remember(charge.copyPaste) { qrBitmap(charge.copyPaste) }
    LaunchedEffect(charge.paymentId) { while (true) { delay(2500); onPoll() } }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PIX · ${moneyDelivery(charge.amountCents)}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                qr?.let { Image(it.asImageBitmap(), contentDescription = "QR Code PIX", modifier = Modifier.fillMaxWidth()) }
                Text("⏳ Aguardando confirmação do PagBank…")
                if (charge.expiresAt.isNotBlank()) Text("Validade: ${charge.expiresAt}")
                OutlinedButton(onClick = { copy(context, charge.copyPaste) }, modifier = Modifier.fillMaxWidth()) { Text("COPIAR CÓDIGO PIX") }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )
}

internal fun qrBitmap(text: String): Bitmap? = runCatching {
    val size = 720; val matrix = MultiFormatWriter().encode(text, BarcodeFormat.QR_CODE, size, size); val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
    for (x in 0 until size) for (y in 0 until size) bitmap.setPixel(x, y, if (matrix[x,y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    bitmap
}.getOrNull()

private fun copy(context: Context, text: String) { (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("PIX EventMenu", text)) }
private fun openDialer(context: Context, phone: String) { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(phone)))) }
private fun openMessage(context: Context, phone: String, orderId: Int) { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:" + Uri.encode(phone))).putExtra("sms_body", "Olá! Estou chegando com seu pedido EventMenu #$orderId.")) }
private fun openGoogleMaps(context: Context, address: String) { val uri=Uri.parse("google.navigation:q="+Uri.encode(address)); val intent=Intent(Intent.ACTION_VIEW,uri).setPackage("com.google.android.apps.maps"); runCatching{context.startActivity(intent)}.onFailure{context.startActivity(Intent(Intent.ACTION_VIEW,Uri.parse("geo:0,0?q="+Uri.encode(address))))} }
private fun openWaze(context: Context, address: String) { val uri=Uri.parse("https://waze.com/ul?q="+Uri.encode(address)+"&navigate=yes"); context.startActivity(Intent(Intent.ACTION_VIEW,uri)) }
internal fun moneyDelivery(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
