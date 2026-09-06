package br.com.eventmenu.go.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.TabSplitPaymentState
import kotlinx.coroutines.delay

@Composable
fun TabSplitPaymentScreen(
    state: TabSplitPaymentState,
    cashOpen: Boolean,
    canCash: Boolean,
    canPix: Boolean,
    canNfc: Boolean,
    onValue: (String, Int, String) -> Unit,
    onPercentage: (String, Double, String) -> Unit,
    onPerson: (String, Int, String) -> Unit,
    onProducts: (String, Set<Int>, String) -> Unit,
    onPollPix: () -> Unit,
    onHidePix: () -> Unit,
    onShowPix: () -> Unit,
    onCancelGroup: () -> Unit,
    onRefresh: () -> Unit,
    onBack: () -> Unit,
) {
    val account = state.account
    if (account == null) {
        Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Dividir conta", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Carregando saldo da comanda...")
            OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) { Text("VOLTAR") }
        }
        return
    }

    var split by remember { mutableStateOf("value") }
    var method by remember { mutableStateOf(if (canCash) "cash" else if (canPix) "pix" else "nfc") }
    var valueText by remember(account.remainingCents) { mutableStateOf("%.2f".format(account.remainingCents / 100.0).replace('.', ',')) }
    var percentageText by remember { mutableStateOf("50") }
    var peopleText by remember { mutableStateOf("2") }
    var taxId by remember { mutableStateOf("") }
    var selectedItems by remember(account.tabId) { mutableStateOf(setOf<Int>()) }

    val valueCents = ((valueText.replace(',', '.').toDoubleOrNull() ?: 0.0) * 100).toInt()
    val percentage = percentageText.replace(',', '.').toDoubleOrNull() ?: 0.0
    val people = peopleText.toIntOrNull() ?: 0
    val productPreview = account.items.filter { it.orderItemId in selectedItems }.sumOf { it.totalCents }
    val preview = when (split) {
        "value" -> valueCents
        "percentage" -> if (percentage in 0.0..100.0) (account.remainingCents * (percentage / 100.0)).toInt() else 0
        "person" -> if (people > 0) (account.remainingCents + people - 1) / people else 0
        else -> productPreview
    }
    val active = state.group?.status in setOf("created", "pending")
    val methodAllowed = when (method) {
        "cash" -> canCash && cashOpen
        "pix" -> canPix && taxId.length in setOf(11, 14)
        "nfc" -> canNfc
        else -> false
    }
    val splitValid = when (split) {
        "value" -> valueCents in 1..account.remainingCents
        "percentage" -> percentage > 0 && percentage <= 100
        "person" -> people in 1..100
        "product" -> selectedItems.isNotEmpty()
        else -> false
    }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("Dividir conta · ${account.tableName}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            if (account.tabLabel.isNotBlank()) Text(account.tabLabel)
            Text("Comanda #${account.tabId}")
        }
        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Total"); Text(splitMoney(account.totalCents), fontWeight = FontWeight.Bold) }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("Pago"); Text(splitMoney(account.paidCents), fontWeight = FontWeight.Bold) }
                    HorizontalDivider()
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text("SALDO", fontWeight = FontWeight.Black); Text(splitMoney(account.remainingCents), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black) }
                }
            }
        }

        state.group?.let { group ->
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Grupo #${group.id} · ${splitMethodLabel(group.method)}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Text("Divisão: ${splitTypeLabel(group.splitType)}")
                        Text("Valor: ${splitMoney(group.amountCents)}")
                        Text("Status: ${groupStatusLabel(group.status)}", fontWeight = FontWeight.Bold)
                        group.allocations.forEach { allocation ->
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text("Pedido #${allocation.orderId}")
                                Text(splitMoney(allocation.amountCents), fontWeight = FontWeight.Bold)
                            }
                        }
                        if (group.method == "pix" && group.status == "pending" && state.pix != null) {
                            OutlinedButton(onClick = onShowPix, modifier = Modifier.fillMaxWidth()) { Text("MOSTRAR PIX") }
                        }
                        if (group.status == "created" && group.providerPaymentId.isBlank()) {
                            OutlinedButton(onClick = onCancelGroup, modifier = Modifier.fillMaxWidth()) { Text("CANCELAR ESTA DIVISÃO") }
                        }
                        if (group.status == "attention") Text("⚠️ O provedor confirmou o valor, mas a operação exige conferência administrativa.")
                    }
                }
            }
        }

        if (!active && account.remainingCents > 0) {
            item { Text("Como deseja dividir?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            item {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                    item { FilterChip(selected = split == "value", onClick = { split = "value" }, label = { Text("Valor") }) }
                    item { FilterChip(selected = split == "percentage", onClick = { split = "percentage" }, label = { Text("Percentual") }) }
                    item { FilterChip(selected = split == "person", onClick = { split = "person" }, label = { Text("Pessoa") }) }
                    item { FilterChip(selected = split == "product", onClick = { split = "product" }, label = { Text("Produto") }) }
                }
            }

            when (split) {
                "value" -> item { OutlinedTextField(valueText, { valueText = it }, label = { Text("Valor desta parte (R$)") }, singleLine = true, modifier = Modifier.fillMaxWidth()) }
                "percentage" -> item { OutlinedTextField(percentageText, { percentageText = it }, label = { Text("Percentual do saldo atual") }, suffix = { Text("%") }, singleLine = true, modifier = Modifier.fillMaxWidth()) }
                "person" -> item {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        OutlinedTextField(peopleText, { peopleText = it.filter(Char::isDigit).take(3) }, label = { Text("Pessoas que ainda vão dividir") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                        Text("Cada operação paga uma cota do saldo atual. Depois, informe a quantidade de pessoas que ainda faltam.")
                    }
                }
                "product" -> {
                    item { Text("Selecione linhas inteiras de produto. Uma linha já usada numa divisão por produto não pode ser usada novamente.") }
                    items(account.items, key = { it.orderItemId }) { item ->
                        val checked = item.orderItemId in selectedItems
                        Card(Modifier.fillMaxWidth()) {
                            Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Checkbox(
                                    checked = checked,
                                    enabled = !item.splitUsed,
                                    onCheckedChange = { selectedItems = if (it) selectedItems + item.orderItemId else selectedItems - item.orderItemId },
                                )
                                Column(Modifier.weight(1f)) {
                                    Text(item.name, fontWeight = FontWeight.Bold)
                                    Text("Pedido #${item.orderId} · ${item.quantity} un.")
                                    if (item.splitUsed) Text("Já utilizado em outra divisão por produto")
                                }
                                Text(splitMoney(item.totalCents), fontWeight = FontWeight.Black)
                            }
                        }
                    }
                }
            }

            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Text("Prévia desta parte", fontWeight = FontWeight.Bold)
                        Text(splitMoney(preview.coerceAtLeast(0)), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                        Text("A API recalcula e distribui o valor novamente no momento do pagamento.")
                    }
                }
            }

            item { Text("Como receber?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            item {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                    if (canCash) item { FilterChip(selected = method == "cash", onClick = { method = "cash" }, label = { Text("Dinheiro") }) }
                    if (canPix) item { FilterChip(selected = method == "pix", onClick = { method = "pix" }, label = { Text("PIX") }) }
                    if (canNfc) item { FilterChip(selected = method == "nfc", onClick = { method = "nfc" }, label = { Text("NFC") }) }
                }
            }
            if (method == "cash" && !cashOpen) item { Text("Abra o caixa financeiro antes de receber dinheiro.") }
            if (method == "pix") item {
                OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF/CNPJ para emitir o PIX") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            }
            item {
                Button(
                    onClick = {
                        when (split) {
                            "value" -> onValue(method, valueCents, taxId)
                            "percentage" -> onPercentage(method, percentage, taxId)
                            "person" -> onPerson(method, people, taxId)
                            else -> onProducts(method, selectedItems, taxId)
                        }
                    },
                    enabled = splitValid && methodAllowed,
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("RECEBER ${splitMoney(preview.coerceAtLeast(0))}") }
            }
        }

        if (account.remainingCents <= 0) item { Text("✅ COMANDA INTEGRALMENTE PAGA", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR SALDO") } }
        item { OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) { Text("VOLTAR À CONTA") } }
    }

    if (state.pixVisible) state.pix?.let { charge -> SplitPixDialog(charge.copyPaste, charge.amountCents, charge.expiresAt, onPollPix, onHidePix) }
}

@Composable
private fun SplitPixDialog(copyPaste: String, amountCents: Int, expiresAt: String, onPoll: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val bitmap = remember(copyPaste) { qrBitmap(copyPaste) }
    LaunchedEffect(copyPaste) { while (true) { delay(2500); onPoll() } }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("PIX da comanda · ${splitMoney(amountCents)}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                bitmap?.let { Image(it.asImageBitmap(), contentDescription = "PIX da conta dividida", modifier = Modifier.fillMaxWidth()) }
                Text("⏳ Aguardando confirmação direta do PagBank…")
                if (expiresAt.isNotBlank()) Text("Validade: $expiresAt")
                OutlinedButton(onClick = { copySplitPix(context, copyPaste) }, modifier = Modifier.fillMaxWidth()) { Text("COPIAR PIX") }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )
}

private fun copySplitPix(context: Context, text: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("PIX EventMenu Comanda", text))
}
private fun splitMethodLabel(value: String) = when (value) { "cash" -> "Dinheiro"; "pix" -> "PIX"; "nfc" -> "Cartão NFC"; else -> value }
private fun splitTypeLabel(value: String) = when (value) { "value" -> "Por valor"; "percentage" -> "Por percentual"; "person" -> "Por pessoa"; "product" -> "Por produto"; else -> value }
private fun groupStatusLabel(value: String) = when (value) { "created" -> "Aguardando cobrança"; "pending" -> "Processando no provedor"; "paid" -> "Pago"; "attention" -> "Atenção"; "failed" -> "Falhou"; "cancelled" -> "Cancelado"; "refunded" -> "Estornado"; else -> value }
private fun splitMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
