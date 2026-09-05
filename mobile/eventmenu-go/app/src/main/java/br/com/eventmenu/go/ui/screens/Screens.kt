package br.com.eventmenu.go.ui.screens

import android.os.Build
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.QrResult

private fun money(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
private fun roleLabel(role: String) = when (role) {
    "delivery" -> "Entregador"
    "cashier" -> "Caixa"
    "attendant" -> "Balconista"
    "kitchen" -> "Cozinha"
    "waiter" -> "Garçom"
    "manager" -> "Gerente"
    "admin" -> "Administrador"
    "promoter" -> "Promotor"
    else -> role.replaceFirstChar { it.uppercase() }
}

@Composable
fun LoginScreen(state: GoState, onLogin: (String, String, String) -> Unit, onPin: (String) -> Unit, onBiometric: () -> Unit) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize().padding(28.dp), verticalArrangement = Arrangement.Center) {
        Text("EVENTMENU GO", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black)
        Text("Operação · Delivery · Eventos · Pay", color = MaterialTheme.colorScheme.primary)
        Spacer(Modifier.height(28.dp))
        Text("Entrar com e-mail e senha", style = MaterialTheme.typography.titleMedium)
        OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(password, { password = it }, label = { Text("Senha") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
        Button(onClick = { onLogin(email, password, "${Build.MANUFACTURER} ${Build.MODEL}") }, enabled = email.isNotBlank() && password.isNotBlank() && !state.loading, modifier = Modifier.fillMaxWidth().padding(top = 12.dp)) { Text("ENTRAR") }
        if (state.hasStoredSession) {
            HorizontalDivider(Modifier.padding(vertical = 22.dp))
            Text("Acesso rápido neste aparelho", style = MaterialTheme.typography.titleMedium)
            if (state.pinConfigured) {
                OutlinedTextField(pin, { pin = it.filter(Char::isDigit).take(8) }, label = { Text("PIN do funcionário") }, visualTransformation = PasswordVisualTransformation(), singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedButton(onClick = { onPin(pin) }, enabled = pin.length >= 4, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("ENTRAR COM PIN") }
            }
            if (state.biometricEnabled) OutlinedButton(onClick = onBiometric, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("ENTRAR COM BIOMETRIA") }
        }
    }
}

@Composable
fun ModePickerScreen(name: String, modes: List<AppMode>, onSelect: (AppMode) -> Unit) {
    Column(Modifier.fillMaxSize().padding(28.dp), verticalArrangement = Arrangement.Center) {
        Text("Olá, $name", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
        Text("Escolha o modo permitido pelo servidor")
        Spacer(Modifier.height(20.dp))
        modes.forEach { mode ->
            Card(onClick = { onSelect(mode) }, modifier = Modifier.fillMaxWidth().padding(vertical = 7.dp)) {
                Row(Modifier.padding(22.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(mode.emoji, style = MaterialTheme.typography.headlineMedium)
                    Column(Modifier.padding(start = 16.dp)) {
                        Text(mode.label, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text("Permissões validadas na API")
                    }
                }
            }
        }
    }
}

@Composable
fun HomeScreen(state: GoState, onRefresh: () -> Unit) {
    val session = state.session ?: return
    val orders = state.orders
    val delivery = orders.filter { it.channel == "delivery" }
    val preparing = orders.count { it.status == "preparing" }
    val ready = orders.count { it.status == "ready" }
    val pending = orders.count { it.paymentStatus != "paid" && it.status !in setOf("cancelled", "completed") }
    val received = orders.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
    LazyColumn(Modifier.fillMaxSize().padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("Olá, ${session.user.name}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("${roleLabel(session.user.role)} · ${state.mode?.label}")
            Text("Turno operacional: ${if (state.workShift?.status == "open") "Aberto" else "Fechado"}")
        }
        when (state.mode) {
            AppMode.DELIVERY -> {
                item { MetricCard("Entregas pendentes", delivery.count { it.status == "ready" }.toString()) }
                item { MetricCard("Em andamento", delivery.count { it.status == "out_for_delivery" }.toString()) }
                item { MetricCard("Recebido nos pedidos visíveis", money(received)) }
                item { MetricCard("Dinheiro a entregar ao caixa", money(state.deliveryCash?.outstandingCents ?: 0)) }
                item { MetricCard("Aguardando pagamento", delivery.count { it.paymentStatus != "paid" }.toString()) }
            }
            AppMode.EVENTS -> {
                item { MetricCard("Modo", "Check-in e lista") }
                item { Text("Use o botão central ESCANEAR para ingresso ou convidado.") }
            }
            AppMode.PAY -> {
                item { MetricCard("Caixa financeiro", if (state.cashOpen) "ABERTO" else "FECHADO") }
                item { MetricCard("Pedidos pagos visíveis", money(received)) }
                item { MetricCard("Pendentes", pending.toString()) }
            }
            else -> {
                item { MetricCard("Preparando", preparing.toString()) }
                item { MetricCard("Prontos", ready.toString()) }
                item { MetricCard("Delivery", delivery.size.toString()) }
                item { MetricCard("Aguardando pagamento", pending.toString()) }
            }
        }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR") } }
    }
}

@Composable
private fun MetricCard(label: String, value: String) {
    Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(18.dp)) { Text(label); Text(value, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black) } }
}

@Composable
fun OrdersScreen(orders: List<Order>, onRefresh: () -> Unit, onStatus: (Int, String) -> Unit) {
    val filters = listOf("Todos", "Novos", "Preparando", "Prontos", "Delivery", "Finalizados")
    var filter by remember { mutableStateOf("Todos") }
    val visible = orders.filter { order -> when (filter) {
        "Novos" -> order.status in setOf("pending", "confirmed")
        "Preparando" -> order.status == "preparing"
        "Prontos" -> order.status == "ready"
        "Delivery" -> order.channel == "delivery"
        "Finalizados" -> order.status in setOf("completed", "cancelled")
        else -> true
    } }
    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Pedidos", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { filters.take(3).forEach { f -> FilterChip(selected = filter == f, onClick = { filter = f }, label = { Text(f) }) } }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { filters.drop(3).forEach { f -> FilterChip(selected = filter == f, onClick = { filter = f }, label = { Text(f) }) } }
        }
        items(visible, key = { it.id }) { order -> OrderCard(order, onStatus) }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR") } }
    }
}

@Composable
private fun OrderCard(order: Order, onStatus: (Int, String) -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("#${order.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                Text(money(order.totalCents), fontWeight = FontWeight.Bold)
            }
            Text("${order.customerName} · ${order.channel.uppercase()}")
            Text("Status: ${order.status} · Pagamento: ${order.paymentStatus}")
            if (order.deliveryAddress.isNotBlank()) Text(order.deliveryAddress)
            when (order.status) {
                "confirmed" -> Button(onClick = { onStatus(order.id, "preparing") }) { Text("INICIAR") }
                "preparing" -> Button(onClick = { onStatus(order.id, "ready") }) { Text("PRONTO") }
                "ready" -> if (order.channel != "delivery" && order.paymentStatus == "paid") Button(onClick = { onStatus(order.id, "completed") }) { Text("ENTREGAR") }
            }
        }
    }
}

@Composable
fun CashScreen(open: Boolean, onOpen: (Int) -> Unit, onClose: (Int) -> Unit) {
    var amount by remember { mutableStateOf("") }
    val cents = ((amount.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
    Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Caixa", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
        Text(if (open) "🟢 Caixa financeiro aberto" else "⚪ Caixa financeiro fechado")
        OutlinedTextField(amount, { amount = it }, label = { Text(if (open) "Dinheiro contado ao fechar" else "Fundo inicial") }, modifier = Modifier.fillMaxWidth())
        if (open) Button(onClick = { onClose(cents) }, modifier = Modifier.fillMaxWidth()) { Text("FECHAR CAIXA") }
        else Button(onClick = { onOpen(cents) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR CAIXA") }
        Text("PIX e cartão são conciliados pelo servidor; este valor representa somente dinheiro físico.")
    }
}

@Composable
fun EventsScreen(onScan: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
        Text("🎟", style = MaterialTheme.typography.displayLarge)
        Text("Modo Evento", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
        Text("Ingressos · Lista · Check-in")
        Button(onClick = onScan, modifier = Modifier.fillMaxWidth().padding(top = 22.dp)) { Text("LER INGRESSO / CONVIDADO") }
    }
}

@Composable
fun QrResultDialog(qr: QrResult, onDismiss: () -> Unit, onAction: () -> Unit) {
    val actionLabel = when (qr.type) {
        "ticket", "guest" -> "REALIZAR CHECK-IN"
        "delivery_handoff" -> "CONFIRMAR RECEBIMENTO"
        else -> null
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(when (qr.type) { "ticket" -> "INGRESSO"; "guest" -> "CONVIDADO"; "table" -> "MESA / COMANDA"; "delivery_handoff" -> "REPASSE DO ENTREGADOR"; else -> "QR IDENTIFICADO" }) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(qr.title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                if (qr.subtitle.isNotBlank()) Text(qr.subtitle)
                qr.amountCents?.let { Text(money(it), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black) }
                if (qr.status.isNotBlank()) Text("Status: ${qr.status}")
                Text("Tipo: ${qr.type}")
                if (qr.type == "delivery_handoff") Text("Ao confirmar, o valor entra no caixa físico aberto e sai da pendência do turno do entregador.")
            }
        },
        confirmButton = { if (actionLabel != null) Button(onClick = onAction) { Text(actionLabel) } else TextButton(onClick = onDismiss) { Text("OK") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )
}
