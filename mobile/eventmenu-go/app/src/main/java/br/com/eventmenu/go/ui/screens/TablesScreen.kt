package br.com.eventmenu.go.ui.screens

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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.RestaurantTable

@Composable
fun TablesScreen(
    tables: List<RestaurantTable>,
    canCreateOrder: Boolean,
    onRefresh: () -> Unit,
    onOpen: (Int, String) -> Unit,
    onClose: (Int) -> Unit,
    onOrder: (RestaurantTable) -> Unit,
) {
    var opening by remember { mutableStateOf<RestaurantTable?>(null) }
    var closing by remember { mutableStateOf<RestaurantTable?>(null) }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Mesas e comandas", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("O saldo considera pagamentos parciais já confirmados pelo servidor.")
        }
        items(tables, key = { it.id }) { table ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column {
                            Text(table.name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            Text("${table.seats} lugares · ${statusLabel(table.status, table.tabId != null)}")
                        }
                        if (table.tabId != null) Text("Comanda #${table.tabId}", fontWeight = FontWeight.Bold)
                    }

                    if (table.tabId != null) {
                        if (table.tabLabel.isNotBlank()) Text(table.tabLabel)
                        Text("Total da comanda: ${tableMoney(table.tabTotalCents)}")
                        Text("Saldo a receber: ${tableMoney(table.unpaidCents)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            if (canCreateOrder) Button(onClick = { onOrder(table) }, modifier = Modifier.weight(1f)) { Text("LANÇAR PEDIDO") }
                            OutlinedButton(onClick = { closing = table }, modifier = Modifier.weight(1f)) { Text("FECHAR") }
                        }
                    } else if (table.status != "inactive") {
                        Button(onClick = { opening = table }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR COMANDA") }
                    } else {
                        Text("Mesa inativa.")
                    }
                }
            }
        }
        if (tables.isEmpty()) item { Text("Nenhuma mesa cadastrada para esta empresa.") }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR SALÃO") } }
    }

    opening?.let { table ->
        var label by remember(table.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { opening = null },
            title = { Text("Abrir ${table.name}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("A comanda ficará vinculada a esta mesa até o fechamento seguro.")
                    OutlinedTextField(label, { label = it.take(120) }, label = { Text("Nome/identificação (opcional)") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = { Button(onClick = { opening = null; onOpen(table.id, label) }) { Text("ABRIR") } },
            dismissButton = { TextButton(onClick = { opening = null }) { Text("CANCELAR") } },
        )
    }

    closing?.let { table ->
        AlertDialog(
            onDismissRequest = { closing = null },
            title = { Text("Fechar ${table.name}?") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Saldo atual: ${tableMoney(table.unpaidCents)}")
                    Text("O servidor bloqueará o fechamento se existir pedido não pago ou ainda em operação.")
                }
            },
            confirmButton = { Button(onClick = { closing = null; table.tabId?.let(onClose) }) { Text("CONFIRMAR") } },
            dismissButton = { TextButton(onClick = { closing = null }) { Text("VOLTAR") } },
        )
    }
}

private fun statusLabel(status: String, hasTab: Boolean): String = when {
    status == "inactive" -> "Inativa"
    hasTab -> "Ocupada"
    else -> "Disponível"
}

private fun tableMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
