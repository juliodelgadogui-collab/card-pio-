package br.com.eventmenu.go.ui.screens

import android.graphics.BitmapFactory
import android.os.Build
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.OrderOperationsViewModel
import br.com.eventmenu.go.R
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.QrResult
import br.com.eventmenu.go.data.TenantBrand
import br.com.eventmenu.go.security.AppPermissionManager
import br.com.eventmenu.go.ui.theme.LocalTenantBrand
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.net.URL

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
    else -> "Equipe"
}

@Composable
fun LoginScreen(state: GoState, onLogin: (String, String, String) -> Unit, onPin: (String) -> Unit, onBiometric: () -> Unit) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    val context = LocalContext.current
    val brand = LocalTenantBrand.current
    val hasMissingPermissions = AppPermissionManager.missingLabels(context).isNotEmpty()

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 22.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item {
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth().padding(top = 28.dp)) {
                if (brand != null) TenantLogoOrMark(brand) else Image(
                    painter = painterResource(R.drawable.ic_eventmenu_logo),
                    contentDescription = "EventMenu GO",
                    modifier = Modifier.size(76.dp),
                )
                Spacer(Modifier.height(14.dp))
                Text(brand?.displayName ?: "EventMenu GO", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.ExtraBold)
                Text(
                    brand?.tagline?.takeIf { it.isNotBlank() } ?: "Operação, entregas e eventos",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (brand?.showEventMenuBrand == true) {
                    Text("Tecnologia EventMenu", color = MaterialTheme.colorScheme.outline, style = MaterialTheme.typography.bodyMedium)
                }
                Spacer(Modifier.height(24.dp))
            }
        }

        item {
            Card(
                Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            ) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Entrar", style = MaterialTheme.typography.headlineSmall)
                    Text("Use o acesso fornecido pela empresa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(password, { password = it }, label = { Text("Senha") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                    Button(
                        onClick = { onLogin(email, password, "${Build.MANUFACTURER} ${Build.MODEL}") },
                        enabled = email.isNotBlank() && password.isNotBlank() && !state.loading,
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text(if (state.loading) "Entrando..." else "Entrar") }

                    if (state.hasStoredSession && (state.pinConfigured || state.biometricEnabled)) {
                        HorizontalDivider(Modifier.padding(vertical = 3.dp))
                        Text("Acesso rápido", style = MaterialTheme.typography.titleMedium)
                        if (state.pinConfigured) {
                            OutlinedTextField(
                                pin,
                                { pin = it.filter(Char::isDigit).take(8) },
                                label = { Text("PIN") },
                                visualTransformation = PasswordVisualTransformation(),
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth(),
                            )
                            OutlinedButton(onClick = { onPin(pin) }, enabled = pin.length >= 4, modifier = Modifier.fillMaxWidth()) { Text("Entrar com PIN") }
                        }
                        if (state.biometricEnabled) OutlinedButton(onClick = onBiometric, modifier = Modifier.fillMaxWidth()) { Text("Usar biometria") }
                    }
                }
            }
        }

        if (hasMissingPermissions) {
            item {
                Card(
                    modifier = Modifier.fillMaxWidth().padding(top = 12.dp),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = .55f)),
                ) {
                    Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("Complete a configuração", style = MaterialTheme.typography.titleMedium)
                        Text(
                            "Permita o acesso solicitado pelo Android para receber avisos e usar os recursos do aparelho.",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Button(onClick = { AppPermissionManager.request(context) }, modifier = Modifier.fillMaxWidth()) { Text("Continuar") }
                        TextButton(onClick = { AppPermissionManager.openSettings(context) }, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("Abrir configurações") }
                    }
                }
            }
        }
        item { Spacer(Modifier.height(26.dp)) }
    }
}

@Composable
private fun TenantLogoOrMark(brand: TenantBrand) {
    val remote by produceState<ImageBitmap?>(initialValue = null, key1 = brand.logoUrl) {
        value = if (brand.logoUrl.isBlank()) null else withContext(Dispatchers.IO) {
            runCatching {
                URL(brand.logoUrl).openStream().use { stream -> BitmapFactory.decodeStream(stream)?.asImageBitmap() }
            }.getOrNull()
        }
    }
    if (remote != null) {
        Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surface) {
            Image(
                bitmap = remote!!,
                contentDescription = "Logo de ${brand.displayName}",
                contentScale = ContentScale.Fit,
                modifier = Modifier.size(76.dp).padding(7.dp),
            )
        }
    } else {
        Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.primary) {
            Text(
                brand.displayName.trim().take(1).uppercase().ifBlank { "E" },
                modifier = Modifier.padding(horizontal = 24.dp, vertical = 15.dp),
                color = MaterialTheme.colorScheme.onPrimary,
                style = MaterialTheme.typography.headlineLarge,
                fontWeight = FontWeight.ExtraBold,
            )
        }
    }
}

@Composable
fun ModePickerScreen(name: String, modes: List<AppMode>, onSelect: (AppMode) -> Unit) {
    val brand = LocalTenantBrand.current
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
        brand?.let { Text(it.displayName, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge) }
        Text("Olá, ${name.trim().substringBefore(' ')}", style = MaterialTheme.typography.headlineMedium)
        Text("Como você vai trabalhar agora?", color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(20.dp))
        modes.forEach { mode ->
            Card(
                onClick = { onSelect(mode) },
                modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            ) {
                Row(Modifier.padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium) {
                        Text(mode.label.take(1), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.padding(horizontal = 14.dp, vertical = 10.dp), color = MaterialTheme.colorScheme.primary)
                    }
                    Column(Modifier.padding(start = 14.dp)) {
                        Text(mode.label, style = MaterialTheme.typography.titleLarge)
                        Text(modeDescription(mode), color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}

private fun modeDescription(mode: AppMode) = when (mode) {
    AppMode.OPERATION -> "Pedidos, salão e produção"
    AppMode.DELIVERY -> "Minhas entregas e recebimentos"
    AppMode.EVENTS -> "Ingressos, convidados e bar"
    AppMode.PAY -> "Caixa e pagamentos"
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
            Text("Olá, ${session.user.name.trim().substringBefore(' ')}", style = MaterialTheme.typography.headlineMedium)
            Text("${roleLabel(session.user.role)} · ${state.mode?.label}", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        when (state.mode) {
            AppMode.DELIVERY -> {
                item { MetricCard("Para retirar", delivery.count { it.status == "ready" }.toString()) }
                item { MetricCard("Em rota", delivery.count { it.status == "out_for_delivery" }.toString()) }
                item { MetricCard("Recebido", money(received)) }
                item { MetricCard("A repassar", money(state.deliveryCash?.outstandingCents ?: 0)) }
            }
            AppMode.EVENTS -> item { MetricCard("Área ativa", "Eventos") }
            AppMode.PAY -> {
                item { MetricCard("Caixa", if (state.cashOpen) "Aberto" else "Fechado") }
                item { MetricCard("Recebido", money(received)) }
                item { MetricCard("A receber", pending.toString()) }
            }
            else -> {
                item { MetricCard("Em preparo", preparing.toString()) }
                item { MetricCard("Prontos", ready.toString()) }
                item { MetricCard("Delivery", delivery.size.toString()) }
                item { MetricCard("A receber", pending.toString()) }
            }
        }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") } }
    }
}

@Composable
private fun MetricCard(label: String, value: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(value, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
fun OrdersScreen(orders: List<Order>, onRefresh: () -> Unit, onStatus: (Int, String) -> Unit) {
    val app = LocalContext.current.applicationContext as EventMenuGoApplication
    val orderViewModel: OrderOperationsViewModel = viewModel(factory = OrderOperationsViewModel.Factory(app.orderOperationsRepository))
    val orderState by orderViewModel.state.collectAsState()
    val filters = listOf("Todos", "Novos", "Preparando", "Prontos", "Delivery", "Finalizados")
    var filter by remember { mutableStateOf("Todos") }
    var query by remember { mutableStateOf("") }
    val visible = orders.filter { order ->
        val byStatus = when (filter) {
            "Novos" -> order.status in setOf("pending", "confirmed")
            "Preparando" -> order.status == "preparing"
            "Prontos" -> order.status == "ready"
            "Delivery" -> order.channel == "delivery"
            "Finalizados" -> order.status in setOf("served", "completed", "cancelled")
            else -> true
        }
        val q = query.trim().lowercase()
        val byQuery = q.isBlank() || order.id.toString().contains(q) || safeText(order.customerName).lowercase().contains(q) || order.channel.lowercase().contains(q)
        byStatus && byQuery
    }
    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Pedidos", style = MaterialTheme.typography.headlineMedium)
            Text("Acompanhe o andamento da operação", color = MaterialTheme.colorScheme.onSurfaceVariant)
            OutlinedTextField(query, { query = it }, label = { Text("Buscar") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
            orderState.error?.let { Text("Não foi possível abrir os detalhes. Tente novamente.", color = MaterialTheme.colorScheme.error) }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(top = 8.dp)) { filters.take(3).forEach { f -> FilterChip(selected = filter == f, onClick = { filter = f }, label = { Text(f) }) } }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { filters.drop(3).forEach { f -> FilterChip(selected = filter == f, onClick = { filter = f }, label = { Text(f) }) } }
        }
        items(visible, key = { it.id }) { order -> OrderCard(order, onStatus, orderViewModel::open) }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar pedidos") } }
    }

    orderState.detail?.let { detail ->
        OrderDetailDialog(detail = detail, canAccept = false, onAccept = {}, onDismiss = orderViewModel::close)
    }
}

@Composable
private fun OrderCard(order: Order, onStatus: (Int, String) -> Unit, onOpen: (Int) -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Column {
                    Text("Pedido #${order.id}", style = MaterialTheme.typography.titleLarge)
                    safeText(order.customerName).takeIf { it.isNotBlank() && !it.equals("Consumidor", true) }?.let { Text(it, fontWeight = FontWeight.SemiBold) }
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(money(order.totalCents), fontWeight = FontWeight.ExtraBold, style = MaterialTheme.typography.titleLarge)
                    Text(statusLabel(order.status), color = statusColor(order.status), style = MaterialTheme.typography.labelLarge)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SmallPill(channelLabel(order.channel), false)
                SmallPill(paymentStatusLabel(order.paymentStatus), order.paymentStatus == "paid")
            }
            safeText(order.deliveryAddress).takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { onOpen(order.id) }, modifier = Modifier.weight(1f)) { Text("Detalhes") }
                when (order.status) {
                    "confirmed" -> Button(onClick = { onStatus(order.id, "preparing") }, modifier = Modifier.weight(1f)) { Text("Preparar") }
                    "preparing" -> Button(onClick = { onStatus(order.id, "ready") }, modifier = Modifier.weight(1f)) { Text("Marcar pronto") }
                    "ready" -> if (order.channel != "delivery" && order.paymentStatus == "paid") Button(onClick = { onStatus(order.id, "completed") }, modifier = Modifier.weight(1f)) { Text("Entregar") }
                }
            }
        }
    }
}

@Composable
private fun SmallPill(label: String, positive: Boolean) {
    Surface(
        color = if (positive) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.primaryContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 9.dp, vertical = 5.dp),
            color = if (positive) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onPrimaryContainer,
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

@Composable
fun CashScreen(open: Boolean, onOpen: (Int) -> Unit, onClose: (Int) -> Unit) {
    var amount by remember { mutableStateOf("") }
    val cents = ((amount.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
    Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Caixa", style = MaterialTheme.typography.headlineMedium)
        Text(if (open) "Caixa aberto" else "Caixa fechado", color = if (open) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurfaceVariant, fontWeight = FontWeight.SemiBold)
        OutlinedTextField(amount, { amount = it }, label = { Text(if (open) "Dinheiro contado" else "Fundo inicial") }, modifier = Modifier.fillMaxWidth())
        if (open) Button(onClick = { onClose(cents) }, modifier = Modifier.fillMaxWidth()) { Text("Fechar caixa") }
        else Button(onClick = { onOpen(cents) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir caixa") }
        Text("Informe apenas o dinheiro físico. PIX e cartão são registrados automaticamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun EventsScreen(onScan: () -> Unit) {
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
        Text("Eventos", style = MaterialTheme.typography.headlineMedium)
        Text("Ingressos, convidados e check-in", color = MaterialTheme.colorScheme.onSurfaceVariant)
        Button(onClick = onScan, modifier = Modifier.fillMaxWidth().padding(top = 22.dp)) { Text("Ler ingresso ou convidado") }
    }
}

@Composable
fun QrResultDialog(qr: QrResult, onDismiss: () -> Unit, onAction: () -> Unit) {
    val actionLabel = when (qr.type) {
        "ticket", "guest" -> "Realizar check-in"
        "delivery_handoff" -> "Confirmar recebimento"
        "order" -> "Abrir pedido"
        else -> null
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(when (qr.type) { "ticket" -> "Ingresso"; "guest" -> "Convidado"; "table" -> "Mesa"; "delivery_handoff" -> "Repasse"; "order" -> "Pedido"; else -> "Código identificado" }) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(qr.title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                if (qr.subtitle.isNotBlank()) Text(qr.subtitle)
                qr.amountCents?.let { Text(money(it), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold) }
                if (qr.status.isNotBlank()) Text(statusLabel(qr.status), color = statusColor(qr.status))
            }
        },
        confirmButton = { if (actionLabel != null) Button(onClick = onAction) { Text(actionLabel) } else TextButton(onClick = onDismiss) { Text("OK") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Fechar") } },
    )
}

@Composable
private fun statusColor(status: String) = when (status) {
    "ready", "completed", "served" -> MaterialTheme.colorScheme.secondary
    "cancelled" -> MaterialTheme.colorScheme.error
    else -> MaterialTheme.colorScheme.primary
}

private fun statusLabel(status: String) = when (status.lowercase()) {
    "pending" -> "Novo"
    "confirmed" -> "Confirmado"
    "preparing" -> "Em preparo"
    "ready" -> "Pronto"
    "out_for_delivery" -> "Em rota"
    "completed" -> "Concluído"
    "served" -> "Entregue"
    "cancelled" -> "Cancelado"
    else -> "Em andamento"
}

private fun paymentStatusLabel(status: String) = when (status.lowercase()) {
    "paid" -> "Pago"
    "pending" -> "Processando"
    "refunded" -> "Estornado"
    else -> "A receber"
}

private fun channelLabel(channel: String) = when (channel.lowercase()) {
    "counter" -> "Balcão"
    "pickup" -> "Retirada"
    "delivery" -> "Delivery"
    "table" -> "Mesa"
    "event_bar" -> "Evento"
    else -> "Pedido"
}

private fun safeText(value: String): String {
    val clean = value.trim()
    return if (clean.isBlank() || clean.equals("null", true) || clean.equals("undefined", true)) "" else clean
}
