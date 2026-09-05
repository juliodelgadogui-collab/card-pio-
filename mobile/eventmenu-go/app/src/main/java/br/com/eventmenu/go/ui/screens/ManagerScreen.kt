package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.ManagerAlert
import br.com.eventmenu.go.data.ManagerOverview

@Composable
fun ManagerScreen(
    overview: ManagerOverview?,
    onRefresh: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Painel do gerente", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Visão rápida da operação. Configurações e relatórios completos continuam no painel web.")
        }

        if (overview == null) {
            item { Text("Carregando indicadores...") }
        } else {
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ManagerMetric("Pedidos agora", overview.ordersNow.toString(), Modifier.weight(1f))
                    ManagerMetric("Cozinha atrasada", overview.kitchenDelayed.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ManagerMetric("Prontos", overview.readyOrders.toString(), Modifier.weight(1f))
                    ManagerMetric("Sem entregador", overview.unassignedDelivery.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ManagerMetric("Entregadores online", overview.deliveryOnline.toString(), Modifier.weight(1f))
                    ManagerMetric("Caixas abertos", overview.cashOpen.toString(), Modifier.weight(1f))
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ManagerMetric("Pagamentos pendentes", overview.pendingPayments.toString(), Modifier.weight(1f))
                    ManagerMetric("Faturamento hoje", managerMoney(overview.revenueTodayCents), Modifier.weight(1f))
                }
            }

            item { Text("Alertas operacionais", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            items(overview.alerts) { alert -> ManagerAlertCard(alert) }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR PAINEL") } }
    }
}

@Composable
private fun ManagerMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier) {
        Column(Modifier.padding(16.dp)) {
            Text(label)
            Text(value, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun ManagerAlertCard(alert: ManagerAlert) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(
                when (alert.level) {
                    "warning" -> "⚠️ ${alert.title}"
                    "ok" -> "✅ ${alert.title}"
                    else -> "ℹ️ ${alert.title}"
                },
                fontWeight = FontWeight.Bold,
            )
            Text(alert.message)
        }
    }
}

private fun managerMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
