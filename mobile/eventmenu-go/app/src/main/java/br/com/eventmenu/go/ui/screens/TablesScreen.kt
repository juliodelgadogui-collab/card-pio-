package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
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
    onAccount: (RestaurantTable) -> Unit,
) {
    var opening by remember { mutableStateOf<RestaurantTable?>(null) }
    var closing by remember { mutableStateOf<RestaurantTable?>(null) }

    Column(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Mesas", style = MaterialTheme.typography.headlineMedium)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TableLegend("●", "Livre", MaterialTheme.colorScheme.secondary, Modifier.weight(1f))
            TableLegend("●", "Ocupada", MaterialTheme.colorScheme.primary, Modifier.weight(1f))
            TableLegend("●", "Inativa", MaterialTheme.colorScheme.error, Modifier.weight(1f))
        }
        if (tables.isEmpty()) {
            Card(Modifier.fillMaxWidth()) { Text("Nenhuma mesa cadastrada para esta unidade.", modifier = Modifier.padding(18.dp)) }
        } else {
            LazyVerticalGrid(
                columns = GridCells.Adaptive(112.dp),
                modifier = Modifier.weight(1f),
                horizontalArrangement = Arrangement.spacedBy(9.dp),
                verticalArrangement = Arrangement.spacedBy(9.dp),
            ) {
                items(tables, key = { it.id }) { table ->
                    val occupied = table.tabId != null
                    val inactive = table.status == "inactive"
                    val tone = when { inactive -> MaterialTheme.colorScheme.error; occupied -> MaterialTheme.colorScheme.primary; else -> MaterialTheme.colorScheme.secondary }
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                        onClick = {
                            when {
                                inactive -> Unit
                                occupied -> onAccount(table)
                                else -> opening = table
                            }
                        },
                    ) {
                        Column(
                            Modifier.padding(horizontal = 10.dp, vertical = 13.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            Text(if (inactive) "⛔" else "🍽️", style = MaterialTheme.typography.titleLarge)
                            Text(table.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
                            Text(statusLabel(table.status, occupied), color = tone, style = MaterialTheme.typography.labelLarge)
                            if (occupied) {
                                Text(tableMoney(table.unpaidCents), fontWeight = FontWeight.Bold)
                                if (canCreateOrder) TextButton(onClick = { onOrder(table) }) { Text("+ Pedido") }
                                TextButton(onClick = { closing = table }) { Text("Fechar") }
                            }
                        }
                    }
                }
            }
        }
        OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar salão") }
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
            confirmButton = { Button(onClick = { opening = null; onOpen(table.id, label) }) { Text("Abrir") } },
            dismissButton = { TextButton(onClick = { opening = null }) { Text("Cancelar") } },
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
            confirmButton = { Button(onClick = { closing = null; table.tabId?.let(onClose) }) { Text("Confirmar") } },
            dismissButton = { TextButton(onClick = { closing = null }) { Text("Voltar") } },
        )
    }
}

@Composable
private fun TableLegend(symbol: String, label: String, color: androidx.compose.ui.graphics.Color, modifier: Modifier = Modifier) {
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(symbol, color = color)
        Text(label, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

private fun statusLabel(status: String, hasTab: Boolean): String = when {
    status == "inactive" -> "Inativa"
    hasTab -> "Ocupada"
    else -> "Livre"
}

private fun tableMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
