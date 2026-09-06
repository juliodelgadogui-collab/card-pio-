package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.TableAccount
import br.com.eventmenu.go.data.TableAccountOrder

@Composable
fun TableAccountScreen(
    account: TableAccount?,
    canReceive: Boolean,
    onReceive: (TableAccountOrder) -> Unit,
    onSplit: (Int) -> Unit,
    onRefresh: () -> Unit,
    onBack: () -> Unit,
) {
    if (account == null) {
        Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Conta da mesa", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Carregando conta...")
            OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) { Text("VOLTAR") }
        }
        return
    }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("${account.table.name} · Comanda #${account.table.tabId}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            if (account.table.tabLabel.isNotBlank()) Text(account.table.tabLabel)
        }

        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Total da comanda")
                        Text(tableAccountMoney(account.totalCents), fontWeight = FontWeight.Bold)
                    }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Já recebido")
                        Text(tableAccountMoney(account.paidCents), fontWeight = FontWeight.Bold)
                    }
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("RESTANTE", fontWeight = FontWeight.Black)
                        Text(tableAccountMoney(account.remainingCents), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                    }
                }
            }
        }

        if (canReceive && account.remainingCents > 0 && account.table.tabId != null) {
            item {
                Button(onClick = { onSplit(account.table.tabId) }, modifier = Modifier.fillMaxWidth()) {
                    Text("DIVIDIR CONTA · PESSOA / PRODUTO / VALOR / %")
                }
            }
        }

        item {
            Text("Pedidos da comanda", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("Cada pagamento permanece ligado ao pedido original para manter estoque, estorno e auditoria corretos.")
        }

        items(account.orders, key = { it.orderId }) { order ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Pedido #${order.orderId}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Text(tableAccountMoney(order.totalCents), fontWeight = FontWeight.Bold)
                    }
                    Text("Operação: ${tableOrderStatus(order.status)}")
                    Text("Pago: ${tableAccountMoney(order.paidCents)}")
                    Text("Restante: ${tableAccountMoney(order.remainingCents)}", fontWeight = FontWeight.Black)
                    if (order.remainingCents <= 0) {
                        Text("✅ PAGAMENTO CONCLUÍDO")
                    } else if (canReceive) {
                        OutlinedButton(onClick = { onReceive(order) }, modifier = Modifier.fillMaxWidth()) { Text("RECEBER SOMENTE ESTE PEDIDO") }
                    } else {
                        Text("Sua função pode consultar a comanda, mas não receber pagamentos.")
                    }
                }
            }
        }

        if (account.orders.isEmpty()) item { Text("A comanda ainda não possui pedidos.") }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR CONTA") } }
        item { OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) { Text("VOLTAR ÀS MESAS") } }
    }
}

private fun tableOrderStatus(status: String) = when (status) {
    "confirmed" -> "Confirmado"
    "preparing" -> "Preparando"
    "ready" -> "Pronto"
    "served" -> "Servido"
    "completed" -> "Finalizado"
    else -> status
}

private fun tableAccountMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
