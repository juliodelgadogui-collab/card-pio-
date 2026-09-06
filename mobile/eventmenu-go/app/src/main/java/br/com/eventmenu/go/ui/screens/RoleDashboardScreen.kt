package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text(greeting() + ", ${session.user.name}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("${dashboardModeLabel(mode)} · turno desde ${state.workShift?.startedAt.orEmpty()}")
        }

        when (mode) {
            AppMode.DELIVERY -> {
                val deliveries = state.orders.filter { it.channel == "delivery" && it.status !in setOf("completed", "cancelled") }
                val ready = deliveries.count { it.status == "ready" }
                val route = deliveries.count { it.status == "out_for_delivery" }
                val paid = deliveries.filter { it.paymentStatus == "paid" }.sumOf { it.totalCents }
                item { MetricRow("Pendentes", ready.toString(), "Em andamento", route.toString()) }
                item { MetricRow("Recebidos visíveis", dashboardMoney(paid), "Dinheiro a repassar", dashboardMoney(state.deliveryCash?.outstandingCents ?: 0)) }
                item { MetricRow("PIX/Cartão confirmados", deliveries.count { it.paymentStatus == "paid" }.toString(), "Aguardando pagamento", deliveries.count { it.paymentStatus != "paid" }.toString()) }
                item {
                    val next = deliveries.firstOrNull { it.status == "out_for_delivery" } ?: deliveries.firstOrNull { it.status == "ready" }
                    Button(onClick = { onNavigate(AppScreen.DELIVERY) }, enabled = next != null, modifier = Modifier.fillMaxWidth()) {
                        Text(if (next == null) "SEM ENTREGA PENDENTE" else "PRÓXIMA ENTREGA · #${next.id}")
                    }
                }
            }

            AppMode.EVENTS -> {
                val event = state.events.firstOrNull { it.id == state.selectedEventId } ?: state.events.firstOrNull()
                if (event == null) {
                    item { DashboardCard("Modo Evento", "Nenhum evento disponível para operação.") }
                } else {
                    item { DashboardCard(event.name, event.venue.ifBlank { "Evento selecionado" }) }
                    item { MetricRow("Ingressos", event.ticketsPaid.toString(), "Check-ins", event.ticketsCheckedIn.toString()) }
                    item { MetricRow("Convidados", event.guestsPending.toString(), "Entraram", event.guestsCheckedIn.toString()) }
                    item { MetricRow("Ingressos R$", dashboardMoney(event.ticketRevenueCents), "Bar R$", dashboardMoney(event.barRevenueCents)) }
                }
                if ("tickets" in permissions || "guests" in permissions) {
                    item { Button(onClick = onScan, modifier = Modifier.fillMaxWidth()) { Text("LER INGRESSO / CONVIDADO") } }
                }
                item { OutlinedButton(onClick = { onNavigate(AppScreen.EVENTS) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR OPERAÇÃO DO EVENTO") } }
            }

            AppMode.PAY -> {
                val cash = state.cashSummary
                item { DashboardCard("Caixa financeiro", if (state.cashOpen) "🟢 ABERTO" else "⚪ FECHADO") }
                item { MetricRow("Saldo físico esperado", dashboardMoney(cash?.expectedCashCents ?: 0), "Movimentos", (cash?.movements?.size ?: 0).toString()) }
                val pix = cash?.digital?.filter { it.provider.contains("pag", true) || it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                val cards = cash?.digital?.filterNot { it.provider.contains("pix", true) }?.sumOf { it.totalCents } ?: 0
                item { MetricRow("PIX/digital", dashboardMoney(pix), "Cartões/digital", dashboardMoney(cards)) }
                item { Button(onClick = { onNavigate(AppScreen.CASH) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR CAIXA") } }
                if ("orders_create" in permissions) item { OutlinedButton(onClick = { onNavigate(AppScreen.POS) }, modifier = Modifier.fillMaxWidth()) { Text("NOVO PEDIDO") } }
            }

            AppMode.OPERATION -> {
                val manager = state.managerOverview
                if ("reports" in permissions && manager != null) {
                    item { Text("Visão do gerente", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                    item { MetricRow("Pedidos agora", manager.ordersNow.toString(), "Cozinha atrasada", manager.kitchenDelayed.toString()) }
                    item { MetricRow("Entregadores online", manager.deliveryOnline.toString(), "Caixas abertos", manager.cashOpen.toString()) }
                    item { MetricRow("Faturamento hoje", dashboardMoney(manager.revenueTodayCents), "Pendências", manager.pendingPayments.toString()) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.MANAGER) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR VISÃO GERENCIAL") } }
                }

                if ("orders_kitchen" in permissions) {
                    val fresh = state.kitchenTickets.count { it.status == "confirmed" }
                    val preparing = state.kitchenTickets.count { it.status == "preparing" }
                    item { Text("Cozinha", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                    item { MetricRow("Novos", fresh.toString(), "Preparando", preparing.toString()) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.KITCHEN) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR KDS") } }
                }

                if ("orders_dispatch" in permissions || "delivery_assign" in permissions) {
                    val ready = state.orders.filter { it.status == "ready" }
                    val pickup = ready.count { it.channel in setOf("counter", "pickup") }
                    val delivery = ready.count { it.channel == "delivery" }
                    val unassigned = ready.count { it.channel == "delivery" && it.assignedDeliveryUserId == null }
                    item { Text("Balcão", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                    item { MetricRow("Prontos", ready.size.toString(), "Retirada", pickup.toString()) }
                    item { MetricRow("Delivery pronto", delivery.toString(), "Sem entregador", unassigned.toString()) }
                    item { Button(onClick = { onNavigate(AppScreen.DISPATCH) }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR PRONTOS / RETIRADAS") } }
                    item { OutlinedButton(onClick = onScan, modifier = Modifier.fillMaxWidth()) { Text("LER QR") } }
                }

                if ("cash" in permissions) {
                    item { Text("Caixa", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
                    item { MetricRow("Situação", if (state.cashOpen) "Aberto" else "Fechado", "Esperado", dashboardMoney(state.cashSummary?.expectedCashCents ?: 0)) }
                    item { OutlinedButton(onClick = { onNavigate(AppScreen.CASH) }, modifier = Modifier.fillMaxWidth()) { Text("VER CAIXA") } }
                }

                if ("orders_create" in permissions) {
                    item { Button(onClick = { onNavigate(AppScreen.POS) }, modifier = Modifier.fillMaxWidth()) { Text("NOVO PEDIDO") } }
                }
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR OPERAÇÃO") } }
    }
}

@Composable
private fun MetricRow(labelA: String, valueA: String, labelB: String, valueB: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        DashboardMetric(labelA, valueA, Modifier.weight(1f))
        DashboardMetric(labelB, valueB, Modifier.weight(1f))
    }
}

@Composable
private fun DashboardMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(label)
            Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun DashboardCard(title: String, text: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text(text)
        }
    }
}

private fun dashboardModeLabel(mode: AppMode) = when (mode) {
    AppMode.OPERATION -> "Operação"
    AppMode.DELIVERY -> "Delivery"
    AppMode.EVENTS -> "Eventos"
    AppMode.PAY -> "Pay"
}

private fun greeting(): String {
    val hour = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
    return when (hour) { in 5..11 -> "Bom dia"; in 12..17 -> "Boa tarde"; else -> "Boa noite" }
}

private fun dashboardMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
