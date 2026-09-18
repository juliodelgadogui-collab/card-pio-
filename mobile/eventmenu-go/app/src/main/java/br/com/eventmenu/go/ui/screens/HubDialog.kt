package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import br.com.eventmenu.go.HubState
import br.com.eventmenu.go.OperationalText
import br.com.eventmenu.go.data.HubLink

@Composable
fun HubDialog(
    state: HubState,
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
    onReadScale: () -> Unit,
    onRevoke: () -> Unit,
) {
    var pairing by remember { mutableStateOf("") }
    var order by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("debit") }
    var installments by remember { mutableStateOf("1") }
    var alert by remember { mutableStateOf("") }

    LaunchedEffect(Unit) { onRefresh() }
    LaunchedEffect(state.selectedLinkId, canTerminalRequest) { if (canTerminalRequest && state.selected != null) onLoadTerminals() }

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize().padding(12.dp), shape = MaterialTheme.shapes.extraLarge, tonalElevation = 3.dp) {
            Column(Modifier.fillMaxSize().padding(18.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                    Column(Modifier.weight(1f)) {
                        Text("Conectar ao computador", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold)
                        Text("Use a impressora, balança, gaveta e máquina de cartão do EventMenu Desktop pela internet.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    TextButton(onClick = onDismiss) { Text("Fechar") }
                }
                Spacer(Modifier.height(12.dp))
                LazyColumn(Modifier.fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    item {
                        Title("Computadores vinculados")
                        if (state.links.isEmpty() && !state.loading) Text("Nenhum computador vinculado.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    items(state.links, key = { it.id }) { link -> Computer(link, state.selected?.id == link.id) { onSelectLink(link.id) } }
                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = onRefresh, enabled = !state.loading) { Text("Atualizar") }
                            Button(onClick = onScanPairing, enabled = !state.loading) { Text("Ler QR do computador") }
                        }
                    }
                    item {
                        OutlinedTextField(
                            pairing,
                            { pairing = it },
                            Modifier.fillMaxWidth(),
                            label = { Text("Código de conexão") },
                            supportingText = { Text("O código expira e só funciona para a empresa e unidade autorizadas.") },
                            singleLine = true,
                        )
                        Button(onClick = { onPairCode(pairing, "Celular EventMenu GO") }, enabled = pairing.isNotBlank() && !state.loading) { Text("Conectar") }
                    }

                    if (state.selected != null) {
                        item { HorizontalDivider(); Title("Recursos disponíveis") }
                        item { OutlinedTextField(order, { order = it.filter(Char::isDigit) }, Modifier.fillMaxWidth(), label = { Text("Número do pedido") }, singleLine = true) }
                        item {
                            val id = order.toIntOrNull() ?: 0
                            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedButton({ onPrintOrder(id) }, enabled = id > 0 && state.selected?.online == true && state.selected?.hardware?.defaultPrinter?.isNotBlank() == true) { Text("Imprimir pedido") }
                                    OutlinedButton({ onPrintReceipt(id) }, enabled = id > 0 && state.selected?.online == true && state.selected?.hardware?.defaultPrinter?.isNotBlank() == true) { Text("Imprimir recibo") }
                                }
                                OutlinedButton({ onShowCustomer(id) }, enabled = id > 0 && state.selected?.online == true && state.selected?.hardware?.customerDisplay?.isNotBlank() == true) { Text("Mostrar no display do cliente") }
                                if (state.selected?.hardware?.scale?.isNotBlank() == true) {
                                    OutlinedButton(onClick = onReadScale, enabled = state.selected?.online == true && !state.loading) { Text("Ler peso da balança") }
                                }
                            }
                        }

                        if (canTerminalRequest) {
                            item { Title("Cobrar na máquina de cartão") }
                            if (state.terminals.isEmpty()) {
                                item { Text("Nenhuma máquina de cartão disponível nesta unidade.", color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            } else {
                                items(state.terminals, key = { "t-${it.id}" }) { terminal ->
                                    FilterChip(
                                        state.selectedTerminal?.id == terminal.id,
                                        { onSelectTerminal(terminal.id) },
                                        { Text(terminal.label.ifBlank { "Máquina de cartão" }) },
                                    )
                                }
                            }
                            item {
                                OutlinedTextField(amount, { amount = it.take(12) }, Modifier.fillMaxWidth(), label = { Text("Valor") }, prefix = { Text("R$ ") }, singleLine = true)
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    FilterChip(type == "debit", { type = "debit" }, { Text("Débito") })
                                    FilterChip(type == "credit", { type = "credit" }, { Text("Crédito") })
                                    FilterChip(type == "pix", { type = "pix" }, { Text("PIX") })
                                }
                                if (type == "credit") OutlinedTextField(installments, { installments = it.filter(Char::isDigit).take(2) }, label = { Text("Parcelas") }, singleLine = true)
                                val id = order.toIntOrNull() ?: 0
                                val cents = money(amount)
                                Button(
                                    { onChargeTef(id, cents, type, installments.toIntOrNull()?.coerceIn(1, 24) ?: 1) },
                                    enabled = id > 0 && cents > 0 && state.selectedTerminal != null && state.selected?.online == true && !state.loading,
                                ) { Text("Enviar cobrança") }
                                Text("O EventMenu confirma o pagamento automaticamente antes de marcar o pedido como pago.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }

                        if (canOpenDrawer) {
                            item {
                                OutlinedButton(
                                    { onOpenDrawer("Abertura solicitada pelo celular") },
                                    enabled = state.selected?.online == true && state.selected?.hardware?.cashDrawer?.isNotBlank() == true && !state.loading,
                                ) { Text("Abrir gaveta") }
                            }
                        }
                        item {
                            Title("Aviso no computador")
                            OutlinedTextField(alert, { alert = it.take(300) }, Modifier.fillMaxWidth(), label = { Text("Mensagem") })
                            OutlinedButton({ onAlert(alert) }, enabled = alert.isNotBlank() && state.selected?.online == true) { Text("Enviar aviso") }
                        }
                        item { TextButton(onClick = onRevoke, enabled = !state.loading) { Text("Desconectar este celular") } }
                    }

                    state.message?.let { message -> item { Text(OperationalText.friendlyApiMessage(message), color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Medium) } }
                    state.error?.let { error -> item { Text(OperationalText.friendlyApiMessage(error), color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Medium) } }
                    if (state.loading) item { Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) { CircularProgressIndicator() } }
                    item { Spacer(Modifier.height(24.dp)) }
                }
            }
        }
    }
}

@Composable
private fun Computer(link: HubLink, selected: Boolean, onSelect: () -> Unit) {
    Card(
        onClick = onSelect,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = if (selected) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(link.desktopLabel.ifBlank { "EventMenu Desktop" }, fontWeight = FontWeight.SemiBold)
                Text(
                    if (link.online) "● Disponível" else "○ Indisponível",
                    color = if (link.online) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (link.unitName.isNotBlank()) Text(link.unitName, color = MaterialTheme.colorScheme.onSurfaceVariant)
            val peripherals = buildList {
                if (link.hardware.defaultPrinter.isNotBlank()) add("Impressora pronta")
                if (link.hardware.cashDrawer.isNotBlank()) add("Gaveta disponível")
                if (link.hardware.pinpad.isNotBlank() || link.hardware.tefProvider.isNotBlank()) add("Máquina de cartão disponível")
                if (link.hardware.scale.isNotBlank()) add("Balança conectada")
                if (link.hardware.barcodeScanner.isNotBlank()) add("Leitor disponível")
                if (link.hardware.customerDisplay.isNotBlank()) add("Display disponível")
            }
            Text(
                if (peripherals.isEmpty()) "Equipamentos ainda não configurados" else peripherals.joinToString(" • "),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun Title(value: String) {
    Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp, bottom = 4.dp))
}

private fun money(raw: String): Int {
    val clean = raw.trim().replace("R$", "").replace(" ", "")
    if (clean.isBlank()) return 0
    val normalized = if (clean.contains(',')) clean.replace(".", "").replace(',', '.') else clean
    return runCatching { normalized.toBigDecimal().multiply(100.toBigDecimal()).toInt() }.getOrDefault(0).coerceAtLeast(0)
}
