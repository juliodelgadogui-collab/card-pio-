package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.ui.theme.EventMenuUi
import br.com.eventmenu.go.ui.theme.LocalTenantBrand

private data class DashboardShortcutItem(
    val icon: ImageVector,
    val label: String,
    val caption: String,
    val screen: AppScreen,
)

@Composable
fun RoleDashboardScreen(
    state: GoState,
    onNavigate: (AppScreen) -> Unit,
    onScan: () -> Unit,
    onRefresh: () -> Unit,
) {
    val session = state.session ?: return
    val permissions = session.permissions
    val mode = state.mode ?: return
    val shift = state.workShift
    val brand = LocalTenantBrand.current
    val tenant = brand?.displayName?.takeIf { it.isNotBlank() } ?: session.user.tenantName.ifBlank { "Sua empresa" }
    val unit = dashboardUnitName(shift?.unitName)
    val shiftTime = shift?.startedAt?.takeIf { it.isNotBlank() }?.let(::dashboardTime)
    val shortcuts = buildList {
        if ("cancellation_approve" in permissions || "discount_approve" in permissions || "reports" in permissions) add(DashboardShortcutItem(Icons.Default.Assessment, "Gestão", "Indicadores e aprovações", AppScreen.MANAGER))
        if ("orders_view" in permissions || "orders_manage" in permissions) add(DashboardShortcutItem(Icons.Default.ReceiptLong, "Pedidos", "Fila e detalhes", AppScreen.ORDERS))
        if ("tables" in permissions) add(DashboardShortcutItem(Icons.Default.TableRestaurant, "Mesas", "Contas e consumo", AppScreen.TABLES))
        if ("orders_create" in permissions) add(DashboardShortcutItem(Icons.Default.PointOfSale, "Nova venda", "Abrir PDV", AppScreen.POS))
        if ("cash" in permissions) add(DashboardShortcutItem(Icons.Default.AccountBalanceWallet, "Caixa", "Movimentos e saldo", AppScreen.CASH))
        if ("orders_kitchen" in permissions) add(DashboardShortcutItem(Icons.Default.Restaurant, "Cozinha", "Produção agora", AppScreen.KITCHEN))
        if ("orders_dispatch" in permissions || "delivery_assign" in permissions) add(DashboardShortcutItem(Icons.Default.LocalShipping, "Saída", "Separar e despachar", AppScreen.DISPATCH))
        if (mode == AppMode.DELIVERY || "orders_delivery" in permissions) add(DashboardShortcutItem(Icons.Default.DeliveryDining, "Entregas", "Minhas rotas", AppScreen.DELIVERY))
        if (mode == AppMode.EVENTS || "events" in permissions || "tickets" in permissions) add(DashboardShortcutItem(Icons.Default.ConfirmationNumber, "Eventos", "Ingressos e operação", AppScreen.EVENTS))
        add(DashboardShortcutItem(Icons.Default.MoreHoriz, "Mais", "Perfil e dispositivo", AppScreen.PROFILE))
    }.distinctBy { it.label }.take(8)

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(horizontal = EventMenuUi.SpaceMd, vertical = EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            OperationalHero(
                userName = firstName(session.user.name),
                tenant = tenant,
                unit = unit,
                mode = mode,
                shiftTime = shiftTime,
            )
        }

        item {
            SectionTitle("Ações rápidas", modeHint(mode))
        }

        shortcuts.chunked(2).forEach { row ->
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
                    row.forEach { shortcut ->
                        DashboardShortcut(shortcut, Modifier.weight(1f)) { onNavigate(shortcut.screen) }
                    }
                    if (row.size == 1) Spacer(Modifier.weight(1f))
                }
            }
        }

        item {
            PairingCard { onNavigate(AppScreen.PROFILE) }
        }

        item {
            SectionTitle("Agora", summaryHint(mode))
        }

        when (mode) {
            AppMode.DELIVERY -> {
                val deliveries = state.orders.filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }
                val ready = deliveries.count { it.status == "ready" }
                val route = deliveries.count { it.status == "out_for_delivery" }
                val paid = deliveries.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                item { MetricRow("Para retirar", ready.toString(), "Em rota", route.toString()) }
                item { MetricRow("Recebido", dashboardMoney(paid), "A repassar", dashboardMoney(state.deliveryCash?.outstandingCents ?: 0), emphasizeA = true, emphasizeB = true) }
                item { PrimaryOperationButton(Icons.Default.DeliveryDining, "Abrir minhas entregas") { onNavigate(AppScreen.DELIVERY) } }
            }

            AppMode.EVENTS -> {
                val event = state.events.firstOrNull { it.id == state.selectedEventId } ?: state.events.firstOrNull()
                if (event == null) {
                    item { DashboardCard("Nenhum evento disponível", "Atualize a operação ou confirme seu acesso.") }
                } else {
                    item { DashboardCard(event.name, event.venue.ifBlank { "Evento selecionado" }) }
                    item { MetricRow("Ingressos", event.ticketsPaid.toString(), "Check-ins", event.ticketsCheckedIn.toString()) }
                    item { MetricRow("Convidados", event.guestsPending.toString(), "Entraram", event.guestsCheckedIn.toString()) }
                    item { MetricRow("Ingressos", dashboardMoney(event.ticketRevenueCents), "Bar", dashboardMoney(event.barRevenueCents), emphasizeA = true, emphasizeB = true) }
                }
                if ("tickets" in permissions || "guests" in permissions) {
                    item { PrimaryOperationButton(Icons.Default.QrCodeScanner, "Ler ingresso ou convidado", onScan) }
                }
            }

            AppMode.PAY -> {
                val cash = state.cashSummary
                item { DashboardStatusCard("Caixa", if (state.cashOpen) "Aberto" else "Fechado", state.cashOpen) }
                item { MetricRow("Saldo esperado", dashboardMoney(cash?.expectedCashCents ?: 0), "Movimentos", (cash?.movements?.size ?: 0).toString(), emphasizeA = true) }
                val pix = cash?.digital?.filter { it.provider.contains("pag", true) || it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                val cards = cash?.digital?.filterNot { it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                item { MetricRow("PIX / digital", dashboardMoney(pix), "Cartões", dashboardMoney(cards)) }
                if ("cash" in permissions) item { PrimaryOperationButton(Icons.Default.AccountBalanceWallet, "Abrir caixa") { onNavigate(AppScreen.CASH) } }
            }

            AppMode.OPERATION -> {
                val manager = state.managerOverview
                val todayRevenue = manager?.revenueTodayCents ?: state.orders.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                val activeOrders = manager?.ordersNow ?: state.orders.count { it.status !in setOf("completed", "cancelled") }
                val pendingPayments = manager?.pendingPayments ?: state.orders.count { it.paymentStatus != "paid" && it.status !in setOf("completed", "cancelled") }
                val avgTicket = if (state.orders.isNotEmpty()) todayRevenue / state.orders.size else 0
                item { MetricRow("Vendas", dashboardMoney(todayRevenue), "Pedidos", activeOrders.toString(), emphasizeA = true) }
                item { MetricRow("Ticket médio", dashboardMoney(avgTicket), "A receber", pendingPayments.toString()) }
                if ("reports" in permissions && manager != null) item { MetricRow("Cozinha atrasada", manager.kitchenDelayed.toString(), "Entregadores", manager.deliveryOnline.toString()) }
                when {
                    "orders_create" in permissions -> item { PrimaryOperationButton(Icons.Default.PointOfSale, "Nova venda") { onNavigate(AppScreen.POS) } }
                    "orders_kitchen" in permissions -> item { PrimaryOperationButton(Icons.Default.Restaurant, "Abrir cozinha") { onNavigate(AppScreen.KITCHEN) } }
                    "orders_dispatch" in permissions || "delivery_assign" in permissions -> item { PrimaryOperationButton(Icons.Default.LocalShipping, "Abrir saída") { onNavigate(AppScreen.DISPATCH) } }
                }
            }
        }

        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
                OutlinedButton(onClick = onScan, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.ActionHeight)) {
                    Icon(Icons.Default.QrCodeScanner, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Ler QR")
                }
                OutlinedButton(onClick = onRefresh, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.ActionHeight)) {
                    Icon(Icons.Default.Refresh, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Atualizar")
                }
            }
        }
        item { Spacer(Modifier.height(8.dp)) }
    }
}

@Composable
private fun OperationalHero(userName: String, tenant: String, unit: String, mode: AppMode, shiftTime: String?) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
    ) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text("Olá, $userName", style = MaterialTheme.typography.headlineMedium)
                    Text(tenant, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary)
                }
                ModeBadge(mode)
            }
            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.65f))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(18.dp)) {
                InfoLine(Icons.Default.Storefront, "Unidade", unit, Modifier.weight(1f))
                InfoLine(Icons.Default.Schedule, "Turno", shiftTime?.let { "Desde $it" } ?: "Em andamento", Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun ModeBadge(mode: AppMode) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.colorScheme.primary) {
        Text(
            text = modeLabel(mode),
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = MaterialTheme.colorScheme.onPrimary,
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

@Composable
private fun InfoLine(icon: ImageVector, label: String, value: String, modifier: Modifier = Modifier) {
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
        Column {
            Text(label, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(value, style = MaterialTheme.typography.titleMedium)
        }
    }
}

@Composable
private fun SectionTitle(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        Text(subtitle, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun PairingCard(onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(13.dp),
        ) {
            Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium) {
                Icon(Icons.Default.Computer, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(11.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text("Conectar ao EventMenu Desktop", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text("Pareie este celular pelo Perfil usando o QR Code.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            }
            Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DashboardShortcut(item: DashboardShortcutItem, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = modifier.heightIn(min = 116.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.fillMaxSize().padding(15.dp), verticalArrangement = Arrangement.SpaceBetween) {
            Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium) {
                Icon(item.icon, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(9.dp))
            }
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(item.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text(item.caption, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun PrimaryOperationButton(icon: ImageVector, label: String, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
        Icon(icon, contentDescription = null)
        Spacer(Modifier.width(9.dp))
        Text(label)
    }
}

@Composable
private fun MetricRow(labelA: String, valueA: String, labelB: String, valueB: String, emphasizeA: Boolean = false, emphasizeB: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
        DashboardMetric(labelA, valueA, Modifier.weight(1f), emphasizeA)
        DashboardMetric(labelB, valueB, Modifier.weight(1f), emphasizeB)
    }
}

@Composable
private fun DashboardMetric(label: String, value: String, modifier: Modifier = Modifier, emphasized: Boolean = false) {
    Card(modifier, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            Text(value, style = MaterialTheme.typography.titleLarge, color = if (emphasized) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurface)
        }
    }
}

@Composable
private fun DashboardCard(title: String, text: String) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge)
            Text(text, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DashboardStatusCard(title: String, status: String, active: Boolean) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Row(Modifier.fillMaxWidth().padding(17.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Surface(
                shape = MaterialTheme.shapes.small,
                color = if (active) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant,
            ) {
                Text(
                    status,
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                    color = if (active) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.labelLarge,
                )
            }
        }
    }
}

private fun modeLabel(mode: AppMode): String = when (mode) {
    AppMode.DELIVERY -> "Entrega"
    AppMode.EVENTS -> "Eventos"
    AppMode.PAY -> "Caixa"
    AppMode.OPERATION -> "Operação"
}

private fun modeHint(mode: AppMode): String = when (mode) {
    AppMode.DELIVERY -> "Acesse rotas, pedidos e repasses com poucos toques."
    AppMode.EVENTS -> "Check-in, bar e operação do evento no mesmo lugar."
    AppMode.PAY -> "Atalhos de recebimento e caixa conforme seu acesso."
    AppMode.OPERATION -> "Só aparecem funções liberadas para o seu perfil."
}

private fun summaryHint(mode: AppMode): String = when (mode) {
    AppMode.DELIVERY -> "Prioridades das suas entregas neste turno."
    AppMode.EVENTS -> "Movimento do evento selecionado."
    AppMode.PAY -> "Situação financeira disponível para este perfil."
    AppMode.OPERATION -> "Indicadores rápidos da operação atual."
}

private fun dashboardUnitName(raw: String?): String {
    val clean = raw?.trim().orEmpty()
    return if (clean.isBlank() || clean.equals("null", true)) "Unidade principal" else clean
}

private fun firstName(name: String): String = name.trim().substringBefore(' ').ifBlank { "equipe" }
private fun dashboardTime(value: String): String = value.trim().replace('T', ' ').substringAfter(' ', value).take(5).ifBlank { value }
private fun dashboardMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
