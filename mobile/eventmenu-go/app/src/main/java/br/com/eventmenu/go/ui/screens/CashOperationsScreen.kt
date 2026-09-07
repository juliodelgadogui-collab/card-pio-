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
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import br.com.eventmenu.go.data.CashSummary

@Composable
fun CashOperationsScreen(
    open: Boolean,
    summary: CashSummary?,
    onOpen: (Int, String) -> Unit,
    onSupply: (Int, String) -> Unit,
    onWithdrawal: (Int, String) -> Unit,
    onClose: (Int, String) -> Unit,
    onRefresh: () -> Unit,
) {
    var openingDialog by remember { mutableStateOf(false) }
    var supplyDialog by remember { mutableStateOf(false) }
    var withdrawalDialog by remember { mutableStateOf(false) }
    var closeDialog by remember { mutableStateOf(false) }
    val session = summary?.session

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Caixa", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Surface(
                color = if (open) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant,
                shape = MaterialTheme.shapes.small,
            ) {
                Text(
                    if (open) "Aberto" else "Fechado",
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                    color = if (open) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }

        if (open && session != null && summary != null) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Caixa aberto", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        friendlyCashDateTime(session.openedAt)?.let { Text("Iniciado em $it", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        Text("Fundo inicial: ${cashMoney(session.openingCashCents)}")
                        HorizontalDivider()
                        Text("Dinheiro esperado", fontWeight = FontWeight.Bold)
                        Text(cashMoney(summary.expectedCashCents), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    }
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { supplyDialog = true }, modifier = Modifier.weight(1f)) { Text("Suprimento") }
                    OutlinedButton(onClick = { withdrawalDialog = true }, modifier = Modifier.weight(1f)) { Text("Sangria") }
                }
            }

            if (summary.digital.isNotEmpty()) {
                val digitalTotal = summary.digital.sumOf { it.totalCents }
                val digitalQty = summary.digital.sumOf { it.qty }
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                            Column {
                                Text("Pagamentos digitais", fontWeight = FontWeight.Bold)
                                Text("$digitalQty recebimento(s)", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            Text(cashMoney(digitalTotal), fontWeight = FontWeight.Black)
                        }
                    }
                }
            }

            if (summary.movements.isNotEmpty()) {
                item { Text("Movimentos", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
                items(summary.movements.take(40), key = { it.id }) { movement ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                            Column(Modifier.weight(1f)) {
                                Text(movementLabel(movement.type), fontWeight = FontWeight.Bold)
                                Text("${methodLabel(movement.method)} · ${if (movement.direction == "in") "Entrada" else "Saída"}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                                usefulCashText(movement.notes)?.let { Text(it) }
                                friendlyCashDateTime(movement.createdAt)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall) }
                            }
                            Text((if (movement.direction == "in") "+ " else "− ") + cashMoney(movement.amountCents), fontWeight = FontWeight.Black)
                        }
                    }
                }
            }

            item { Button(onClick = { closeDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("Conferir e fechar caixa") } }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Nenhum caixa aberto", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Abra o caixa para receber em dinheiro. PIX e cartão são registrados automaticamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { openingDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("Abrir caixa") }
                    }
                }
            }

            if (session?.status == "closed") {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Text("Último fechamento", fontWeight = FontWeight.Bold)
                            Text("Esperado: ${cashMoney(session.expectedCashCents ?: summary?.expectedCashCents ?: 0)}")
                            Text("Contado: ${cashMoney(session.closingCashCents ?: 0)}")
                            Text("Diferença: ${cashMoney(session.differenceCents ?: 0)}", fontWeight = FontWeight.SemiBold)
                            friendlyCashDateTime(session.closedAt)?.let { Text("Fechado em $it", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        }
                    }
                }
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") } }
    }

    if (openingDialog) MoneyDialog("Abrir caixa", "Fundo inicial (R$)", "Observação (opcional)", false, onDismiss = { openingDialog = false }, onConfirm = { amount, notes -> openingDialog = false; onOpen(amount, notes) })
    if (supplyDialog) MoneyDialog("Suprimento", "Valor que entrou (R$)", "Origem ou observação (opcional)", false, onDismiss = { supplyDialog = false }, onConfirm = { amount, notes -> supplyDialog = false; onSupply(amount, notes) })
    if (withdrawalDialog) MoneyDialog("Sangria", "Valor retirado (R$)", "Motivo da sangria", true, onDismiss = { withdrawalDialog = false }, onConfirm = { amount, notes -> withdrawalDialog = false; onWithdrawal(amount, notes) })
    if (closeDialog) MoneyDialog(
        "Conferir fechamento",
        "Dinheiro contado (R$)",
        "Observação (opcional)",
        false,
        hint = "Valor esperado: ${cashMoney(summary?.expectedCashCents ?: 0)}. Informe quanto foi realmente contado.",
        onDismiss = { closeDialog = false },
        onConfirm = { amount, notes -> closeDialog = false; onClose(amount, notes) },
    )
}

@Composable
private fun MoneyDialog(
    title: String,
    valueLabel: String,
    notesLabel: String,
    requireNotes: Boolean,
    hint: String = "",
    onDismiss: () -> Unit,
    onConfirm: (Int, String) -> Unit,
) {
    var value by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val cents = ((value.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (hint.isNotBlank()) Text(hint, color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedTextField(value, { value = it }, label = { Text(valueLabel) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(notes, { notes = it.take(500) }, label = { Text(notesLabel) }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            Button(
                onClick = { onConfirm(cents, notes.trim()) },
                enabled = cents >= 0 && (!requireNotes || notes.isNotBlank()) && (title == "Abrir caixa" || title == "Conferir fechamento" || cents > 0),
            ) { Text("Confirmar") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } },
    )
}

private fun movementLabel(type: String) = when (type) {
    "sale" -> "Venda"
    "supply" -> "Suprimento"
    "withdrawal" -> "Sangria"
    "refund" -> "Estorno"
    "delivery_handoff" -> "Repasse de entrega"
    "adjustment" -> "Ajuste"
    else -> "Movimento"
}

private fun methodLabel(method: String) = when (method) {
    "cash" -> "Dinheiro"
    "pix" -> "PIX"
    "card" -> "Cartão"
    else -> "Outro"
}

private fun usefulCashText(value: String?): String? {
    val clean = value?.trim().orEmpty()
    return clean.takeIf { it.isNotBlank() && !it.equals("null", true) && !it.equals("undefined", true) }
}

private fun friendlyCashDateTime(value: String?): String? {
    val clean = usefulCashText(value) ?: return null
    val normalized = clean.replace('T', ' ')
    val date = normalized.substringBefore(' ')
    val time = normalized.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val formattedDate = if (parts.size == 3) "${parts[2]}/${parts[1]}/${parts[0]}" else date
    return when {
        formattedDate.isNotBlank() && time.isNotBlank() -> "$formattedDate às $time"
        time.isNotBlank() -> time
        else -> formattedDate
    }
}

private fun cashMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
