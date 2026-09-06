package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.DeviceStatusState
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.PrinterState
import br.com.eventmenu.go.data.ShiftSummary
import br.com.eventmenu.go.printing.PrinterDevice

@Composable
fun ShiftStartScreen(state: GoState, onStart: () -> Unit, onChangeMode: (() -> Unit)? = null, onLogout: () -> Unit) {
    val user = state.session?.user ?: return
    val mode = state.mode ?: return
    Column(Modifier.fillMaxSize().padding(28.dp), verticalArrangement = Arrangement.Center) {
        Text("Olá, ${user.name}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
        if (user.tenantName.isNotBlank()) Text("Empresa: ${user.tenantName}")
        Text(mode.label, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleLarge)
        Text("Turno: Fechado", modifier = Modifier.padding(top = 8.dp))
        Button(onClick = onStart, enabled = !state.loading, modifier = Modifier.fillMaxWidth().padding(top = 24.dp)) { Text("INICIAR TURNO") }
        onChangeMode?.let { OutlinedButton(onClick = it, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("TROCAR MODO") } }
        OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("SAIR") }
        Text("O turno é aberto na API EventMenu e fica vinculado ao funcionário e ao aparelho autenticado.", modifier = Modifier.padding(top = 18.dp))
    }
}

@Composable
fun EmployeeProfileScreen(
    state: GoState,
    shiftSummary: ShiftSummary?,
    summaryLoading: Boolean,
    onRefreshSummary: () -> Unit,
    onGenerateMyQr: () -> Unit,
    deviceState: DeviceStatusState,
    onRefreshDevice: () -> Unit,
    printerState: PrinterState,
    onPrinterRefresh: () -> Unit,
    onSelectPrinter: (PrinterDevice) -> Unit,
    onClearPrinter: () -> Unit,
    onPrinterEnabled: (Boolean) -> Unit,
    onPrinterAutoPrint: (Boolean) -> Unit,
    onPrinterPaperWidth: (Int) -> Unit,
    onPrinterTest: () -> Unit,
    onSavePin: (String) -> Unit,
    onBiometric: (Boolean) -> Unit,
    onCloseShift: () -> Unit,
    onCreateHandoff: () -> Unit,
    onDismissHandoff: () -> Unit,
    onRefreshDeliveryCash: () -> Unit,
    onLogout: () -> Unit,
) {
    val user = state.session?.user ?: return
    var pin by remember { mutableStateOf("") }

    LazyColumn(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text(user.name, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("${state.mode?.label ?: user.role} · ${user.email}")
            Text("Empresa: ${user.tenantName.ifBlank { "Empresa #${user.tenantId}" }}")
            Text("Unidade: ${state.workShift?.unitName?.ifBlank { "Principal" } ?: "Principal"}")
            OutlinedButton(onClick = onGenerateMyQr, modifier = Modifier.fillMaxWidth().padding(top = 10.dp)) {
                Text(if (state.workShift?.mode == "delivery") "MOSTRAR MEU QR DE ENTREGADOR" else "MOSTRAR MEU QR DE FUNCIONÁRIO")
            }
            Text("O QR identifica o funcionário, mas cargo, permissões e turno são sempre validados pela API.")
        }

        state.workShift?.let { shift ->
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Turno aberto", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                        Text("Iniciado: ${shift.startedAt}")
                        Text("Modo: ${modeLabel(shift.mode)}")
                        Text("Unidade: ${shift.unitName.ifBlank { "Principal" }}")
                        if (summaryLoading) CircularProgressIndicator()
                    }
                }
            }

            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Resumo do turno", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Pedidos/entregas vinculados: ${shiftSummary?.orders?.qty ?: 0}")
                        Text("Valor dos pedidos: ${profileMoney(shiftSummary?.orders?.totalCents ?: 0)}")
                        Text("Recebimentos registrados: ${profileMoney(shiftSummary?.receivedTotalCents ?: 0)}", fontWeight = FontWeight.Bold)
                        HorizontalDivider()
                        if (shiftSummary?.byMethod.isNullOrEmpty()) {
                            Text("Nenhum recebimento registrado neste turno.")
                        } else {
                            shiftSummary!!.byMethod
                                .filter { it.direction == "in" }
                                .groupBy { it.method }
                                .forEach { (method, rows) ->
                                    val total = rows.sumOf { it.totalCents }
                                    val qty = rows.sumOf { it.qty }
                                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                        Text(methodLabel(method))
                                        Text("${profileMoney(total)} · $qty", fontWeight = FontWeight.Bold)
                                    }
                                }
                        }
                        OutlinedButton(onClick = onRefreshSummary, enabled = !summaryLoading, modifier = Modifier.fillMaxWidth()) {
                            Text("ATUALIZAR RESUMO")
                        }
                    }
                }
            }

            if (shift.mode == "delivery") {
                val cash = shiftSummary?.deliveryCash ?: state.deliveryCash
                val commission = shiftSummary?.deliveryCommission
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                            Text("Fechamento do entregador", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                            Text("Unidade: ${shift.unitName.ifBlank { "Principal" }}")
                            Text("Entregas concluídas: ${commission?.deliveries ?: 0}")
                            Text("Valor das entregas concluídas: ${profileMoney(commission?.revenueCents ?: 0)}")
                            val bps = commission?.percentBps ?: 0
                            val fixed = commission?.fixedPerDeliveryCents ?: 0
                            if (bps > 0 || fixed > 0) {
                                val rules = buildList {
                                    if (bps > 0) add("${formatCommissionPercent(bps)}%")
                                    if (fixed > 0) add("${profileMoney(fixed)} por entrega")
                                }.joinToString(" + ")
                                Text("Regra de comissão: $rules")
                            } else Text("Regra de comissão: sem comissão configurada")
                            Text("Comissão: ${profileMoney(commission?.commissionCents ?: 0)}", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                            HorizontalDivider()
                            Text("Dinheiro recebido: ${profileMoney(cash?.cashCollectedCents ?: 0)}")
                            Text("Já entregue ao caixa: ${profileMoney(cash?.confirmedHandoffCents ?: 0)}")
                            Text("Dinheiro a entregar: ${profileMoney(cash?.outstandingCents ?: 0)}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                            if ((cash?.outstandingCents ?: 0) > 0) {
                                Button(onClick = onCreateHandoff, modifier = Modifier.fillMaxWidth()) { Text("GERAR QR PARA O CAIXA") }
                            }
                            OutlinedButton(onClick = onRefreshDeliveryCash, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR DINHEIRO") }
                        }
                    }
                }
            }

            item { Button(onClick = onCloseShift, modifier = Modifier.fillMaxWidth()) { Text("ENCERRAR TURNO") } }
        }

        item { DeviceStatusCard(deviceState, onRefreshDevice) }
        item {
            PrinterSettingsCard(
                state = printerState,
                onRefresh = onPrinterRefresh,
                onSelectDevice = onSelectPrinter,
                onClearDevice = onClearPrinter,
                onEnabled = onPrinterEnabled,
                onAutoPrint = onPrinterAutoPrint,
                onPaperWidth = onPrinterPaperWidth,
                onPrintTest = onPrinterTest,
            )
        }

        item { HorizontalDivider() }
        item { Text("Segurança deste aparelho", style = MaterialTheme.typography.titleMedium) }
        item {
            OutlinedTextField(
                pin,
                { pin = it.filter(Char::isDigit).take(8) },
                label = { Text("Novo PIN (4 a 8 dígitos)") },
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth(),
            )
        }
        item { OutlinedButton(onClick = { onSavePin(pin); pin = "" }, enabled = pin.length >= 4, modifier = Modifier.fillMaxWidth()) { Text("SALVAR PIN") } }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text("Entrar com biometria")
                Switch(checked = state.biometricEnabled, onCheckedChange = onBiometric)
            }
        }
        item { Text("PIN e biometria só desbloqueiam a sessão local. A API revalida usuário, empresa, aparelho e permissões em todas as ações.") }
        item { OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("SAIR DO APP") } }
    }

    state.cashHandoff?.let { handoff ->
        val bitmap = remember(handoff.qrPayload) { qrBitmap(handoff.qrPayload) }
        AlertDialog(
            onDismissRequest = onDismissHandoff,
            title = { Text("Entregar dinheiro ao caixa") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Valor: ${profileMoney(handoff.amountCents)}", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                    Text("Unidade: ${state.workShift?.unitName?.ifBlank { "Principal" } ?: "Principal"}")
                    bitmap?.let { Image(it.asImageBitmap(), contentDescription = "QR do repasse", modifier = Modifier.fillMaxWidth()) }
                    Text("O caixa deve ler este QR no EventMenu GO na mesma unidade. O turno só poderá ser encerrado depois da confirmação do recebimento.")
                }
            },
            confirmButton = { TextButton(onClick = onDismissHandoff) { Text("FECHAR") } },
        )
    }
}

private fun modeLabel(mode: String) = when (mode) {
    "operation" -> "Operação"
    "delivery" -> "Delivery"
    "events" -> "Eventos"
    "pay" -> "Pay"
    else -> mode
}

private fun methodLabel(method: String) = when (method.lowercase()) {
    "cash" -> "Dinheiro"
    "pix" -> "PIX"
    "nfc", "card", "credit", "debit" -> "Cartão / NFC"
    "handoff" -> "Repasse"
    else -> method.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() }
}

private fun formatCommissionPercent(bps: Int): String {
    val value = bps / 100.0
    return if (value % 1.0 == 0.0) value.toInt().toString() else "%.2f".format(value).replace('.', ',')
}

private fun profileMoney(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')
