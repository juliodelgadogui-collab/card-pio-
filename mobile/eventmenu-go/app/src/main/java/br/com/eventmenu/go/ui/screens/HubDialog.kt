package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import br.com.eventmenu.go.HubState
import br.com.eventmenu.go.data.HubLink

@Composable
fun HubDialog(
    state: HubState,
    canPrintOrder: Boolean,
    canPrintReceipt: Boolean,
    canShowCustomer: Boolean,
    canSendAlert: Boolean,
    canTerminalRequest: Boolean,
    canOpenDrawer: Boolean,
    onDismiss: () -> Unit,
    onRefresh: () -> Unit,
    onSelectLink: (Int) -> Unit,
    onSelectTerminal: (Int) -> Unit,
    onLoadTerminals: () -> Unit,
    onPairCode: (String, String) -> Unit,
    onScanPairing: () -> Unit,
    onPrintOrder: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onOpenDrawer: (String) -> Unit,
    onShowCustomer: (Int) -> Unit,
    onChargeTef: (Int, Int, String, Int) -> Unit,
    onAlert: (String) -> Unit,
    onRevoke: () -> Unit,
) {
    var pairingCode by remember { mutableStateOf("") }
    var orderText by remember { mutableStateOf("") }
    var amountText by remember { mutableStateOf("") }
    var paymentType by remember { mutableStateOf("debit") }
    var installmentsText by remember { mutableStateOf("1") }
    var alertText by remember { mutableStateOf("") }
    val hasOrderAction = canPrintOrder || canPrintReceipt || canShowCustomer || canTerminalRequest
    val hasHardwareAction = hasOrderAction || canOpenDrawer || canSendAlert
    val commandEnabled = !state.loading && !state.commandBusy

    LaunchedEffect(Unit) { onRefresh() }
    LaunchedEffect(state.selectedLinkId, canTerminalRequest) {
        if (canTerminalRequest && state.selected != null) onLoadTerminals()
    }

    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Surface(
            modifier = Modifier.fillMaxSize().padding(12.dp),
            shape = MaterialTheme.shapes.extraLarge,
            tonalElevation = 3.dp,
        ) {
            Column(Modifier.fillMaxSize().padding(18.dp)) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text("Hub da unidade", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold)
                        Text("Celular conectado aos equipamentos do EventMenu Desktop", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    TextButton(onClick = onDismiss) { Text("Fechar") }
                }

                Spacer(Modifier.height(12.dp))

                LazyColumn(
                    modifier = Modifier.fillMaxHeight(),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    item {
                        SectionTitle("Computador e equipamentos")
                        if (state.links.isEmpty() && !state.loading) {
                            Text("Nenhum computador vinculado a este celular.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }

                    items(state.links, key = { it.id }) { link ->
                        HubComputerCard(
                            link = link,
                            selected = state.selected?.id == link.id,
                            enabled = !state.commandBusy,
                            onSelect = { onSelectLink(link.id) },
                        )
                    }

                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = onRefresh, enabled = !state.loading) { Text("Atualizar") }
                            Button(onClick = onScanPairing, enabled = commandEnabled) { Text("Ler QR do computador") }
                        }
                    }

                    item {
                        OutlinedTextField(
                            value = pairingCode,
                            onValueChange = { pairingCode = it },
                            modifier = Modifier.fillMaxWidth(),
                            enabled = commandEnabled,
                            label = { Text("Código de pareamento") },
                            supportingText = { Text("Use somente se não conseguir ler o QR exibido no computador.") },
                            singleLine = true,
                        )
                        Button(
                            onClick = { if (pairingCode.isNotBlank()) onPairCode(pairingCode, "Celular EventMenu GO") },
                            enabled = pairingCode.isNotBlank() && commandEnabled,
                        ) { Text("Vincular") }
                    }

                    if (state.selected != null && hasHardwareAction) {
                        item { HorizontalDivider(); SectionTitle("Ações no computador") }

                        if (hasOrderAction) {
                            item {
                                OutlinedTextField(
                                    value = orderText,
                                    onValueChange = { orderText = it.filter(Char::isDigit) },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = !state.commandBusy,
                                    label = { Text("Número do pedido") },
                                    singleLine = true,
                                )
                            }
                        }

                        if (canPrintOrder || canPrintReceipt || canShowCustomer) {
                            item {
                                val orderId = orderText.toIntOrNull() ?: 0
                                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                    if (canPrintOrder || canPrintReceipt) {
                                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                            if (canPrintOrder) {
                                                OutlinedButton(
                                                    onClick = { onPrintOrder(orderId) },
                                                    enabled = orderId > 0 && state.selected?.online == true && commandEnabled,
                                                ) { Text("Imprimir pedido") }
                                            }
                                            if (canPrintReceipt) {
                                                OutlinedButton(
                                                    onClick = { onPrintReceipt(orderId) },
                                                    enabled = orderId > 0 && state.selected?.online == true && commandEnabled,
                                                ) { Text("Imprimir recibo") }
                                            }
                                        }
                                    }
                                    if (canShowCustomer) {
                                        OutlinedButton(
                                            onClick = { onShowCustomer(orderId) },
                                            enabled = orderId > 0 && state.selected?.online == true && state.selected?.hardware?.customerDisplay?.isNotBlank() == true && commandEnabled,
                                        ) { Text("Mostrar para o cliente") }
                                    }
                                }
                            }
                        }

                        if (canTerminalRequest) {
                            item { SectionTitle("Cobrar no PINPad") }
                            if (state.terminals.isEmpty()) {
                                item { Text("Nenhum PINPad ativo nesta unidade.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            } else {
                                items(state.terminals, key = { "terminal-${it.id}" }) { terminal ->
                                    FilterChip(
                                        selected = state.selectedTerminal?.id == terminal.id,
                                        onClick = { onSelectTerminal(terminal.id) },
                                        enabled = !state.commandBusy,
                                        label = { Text(if (terminal.pinpad.isBlank()) terminal.label else "${terminal.label} • ${terminal.pinpad}") },
                                    )
                                }
                            }
                            item {
                                OutlinedTextField(
                                    value = amountText,
                                    onValueChange = { amountText = it.take(12) },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = !state.commandBusy,
                                    label = { Text("Valor") },
                                    prefix = { Text("R$ ") },
                                    singleLine = true,
                                )
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    FilterChip(selected = paymentType == "debit", onClick = { paymentType = "debit" }, enabled = !state.commandBusy, label = { Text("Débito") })
                                    FilterChip(selected = paymentType == "credit", onClick = { paymentType = "credit" }, enabled = !state.commandBusy, label = { Text("Crédito") })
                                    FilterChip(selected = paymentType == "pix", onClick = { paymentType = "pix" }, enabled = !state.commandBusy, label = { Text("Pix") })
                                }
                                if (paymentType == "credit") {
                                    OutlinedTextField(
                                        value = installmentsText,
                                        onValueChange = { installmentsText = it.filter(Char::isDigit).take(2) },
                                        enabled = !state.commandBusy,
                                        label = { Text("Parcelas") },
                                        singleLine = true,
                                    )
                                }
                                val orderId = orderText.toIntOrNull() ?: 0
                                val amount = parseMoneyCents(amountText)
                                Button(
                                    onClick = { onChargeTef(orderId, amount, paymentType, installmentsText.toIntOrNull()?.coerceIn(1, 24) ?: 1) },
                                    enabled = orderId > 0 && amount > 0 && state.selectedTerminal != null && state.selected?.online == true && commandEnabled,
                                ) { Text("Enviar cobrança ao PINPad") }
                                Text(
                                    "O celular apenas solicita a cobrança. A confirmação do pagamento continua sendo feita pelo servidor.",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }

                        if (canOpenDrawer) {
                            item {
                                OutlinedButton(
                                    onClick = { onOpenDrawer("Abertura solicitada pelo celular") },
                                    enabled = state.selected?.online == true && commandEnabled,
                                ) { Text("Abrir gaveta") }
                            }
                        }

                        if (canSendAlert) {
                            item {
                                SectionTitle("Aviso no computador")
                                OutlinedTextField(
                                    value = alertText,
                                    onValueChange = { alertText = it.take(300) },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = !state.commandBusy,
                                    label = { Text("Mensagem") },
                                )
                                OutlinedButton(
                                    onClick = { onAlert(alertText) },
                                    enabled = alertText.isNotBlank() && state.selected?.online == true && commandEnabled,
                                ) { Text("Enviar alerta") }
                            }
                        }
                    }

                    if (state.selected != null) {
                        item {
                            TextButton(onClick = onRevoke, enabled = commandEnabled) {
                                Text("Desvincular este celular do computador")
                            }
                        }
                    }

                    state.message?.let { message -> item { Text(message, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Medium) } }
                    state.error?.let { error -> item { Text(error, color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Medium) } }
                    if (state.loading) item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) { CircularProgressIndicator() } }
                    if (state.commandBusy) {
                        item {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                                CircularProgressIndicator()
                                Text("Aguardando resposta do computador…", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                    item { Spacer(Modifier.height(24.dp)) }
                }
            }
        }
    }
}

@Composable
private fun HubComputerCard(link: HubLink, selected: Boolean, enabled: Boolean, onSelect: () -> Unit) {
    Card(
        onClick = onSelect,
        enabled = enabled,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = if (selected) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant,
        ),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(link.desktopLabel, fontWeight = FontWeight.SemiBold)
                Text(if (link.online) "● Online" else "○ Offline", color = if (link.online) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (link.unitName.isNotBlank()) Text(link.unitName, color = MaterialTheme.colorScheme.onSurfaceVariant)
            val items = buildList {
                if (link.hardware.defaultPrinter.isNotBlank()) add("Impressora")
                if (link.hardware.cashDrawer.isNotBlank()) add("Gaveta")
                if (link.hardware.pinpad.isNotBlank() || link.hardware.tefProvider.isNotBlank()) add("PINPad")
                if (link.hardware.scale.isNotBlank()) add("Balança")
                if (link.hardware.barcodeScanner.isNotBlank()) add("Leitor")
                if (link.hardware.customerDisplay.isNotBlank()) add("Tela do cliente")
            }
            Text(if (items.isEmpty()) "Equipamentos ainda não configurados" else items.joinToString(" • "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp, bottom = 4.dp))
}

private fun parseMoneyCents(raw: String): Int {
    val clean = raw.trim().replace("R$", "").replace(" ", "")
    if (clean.isBlank()) return 0
    val normalized = if (clean.contains(',')) clean.replace(".", "").replace(',', '.') else clean
    val value = normalized.toBigDecimalOrNull() ?: return 0
    return runCatching { value.multiply(100.toBigDecimal()).toInt() }.getOrDefault(0).coerceAtLeast(0)
}