package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.ManagerReopenCandidate

@Composable
fun ManagerReopenPanel(
    candidates: List<ManagerReopenCandidate>,
    onReopen: (Int, String) -> Unit,
) {
    var selected by remember { mutableStateOf<ManagerReopenCandidate?>(null) }

    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Finalizados recentes", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text(candidates.size.toString(), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        }
        Text("Reabrir não desfaz pagamento nem estorno. O servidor valida a operação novamente antes de mover o pedido para Prontos.")
        if (candidates.isEmpty()) Text("Nenhum pedido finalizado recente nesta unidade.")
        candidates.take(20).forEach { order ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("#${order.id} · ${managerReopenChannel(order.channel)}", fontWeight = FontWeight.Bold)
                        Text(managerReopenMoney(order.totalCents), fontWeight = FontWeight.Black)
                    }
                    Text(order.customerName)
                    if (order.tableName.isNotBlank()) Text("Mesa: ${order.tableName}")
                    Text("Finalizado: ${order.updatedAt}")
                    if (order.eligible) {
                        OutlinedButton(onClick = { selected = order }, modifier = Modifier.fillMaxWidth()) { Text("REABRIR PEDIDO") }
                    } else {
                        Text("🔒 ${order.blockReason.ifBlank { "Pedido não elegível para reabertura." }}")
                    }
                }
            }
        }
    }

    selected?.let { order ->
        var reason by remember(order.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { selected = null },
            title = { Text("Reabrir pedido #${order.id}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("O pedido voltará para Prontos. Pagamento e valores permanecem confirmados.")
                    OutlinedTextField(
                        value = reason,
                        onValueChange = { reason = it.take(500) },
                        label = { Text("Motivo obrigatório") },
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = { selected = null; onReopen(order.id, reason.trim()) },
                    enabled = reason.trim().length >= 5,
                ) { Text("CONFIRMAR REABERTURA") }
            },
            dismissButton = { TextButton(onClick = { selected = null }) { Text("VOLTAR") } },
        )
    }
}

private fun managerReopenChannel(value: String) = when (value) {
    "delivery" -> "Delivery"
    "pickup" -> "Retirada"
    "table" -> "Mesa"
    "counter" -> "Balcão"
    "bar" -> "Bar"
    else -> value
}

private fun managerReopenMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
