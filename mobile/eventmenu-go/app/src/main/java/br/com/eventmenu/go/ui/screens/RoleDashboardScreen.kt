package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.AppMode

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
    val tenant = session.user.tenantName.ifBlank { "Empresa #${session.user.tenantId}" }
    val unit = dashboardUnitName(shift?.unitName)

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Text("EVENTMENU GO", color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
                    Text("${greeting()}, ${firstName(session.user.name)}", style = MaterialTheme.typography.headlineMedium)
                    Text(tenant, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        DashboardInfoPill(dashboardModeLabel(mode), Modifier.weight(1f))
                        DashboardInfoPill(unit, Modifier.weight(1f))
                    }
                    if (!shift?.startedAt.isNullOrBlank()) {
                        Text("Turno aberto desde ${dashboardTime(shift!!.startedAt)}", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                    }
                }
            }
        }

        when (mode) {
            AppMode.DELIVERY -> {
                val deliveries = state.orders.filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }
                val ready = deliveries.count { it.status == "ready" }
                val route = deliveries.count { it.status == "out_for_delivery" }
                val paid = deliveries.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                item { SectionTitle("Minha rota", "Entregas atribuídas a este turno") }
                item { MetricRow("Para retirar", ready.toString(), "Em rota", route.toString()) }
                item { MetricRow("Recebido", dashboardMoney(paid), "A repassar", dashboardMoney(state.deliveryCash?.outstandingCents ?: 0), emphasizeB = true) }
                item { MetricRow("Pagas", deliveries.count { it.paymentStatus == "paid" }.toString(), "Pendentes", deliveries.count { it.paymentStatus != "paid" }.toString()) }
                item {
                    val next = deliveries.firstOrNull { it.status == "out_for_delivery" } ?: deliveries.firstOrNull { it.status == "ready" }
                    Button(onClick = { onNavigate(AppScreen.DELIVERY) }, enabled = next != null, modifier = Modifier.fillMaxWidth()) {
                        Text(if (next == null) "Nenhuma entrega pendente" else "Abrir próxima entrega · #${next.id}")
                    }
                }
                item { OutlinedButton(onClick = { onNavigate(AppScreen.PROFILE) }, modifier = Modifier.fillMaxWidth()) { Text("Ver turno e fechamento") } }
            }

            AppMode.EVENTS -> {
                val event = state.events.firstOrNull { it.id == state.selectedEventId } ?: state.events.firstOrNull()
                item { SectionTitle("Operação do evento", "Check-in, convidados e consumo") }
                if (event == null) {
                    item { DashboardCard("Nenhum evento disponível", "Atualize a operação ou confirme o acesso deste usuário.") }
                } else {
                    item { DashboardCard(event.name, event.venue.ifBlank { "Evento selecionado" }) }
                    item { MetricRow("Ingressos", event.ticketsPaid.toString(), "Check-ins", event.ticketsCheckedIn.toString()) }
                    item { MetricRow("Convidados", event.guestsPending.toString(), "Entraram", event.guestsCheckedIn.toString()) }
                    item { MetricRow("Ingressos", dashboardMoney(event.ticketRevenueCents), "Bar", dashboardMoney(event.barRevenueCents), emphasizeA = true, emphasizeB = true) }
                }
                if ("tickets" in permissions || "guests" in permissions) {
                    item { Button(onClick = onScan, modifier = Modifier.fillMaxWidth()) { Text("Ler ingresso ou convidado") } }
                }
                item { OutlinedButton(onClick = { onNavigate(AppScreen.EVENTS) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir operação do evento") } }
            }

            AppMode.PAY -> {
                val cash = state.cashSummary
                item { SectionTitle("Financeiro", "Recebimentos e caixa deste turno") }
                item { DashboardStatusCard("Caixa financeiro", if (state.cashOpen) "Aberto" else "Fechado", state.cashOpen) }
                item { MetricRow("Saldo esperado", dashboardMoney(cash?.expectedCashCents ?: 0), "Movimentos", (cash?.movements?.size ?: 0).toString(), emphasizeA = true) }
                val pix = cash?.digital?.filter { it.provider.contains("pag", true) || it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                val cards = cash?.digital?.filterNot { it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                item { MetricRow("PIX / digital", dashboardMoney(pix), "Cartões", dashboardMoney(cards)) }
                item { Button(onClick = { onNavigate(AppScreen.CASH) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir caixa") } }
                if ("orders_create" in permissions) item { OutlinedButton(onClick = { onNavigate(AppScreen.POS) }, modifier = Modifier.fillMaxWidth()) { Text("Novo pedido") } }
            }

            AppMode.OPERATION -> {
                val manager = state.managerOverview
                if ("reports" in permissions && manager != null) {
                    item { SectionTitle("Visão da operação", "Indicadores em tempo real") }
                    item { MetricRow("Pedidos agora", manager.ordersNow.toString(), "Cozinha atrasada", manager.kitchenDelayed.toString()) }
                    item { MetricRow("Entregadores", manager.deliveryOnline.toString(), "Caixas abertos", manager.cashOpen.toString()) }
                    item { MetricRow("Faturamento hoje", dashboardMoney(manager.revenueTodayCents), "Pendências", manager.pendingPayments.toString(), emphasizeA = true) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.MANAGER) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir visão gerencial") } }
                }

                if ("orders_kitchen" in permissions) {
                    val fresh = state.kitchenTickets.count { it.status == "confirmed" }
                    val preparing = state.kitchenTickets.count { it.status == "preparing" }
                    item { SectionTitle("Cozinha", "Fila de produção") }
                    item { MetricRow("Novos", fresh.toString(), "Preparando", preparing.toString()) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.KITCHEN) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir KDS") } }
                }

                if ("orders_dispatch" in permissions || "delivery_assign" in permissions) {
                    val ready = state.orders.filter { it.status == "ready" }
                    val pickup = ready.count { it.channel in setOf("counter", "pickup") }
                    val delivery = ready.count { it.channel == "delivery" }
                    val unassigned = ready.count { it.channel == "delivery" && it.assignedDeliveryUserId == null }
                    item { SectionTitle("Balcão e despacho", "Pedidos prontos para sair") }
                    item { MetricRow("Prontos", ready.size.toString(), "Retirada", pickup.toString()) }
                    item { MetricRow("Delivery", delivery.toString(), "Sem entregador", unassigned.toString()) }
                    item { Button(onClick = { onNavigate(AppScreen.DISPATCH) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir prontos e retiradas") } }
                    item { OutlinedButton(onClick = onScan, modifier = Modifier.fillMaxWidth()) { Text("Ler QR") } }
                }

                if ("cash" in permissions) {
                    item { SectionTitle("Caixa", "Situação financeira do turno") }
                    item { MetricRow("Situação", if (state.cashOpen) "Aberto" else "Fechado", "Esperado", dashboardMoney(state.cashSummary?.expectedCashCents ?: 0), emphasizeB = true) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.CASH) }, modifier = Modifier.fillMaxWidth()) { Text("Ver caixa") } }
                }

                if ("orders_create" in permissions) item { Button(onClick = { onNavigate(AppScreen.POS) }, modifier = Modifier.fillMaxWidth()) { Text("Novo pedido") } }
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar operação") } }
        item { Spacer(Modifier.height(6.dp)) }
    }
}

@Composable
private fun SectionTitle(title: String, subtitle: String) {
    Column(Modifier.padding(top = 4.dp, start = 2.dp, end = 2.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
private fun MetricRow(
    labelA: String,
    valueA: String,
    labelB: String,
    valueB: String,
    emphasizeA: Boolean = false,
    emphasizeB: Boolean = false,
) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
        DashboardMetric(labelA, valueA, Modifier.weight(1f), emphasizeA)
        DashboardMetric(labelB, valueB, Modifier.weight(1f), emphasizeB)
    }
}

@Composable
private fun DashboardMetric(label: String, value: String, modifier: Modifier = Modifier, emphasized: Boolean = false) {
    Card(modifier) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            Text(value, style = MaterialTheme.typography.titleLarge, color = if (emphasized) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
        }
    }
}

@Composable
private fun DashboardCard(title: String, text: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge)
            Text(text, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun DashboardStatusCard(title: String, status: String, active: Boolean) {
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth().padding(17.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(status, color = if (active) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurfaceVariant, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun DashboardInfoPill(text: String, modifier: Modifier = Modifier) {
    Surface(modifier = modifier, color = MaterialTheme.colorScheme.surface, shape = MaterialTheme.shapes.medium) {
        Text(text, modifier = Modifier.padding(horizontal = 12.dp, vertical = 9.dp), color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
    }
}

private fun dashboardModeLabel(mode: AppMode) = when (mode) {
    AppMode.OPERATION -> "Operação"
    AppMode.DELIVERY -> "Delivery"
    AppMode.EVENTS -> "Eventos"
    AppMode.PAY -> "Pay"
}

private fun dashboardUnitName(raw: String?): String {
    val clean = raw?.trim().orEmpty()
    return if (clean.isBlank() || clean.equals("null", ignoreCase = true)) "Unidade principal" else clean
}

private fun firstName(name: String): String = name.trim().substringBefore(' ').ifBlank { "equipe" }
private fun dashboardTime(value: String): String = value.trim().replace('T', ' ').substringAfter(' ', value).take(5).ifBlank { value }

private fun greeting(): String {
    val hour = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
    return when (hour) { in 5..11 -> "Bom dia"; in 12..17 -> "Boa tarde"; else -> "Boa noite" }
}

private fun dashboardMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
