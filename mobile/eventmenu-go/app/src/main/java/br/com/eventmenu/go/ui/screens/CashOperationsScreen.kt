package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.CashSummary
import br.com.eventmenu.go.ui.theme.EventMenuUi

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

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(EventMenuUi.SpaceMd),
        verticalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceMd),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text("Caixa", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    Text(if (open) "Controle do dinheiro deste turno" else "Abra o caixa para começar a receber em dinheiro", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                CashStatusPill(open)
            }
        }

        if (open && session != null && summary != null) {
            item {
                CashHeroCard(summary)
            }

            item {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Ações do caixa", style = MaterialTheme.typography.titleLarge)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(EventMenuUi.SpaceSm)) {
                        Button(onClick = { supplyDialog = true }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.AddCircleOutline, contentDescription = null)
                            Spacer(Modifier.width(7.dp))
                            Text("Suprimento")
                        }
                        OutlinedButton(onClick = { withdrawalDialog = true }, modifier = Modifier.weight(1f).heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.RemoveCircleOutline, contentDescription = null)
                            Spacer(Modifier.width(7.dp))
                            Text("Sangria")
                        }
                    }
                }
            }

            if (summary.digital.isNotEmpty()) {
                val digitalTotal = summary.digital.sumOf { it.totalCents }
                val digitalQty = summary.digital.sumOf { it.qty }
                item {
                    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                        Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium) {
                                Icon(Icons.Default.CreditCard, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(10.dp))
                            }
                            Column(Modifier.weight(1f)) {
                                Text("Pagamentos digitais", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                Text("$digitalQty recebimento(s) registrados automaticamente", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                            }
                            Text(cashMoney(digitalTotal), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        }
                    }
                }
            }

            if (summary.movements.isNotEmpty()) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("Movimentos recentes", style = MaterialTheme.typography.titleLarge)
                        Text("Entradas e saídas registradas neste caixa.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                    }
                }
                items(summary.movements.take(40), key = { it.id }) { movement ->
                    val incoming = movement.direction == "in"
                    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                        Row(Modifier.fillMaxWidth().padding(15.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Surface(
                                color = if (incoming) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant,
                                shape = MaterialTheme.shapes.small,
                            ) {
                                Icon(
                                    if (incoming) Icons.Default.SouthWest else Icons.Default.NorthEast,
                                    contentDescription = null,
                                    tint = if (incoming) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.padding(9.dp),
                                )
                            }
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text(movementLabel(movement.type), fontWeight = FontWeight.Bold)
                                Text("${methodLabel(movement.method)} · ${if (incoming) "Entrada" else "Saída"}", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                                usefulCashText(movement.notes)?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                                friendlyCashDateTime(movement.createdAt)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall) }
                            }
                            Text(
                                (if (incoming) "+ " else "− ") + cashMoney(movement.amountCents),
                                fontWeight = FontWeight.Black,
                                color = if (incoming) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurface,
                            )
                        }
                    }
                }
            } else {
                item {
                    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                        Column(Modifier.fillMaxWidth().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Icon(Icons.Default.ReceiptLong, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text("Nenhum movimento ainda", fontWeight = FontWeight.Bold)
                            Text("Vendas, suprimentos e sangrias aparecerão aqui.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }

            item {
                Button(onClick = { closeDialog = true }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                    Icon(Icons.Default.Lock, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Conferir e fechar caixa")
                }
            }
        } else {
            item {
                Card(
                    Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                ) {
                    Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium) {
                            Icon(Icons.Default.AccountBalanceWallet, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(12.dp))
                        }
                        Text("Nenhum caixa aberto", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("O caixa é necessário para recebimentos em dinheiro. PIX e cartão continuam sendo registrados automaticamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { openingDialog = true }, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.ActionHeight)) {
                            Icon(Icons.Default.LockOpen, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Abrir caixa")
                        }
                    }
                }
            }

            if (session?.status == "closed") {
                item {
                    val difference = session.differenceCents ?: 0
                    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Último fechamento", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                            CashValueRow("Esperado", cashMoney(session.expectedCashCents ?: summary?.expectedCashCents ?: 0))
                            CashValueRow("Contado", cashMoney(session.closingCashCents ?: 0))
                            HorizontalDivider()
                            CashValueRow("Diferença", cashMoney(difference), emphasize = difference != 0)
                            friendlyCashDateTime(session.closedAt)?.let { Text("Fechado em $it", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium) }
                        }
                    }
                }
            }
        }

        item {
            OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth().heightIn(min = EventMenuUi.TouchTarget)) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("Atualizar caixa")
            }
        }
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
private fun CashHeroCard(summary: CashSummary) {
    val session = summary.session ?: return
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
    ) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Dinheiro esperado", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
            Text(cashMoney(summary.expectedCashCents), style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black, color = MaterialTheme.colorScheme.onPrimaryContainer)
            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.7f))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                Column(Modifier.weight(1f)) {
                    Text("Fundo inicial", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(cashMoney(session.openingCashCents), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                }
                Column(Modifier.weight(1f)) {
                    Text("Aberto", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(friendlyCashDateTime(session.openedAt) ?: "Neste turno", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun CashStatusPill(open: Boolean) {
    Surface(
        color = if (open) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.small,
    ) {
        Text(
            if (open) "Aberto" else "Fechado",
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            color = if (open) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun CashValueRow(label: String, value: String, emphasize: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, fontWeight = FontWeight.Bold, color = if (emphasize) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface)
    }
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
