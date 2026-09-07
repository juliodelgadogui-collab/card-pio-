package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Assessment
import androidx.compose.material.icons.filled.Badge
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.DeliveryDining
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.PointOfSale
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Restaurant
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.ui.components.TenantBrandLogo
import br.com.eventmenu.go.ui.theme.LocalEventMenuBranding

private data class HomeShortcut(val icon: ImageVector, val label: String, val subtitle: String, val screen: AppScreen)

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
    val branding = LocalEventMenuBranding.current
    val tenantFallback = session.user.tenantName.ifBlank { "Empresa #${session.user.tenantId}" }
    val brandName = branding.displayName.takeIf { branding.applyApp && it.isNotBlank() } ?: tenantFallback
    val unit = dashboardUnitName(shift?.unitName)

    val shortcuts = buildList {
        if ("orders_create" in permissions) add(HomeShortcut(Icons.Default.ShoppingCart, "Nova venda", "Abrir PDV", AppScreen.POS))
        if ("orders_view" in permissions || "orders_manage" in permissions) add(HomeShortcut(Icons.Default.ReceiptLong, "Pedidos", "Acompanhar operação", AppScreen.ORDERS))
        if ("tables" in permissions) add(HomeShortcut(Icons.Default.Restaurant, "Mesas", "Comandas e contas", AppScreen.TABLES))
        if ("cash" in permissions) add(HomeShortcut(Icons.Default.PointOfSale, "Caixa", "Entradas e fechamento", AppScreen.CASH))
        if ("orders_kitchen" in permissions) add(HomeShortcut(Icons.Default.Restaurant, "Cozinha", "Produção / KDS", AppScreen.KITCHEN))
        if ("orders_dispatch" in permissions || "delivery_assign" in permissions) add(HomeShortcut(Icons.Default.DeliveryDining, "Expedição", "Pedidos prontos", AppScreen.DISPATCH))
        if (mode == AppMode.DELIVERY || "orders_delivery" in permissions) add(HomeShortcut(Icons.Default.DeliveryDining, "Entregas", "Minha rota", AppScreen.DELIVERY))
        if (mode == AppMode.EVENTS || "events" in permissions || "tickets" in permissions) add(HomeShortcut(Icons.Default.ConfirmationNumber, "Eventos", "Ingressos e bar", AppScreen.EVENTS))
        if ("reports" in permissions && mode in setOf(AppMode.OPERATION, AppMode.PAY)) add(HomeShortcut(Icons.Default.Assessment, "Gestão", "Indicadores e alertas", AppScreen.MANAGER))
        add(HomeShortcut(Icons.Default.Notifications, "Avisos", "Notificações", AppScreen.NOTIFICATIONS))
        add(HomeShortcut(Icons.Default.Badge, "Perfil", "Turno e dispositivo", AppScreen.PROFILE))
    }.distinctBy { it.screen }

    androidx.compose.foundation.lazy.LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primary),
            ) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Row(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            TenantBrandLogo(
                                logoUrl = branding.logoUrl.takeIf { branding.applyApp } ?: "",
                                displayName = brandName,
                                size = 50.dp,
                            )
                            Column(Modifier.weight(1f)) {
                                Text(brandName, color = MaterialTheme.colorScheme.onPrimary, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, maxLines = 1)
                                Text("Olá, ${firstName(session.user.name)}", color = MaterialTheme.colorScheme.onPrimary.copy(alpha = .86f), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                            }
                        }
                        Surface(shape = CircleShape, color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.14f)) {
                            IconButton(onClick = onRefresh) { Icon(Icons.Default.Refresh, contentDescription = "Atualizar", tint = MaterialTheme.colorScheme.onPrimary) }
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Surface(shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.14f)) {
                            Text(unit, modifier = Modifier.padding(horizontal = 11.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onPrimary, style = MaterialTheme.typography.bodyMedium)
                        }
                        if (!shift?.startedAt.isNullOrBlank()) {
                            Surface(shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.14f)) {
                                Text("Turno ${dashboardTime(shift!!.startedAt)}", modifier = Modifier.padding(horizontal = 11.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.onPrimary, style = MaterialTheme.typography.bodyMedium)
                            }
                        }
                    }
                }
            }
        }

        item { Text("Acesso rápido", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }

        shortcuts.chunked(2).forEach { row ->
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    row.forEach { shortcut -> HomeShortcutCard(shortcut, Modifier.weight(1f)) { onNavigate(shortcut.screen) } }
                    if (row.size == 1) Spacer(Modifier.weight(1f))
                }
            }
        }

        item { Text("Resumo de hoje", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }

        when (mode) {
            AppMode.DELIVERY -> {
                val deliveries = state.orders.filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }
                val ready = deliveries.count { it.status == "ready" }
                val route = deliveries.count { it.status == "out_for_delivery" }
                val paid = deliveries.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                item { MetricRow("Para retirar", ready.toString(), "Em rota", route.toString()) }
                item { MetricRow("Recebido", dashboardMoney(paid), "A repassar", dashboardMoney(state.deliveryCash?.outstandingCents ?: 0), emphasizeA = true) }
                item { PrimaryHomeAction("Abrir minhas entregas", Icons.Default.DeliveryDining) { onNavigate(AppScreen.DELIVERY) } }
            }

            AppMode.EVENTS -> {
                val event = state.events.firstOrNull { it.id == state.selectedEventId } ?: state.events.firstOrNull()
                if (event == null) item { DashboardInfoCard("Nenhum evento disponível", "Atualize a operação ou confirme seu acesso.") }
                else {
                    item { DashboardInfoCard(event.name, event.venue.ifBlank { "Evento selecionado" }) }
                    item { MetricRow("Ingressos", event.ticketsPaid.toString(), "Check-ins", event.ticketsCheckedIn.toString()) }
                    item { MetricRow("Receita ingressos", dashboardMoney(event.ticketRevenueCents), "Receita bar", dashboardMoney(event.barRevenueCents), emphasizeA = true) }
                }
                if ("tickets" in permissions || "guests" in permissions) item { PrimaryHomeAction("Ler ingresso ou convidado", Icons.Default.QrCodeScanner, onScan) }
            }

            AppMode.PAY -> {
                val cash = state.cashSummary
                item { StatusCard("Caixa financeiro", if (state.cashOpen) "Aberto" else "Fechado", state.cashOpen) }
                item { MetricRow("Saldo esperado", dashboardMoney(cash?.expectedCashCents ?: 0), "Movimentos", (cash?.movements?.size ?: 0).toString(), emphasizeA = true) }
                val pix = cash?.digital?.filter { it.provider.contains("pag", true) || it.provider.contains("pix", true) || it.provider.contains("mercado", true) }?.sumOf { it.totalCents } ?: 0
                val cards = cash?.digital?.filterNot { it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                item { MetricRow("PIX / digital", dashboardMoney(pix), "Cartões", dashboardMoney(cards)) }
            }

            AppMode.OPERATION -> {
                val manager = state.managerOverview
                val todayRevenue = manager?.revenueTodayCents ?: state.orders.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                val activeOrders = manager?.ordersNow ?: state.orders.count { it.status !in setOf("completed", "cancelled") }
                val pendingPayments = manager?.pendingPayments ?: state.orders.count { it.paymentStatus != "paid" && it.status !in setOf("completed", "cancelled") }
                val paidOrders = state.orders.count { it.paymentStatus == "paid" }
                val avgTicket = if (paidOrders > 0) todayRevenue / paidOrders else 0
                item { MetricRow("Vendas", dashboardMoney(todayRevenue), "Pedidos ativos", activeOrders.toString(), emphasizeA = true) }
                item { MetricRow("Ticket médio", dashboardMoney(avgTicket), "Pagamentos pendentes", pendingPayments.toString()) }
                if ("reports" in permissions && manager != null && manager.kitchenDelayed > 0) {
                    item { DashboardInfoCard("Atenção na operação", "${manager.kitchenDelayed} pedido(s) com atraso na cozinha.") }
                }
            }
        }

        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Button(onClick = onScan, modifier = Modifier.weight(1f)) {
                    Icon(Icons.Default.QrCodeScanner, contentDescription = null)
                    Spacer(Modifier.size(8.dp))
                    Text("Ler QR")
                }
                Button(onClick = { onNavigate(AppScreen.PROFILE) }, modifier = Modifier.weight(1f)) {
                    Icon(Icons.Default.Badge, contentDescription = null)
                    Spacer(Modifier.size(8.dp))
                    Text("Meu perfil")
                }
            }
        }
        item { Spacer(Modifier.height(6.dp)) }
    }
}

@Composable
private fun HomeShortcutCard(shortcut: HomeShortcut, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = modifier,
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer) {
                Box(Modifier.size(42.dp), contentAlignment = Alignment.Center) {
                    Icon(shortcut.icon, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(22.dp))
                }
            }
            Text(shortcut.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text(shortcut.subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun PrimaryHomeAction(label: String, icon: ImageVector, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Icon(icon, contentDescription = null)
        Spacer(Modifier.size(8.dp))
        Text(label)
    }
}

@Composable
private fun MetricRow(labelA: String, valueA: String, labelB: String, valueB: String, emphasizeA: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        DashboardMetric(labelA, valueA, Modifier.weight(1f), emphasizeA)
        DashboardMetric(labelB, valueB, Modifier.weight(1f), false)
    }
}

@Composable
private fun DashboardMetric(label: String, value: String, modifier: Modifier = Modifier, emphasized: Boolean = false) {
    Card(modifier, shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
            Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, color = if (emphasized) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
        }
    }
}

@Composable
private fun DashboardInfoCard(title: String, text: String) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text(text, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun StatusCard(title: String, status: String, active: Boolean) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = if (active) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface)) {
        Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.Storefront, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            }
            Text(status, color = if (active) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant, fontWeight = FontWeight.Black)
        }
    }
}

private fun dashboardUnitName(raw: String?): String {
    val clean = raw?.trim().orEmpty()
    return if (clean.isBlank() || clean.equals("null", true)) "Unidade principal" else clean
}
private fun firstName(name: String): String = name.trim().substringBefore(' ').ifBlank { "equipe" }
private fun dashboardTime(value: String): String = value.trim().replace('T', ' ').substringAfter(' ', value).take(5).ifBlank { value }
private fun dashboardMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
