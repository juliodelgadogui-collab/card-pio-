package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.weight
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import androidx.compose.ui.draw.clip
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
    Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
        Text("EVENTMENU GO", color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
        Spacer(Modifier.height(8.dp))
        Text("Olá, ${user.name}", style = MaterialTheme.typography.headlineMedium)
        Text(user.tenantName.ifBlank { "Empresa #${user.tenantId}" }, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(22.dp))
        Card(
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text(mode.label, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleLarge)
                Text("Turno fechado", fontWeight = FontWeight.SemiBold)
                Text("Inicie seu turno para acessar as funções liberadas pelo servidor.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        Button(onClick = onStart, enabled = !state.loading, modifier = Modifier.fillMaxWidth().padding(top = 18.dp)) { Text("Iniciar turno") }
        onChangeMode?.let { OutlinedButton(onClick = it, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("Trocar modo") } }
        OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("Sair") }
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
    val shift = state.workShift
    val unitName = displayUnitName(shift?.unitName)
    val modeName = shift?.mode?.let(::modeLabel) ?: state.mode?.label ?: roleLabelProfile(user.role)
    var pin by remember { mutableStateOf("") }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(13.dp)) {
                        Box(
                            modifier = Modifier.size(52.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primaryContainer),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(profileInitials(user.name), color = MaterialTheme.colorScheme.onPrimaryContainer, fontWeight = FontWeight.Black)
                        }
                        Column(Modifier.weight(1f)) {
                            Text(user.name, style = MaterialTheme.typography.titleLarge)
                            Text(user.email, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                        }
                        StatusPill(if (shift?.status == "open") "Turno aberto" else "Sem turno")
                    }
                    HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = .35f))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        ProfileInfoTile("Função", modeName, Modifier.weight(1f))
                        ProfileInfoTile("Unidade", unitName, Modifier.weight(1f))
                    }
                    Text(user.tenantName.ifBlank { "Empresa #${user.tenantId}" }, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Button(onClick = onGenerateMyQr, modifier = Modifier.fillMaxWidth()) {
                        Text(if (shift?.mode == "delivery") "Mostrar meu QR de entregador" else "Mostrar meu QR de funcionário")
                    }
                    Text(
                        "O QR identifica você; cargo, permissões e turno continuam sendo validados pelo servidor.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
        }

        shift?.let { currentShift ->
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Column {
                                Text("Turno atual", style = MaterialTheme.typography.titleLarge)
                                Text("Desde ${profileDateTime(currentShift.startedAt)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            StatusPill(modeLabel(currentShift.mode))
                        }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            ProfileMetric("Pedidos", (shiftSummary?.orders?.qty ?: 0).toString(), Modifier.weight(1f))
                            ProfileMetric("Valor", profileMoney(shiftSummary?.orders?.totalCents ?: 0), Modifier.weight(1f))
                        }
                        ProfileMetric("Recebimentos", profileMoney(shiftSummary?.receivedTotalCents ?: 0), Modifier.fillMaxWidth(), emphasized = true)
                        if (summaryLoading) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) { CircularProgressIndicator(Modifier.size(24.dp)) }
                        }
                        if (!shiftSummary?.byMethod.isNullOrEmpty()) {
                            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = .3f))
                            shiftSummary!!.byMethod
                                .filter { it.direction == "in" }
                                .groupBy { it.method }
                                .forEach { (method, rows) ->
                                    val total = rows.sumOf { it.totalCents }
                                    val qty = rows.sumOf { it.qty }
                                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                        Text(methodLabel(method), color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        Text("${profileMoney(total)} · $qty", fontWeight = FontWeight.Bold)
                                    }
                                }
                        }
                        OutlinedButton(onClick = onRefreshSummary, enabled = !summaryLoading, modifier = Modifier.fillMaxWidth()) { Text("Atualizar resumo") }
                    }
                }
            }

            if (currentShift.mode == "delivery") {
                val cash = shiftSummary?.deliveryCash ?: state.deliveryCash
                val commission = shiftSummary?.deliveryCommission
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
                            Text("Fechamento do entregador", style = MaterialTheme.typography.titleLarge)
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                ProfileMetric("Entregas", (commission?.deliveries ?: 0).toString(), Modifier.weight(1f))
                                ProfileMetric("Comissão", profileMoney(commission?.commissionCents ?: 0), Modifier.weight(1f), emphasized = true)
                            }
                            Text("Valor concluído: ${profileMoney(commission?.revenueCents ?: 0)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            val bps = commission?.percentBps ?: 0
                            val fixed = commission?.fixedPerDeliveryCents ?: 0
                            val rules = buildList {
                                if (bps > 0) add("${formatCommissionPercent(bps)}%")
                                if (fixed > 0) add("${profileMoney(fixed)} por entrega")
                            }.joinToString(" + ")
                            Text(if (rules.isBlank()) "Sem comissão configurada" else "Regra: $rules", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = .3f))
                            ProfileMetric("Dinheiro a entregar ao caixa", profileMoney(cash?.outstandingCents ?: 0), Modifier.fillMaxWidth(), emphasized = true)
                            Text("Recebido: ${profileMoney(cash?.cashCollectedCents ?: 0)} · Já repassado: ${profileMoney(cash?.confirmedHandoffCents ?: 0)}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            if ((cash?.outstandingCents ?: 0) > 0) Button(onClick = onCreateHandoff, modifier = Modifier.fillMaxWidth()) { Text("Gerar QR para o caixa") }
                            OutlinedButton(onClick = onRefreshDeliveryCash, modifier = Modifier.fillMaxWidth()) { Text("Atualizar valores") }
                        }
                    }
                }
            }

            item { Button(onClick = onCloseShift, modifier = Modifier.fillMaxWidth()) { Text("Encerrar turno") } }
        }

        item {
            Text("Dispositivo e impressão", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 4.dp, start = 2.dp))
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

        item {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
                    Text("Segurança deste aparelho", style = MaterialTheme.typography.titleLarge)
                    OutlinedTextField(
                        pin,
                        { pin = it.filter(Char::isDigit).take(8) },
                        label = { Text("Novo PIN (4 a 8 dígitos)") },
                        visualTransformation = PasswordVisualTransformation(),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedButton(onClick = { onSavePin(pin); pin = "" }, enabled = pin.length >= 4, modifier = Modifier.fillMaxWidth()) { Text("Salvar PIN") }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("Biometria", fontWeight = FontWeight.SemiBold)
                            Text("Acesso rápido neste aparelho", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                        }
                        Switch(checked = state.biometricEnabled, onCheckedChange = onBiometric)
                    }
                    Text("PIN e biometria apenas desbloqueiam a sessão local. A API continua validando usuário, empresa, aparelho e permissões.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
        item { OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("Sair do app") } }
        item { Spacer(Modifier.height(8.dp)) }
    }

    state.cashHandoff?.let { handoff ->
        val bitmap = remember(handoff.qrPayload) { qrBitmap(handoff.qrPayload) }
        AlertDialog(
            onDismissRequest = onDismissHandoff,
            title = { Text("Entregar dinheiro ao caixa") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(profileMoney(handoff.amountCents), style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.primary)
                    Text("Unidade: $unitName")
                    bitmap?.let { Image(it.asImageBitmap(), contentDescription = "QR do repasse", modifier = Modifier.fillMaxWidth()) }
                    Text("O caixa deve ler este QR no EventMenu GO da mesma unidade. O turno só poderá ser encerrado após a confirmação do recebimento.")
                }
            },
            confirmButton = { TextButton(onClick = onDismissHandoff) { Text("Fechar") } },
        )
    }
}

@Composable
private fun StatusPill(text: String) {
    Surface(
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
        shape = RoundedCornerShape(999.dp),
    ) {
        Text(text, modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp), style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
private fun ProfileInfoTile(label: String, value: String, modifier: Modifier = Modifier) {
    Surface(modifier = modifier, color = MaterialTheme.colorScheme.surface, shape = MaterialTheme.shapes.medium) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            Text(value, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun ProfileMetric(label: String, value: String, modifier: Modifier = Modifier, emphasized: Boolean = false) {
    Surface(modifier = modifier, color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = .7f), shape = MaterialTheme.shapes.medium) {
        Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            Text(value, style = MaterialTheme.typography.titleLarge, color = if (emphasized) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
        }
    }
}

private fun modeLabel(mode: String) = when (mode) {
    "operation" -> "Operação"
    "delivery" -> "Delivery"
    "events" -> "Eventos"
    "pay" -> "Pay"
    else -> mode.ifBlank { "Operação" }
}

private fun roleLabelProfile(role: String) = when (role.lowercase()) {
    "delivery" -> "Entregador"
    "cashier" -> "Caixa"
    "attendant" -> "Balconista"
    "kitchen" -> "Cozinha"
    "waiter" -> "Garçom"
    "manager" -> "Gerente"
    "admin" -> "Administrador"
    "promoter" -> "Promotor"
    else -> role.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() }
}

private fun displayUnitName(raw: String?): String {
    val clean = raw?.trim().orEmpty()
    return if (clean.isBlank() || clean.equals("null", ignoreCase = true)) "Principal" else clean
}

private fun profileInitials(name: String): String {
    val parts = name.trim().split(Regex("\\s+")).filter { it.isNotBlank() }
    if (parts.isEmpty()) return "EM"
    val first = parts.first().firstOrNull()?.uppercaseChar()?.toString().orEmpty()
    val last = if (parts.size > 1) parts.last().firstOrNull()?.uppercaseChar()?.toString().orEmpty() else ""
    return (first + last).ifBlank { "EM" }
}

private fun profileDateTime(value: String): String {
    val clean = value.trim().replace('T', ' ')
    val date = clean.substringBefore(' ')
    val time = clean.substringAfter(' ', "").take(5)
    val parts = date.split('-')
    val dateBr = if (parts.size == 3) "${parts[2]}/${parts[1]}" else date
    return listOf(dateBr, time).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { "agora" }
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
