package br.com.eventmenu.go.ui.screens

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.PrinterState
import br.com.eventmenu.go.printing.PrinterDevice

@Composable
fun PrinterSettingsCard(
    state: PrinterState,
    onRefresh: () -> Unit,
    onSelectDevice: (PrinterDevice) -> Unit,
    onClearDevice: () -> Unit,
    onEnabled: (Boolean) -> Unit,
    onAutoPrint: (Boolean) -> Unit,
    onPaperWidth: (Int) -> Unit,
    onPrintTest: () -> Unit,
) {
    var chooseDevice by remember { mutableStateOf(false) }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { onRefresh() }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Impressora térmica", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text(if (state.settings.deviceAddress.isBlank()) "Nenhuma impressora selecionada" else "${state.settings.deviceName} · ${state.settings.deviceAddress}")

            if (!state.hasPermission && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                Button(
                    onClick = { permissionLauncher.launch(Manifest.permission.BLUETOOTH_CONNECT) },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("PERMITIR BLUETOOTH") }
            } else {
                OutlinedButton(onClick = { onRefresh(); chooseDevice = true }, modifier = Modifier.fillMaxWidth()) { Text("ESCOLHER IMPRESSORA PAREADA") }
            }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text("Impressora ativa")
                Switch(checked = state.settings.enabled, onCheckedChange = onEnabled, enabled = state.settings.deviceAddress.isNotBlank())
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text("Imprimir automaticamente")
                Switch(checked = state.settings.autoPrint, onCheckedChange = onAutoPrint, enabled = state.settings.enabled)
            }
            Text("Largura do papel")
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = state.settings.paperWidthMm == 58, onClick = { onPaperWidth(58) }, label = { Text("58 mm") })
                FilterChip(selected = state.settings.paperWidthMm == 80, onClick = { onPaperWidth(80) }, label = { Text("80 mm") })
            }
            OutlinedButton(onClick = onPrintTest, enabled = state.settings.enabled && state.hasPermission && !state.loading, modifier = Modifier.fillMaxWidth()) { Text("IMPRIMIR TESTE") }
            if (state.settings.deviceAddress.isNotBlank()) TextButton(onClick = onClearDevice) { Text("REMOVER IMPRESSORA") }
            Text("O EventMenu GO usa somente dispositivos Bluetooth já pareados no Android. A configuração da impressora fica neste aparelho e não altera permissões do funcionário.")
        }
    }

    if (chooseDevice) {
        AlertDialog(
            onDismissRequest = { chooseDevice = false },
            title = { Text("Impressoras pareadas") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (state.devices.isEmpty()) Text("Nenhum dispositivo Bluetooth pareado encontrado. Faça o pareamento nas configurações do Android e atualize.")
                    state.devices.forEach { device ->
                        OutlinedButton(
                            onClick = { chooseDevice = false; onSelectDevice(device) },
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text(device.name) }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { chooseDevice = false }) { Text("FECHAR") } },
        )
    }
}
