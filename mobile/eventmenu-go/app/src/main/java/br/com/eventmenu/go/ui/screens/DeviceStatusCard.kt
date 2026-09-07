package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.DeviceStatusState
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.security.AppPermissionManager
import kotlinx.coroutines.launch

@Composable
fun DeviceStatusCard(state: DeviceStatusState, onRefresh: () -> Unit) {
    val status = state.status
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val scope = rememberCoroutineScope()
    val needsAndroidPermission = AppPermissionManager.missingLabels(context).isNotEmpty()
    var requestBusy by remember { mutableStateOf(false) }
    var requestMessage by remember { mutableStateOf<String?>(null) }
    var requestError by remember { mutableStateOf<String?>(null) }

    fun requestNfcAuthorization() {
        if (requestBusy) return
        requestBusy = true
        requestMessage = null
        requestError = null
        scope.launch {
            runCatching { app.deviceStatusRepository.requestNfcAuthorization() }
                .onSuccess { updated ->
                    requestMessage = if (updated.tapOnReady) {
                        "Pagamento por aproximação já está pronto neste aparelho."
                    } else {
                        "Solicitação enviada para aprovação."
                    }
                    onRefresh()
                }
                .onFailure { requestError = "Não foi possível enviar a solicitação. Tente novamente." }
            requestBusy = false
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text("Recursos do aparelho", style = MaterialTheme.typography.titleLarge)
                    Text("Permissões e pagamento por aproximação", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                if (state.loading || requestBusy) CircularProgressIndicator()
            }

            if (needsAndroidPermission) {
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer,
                    shape = MaterialTheme.shapes.medium,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Autorizações necessárias", fontWeight = FontWeight.Bold)
                        Text("Libere os acessos solicitados para usar todos os recursos do aplicativo.")
                        Button(onClick = { AppPermissionManager.request(context) }, modifier = Modifier.fillMaxWidth()) { Text("Continuar") }
                    }
                }
            } else {
                DeviceFeatureRow("Recursos do Android", "Prontos", true)
            }

            requestMessage?.let { Text(it, color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.SemiBold) }
            requestError?.let { Text(it, color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold) }

            if (status == null) {
                Text("Verificando pagamento por aproximação...", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                val nfc = status.nfc
                when {
                    !status.nfcPermission -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Não disponível para este acesso", false)
                    }
                    !status.pagbankActive -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Ainda não configurado pela empresa", false)
                    }
                    nfc == null -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Precisa de autorização", false)
                        Button(
                            onClick = ::requestNfcAuthorization,
                            enabled = !state.loading && !requestBusy,
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text("Solicitar autorização") }
                    }
                    nfc.status == "pending" -> {
                        Surface(
                            color = MaterialTheme.colorScheme.primaryContainer,
                            shape = MaterialTheme.shapes.medium,
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text("Aguardando aprovação", fontWeight = FontWeight.Bold)
                                Text("A solicitação já foi enviada. Atualize depois que o responsável aprovar.")
                            }
                        }
                    }
                    nfc.status == "active" && status.tapOnReady -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Pronto para usar", true)
                    }
                    nfc.status == "revoked" -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Acesso removido", false)
                        Text("Procure um administrador para liberar novamente.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    else -> {
                        DeviceFeatureRow("Pagamento por aproximação", "Configuração pendente", false)
                    }
                }
            }

            OutlinedButton(onClick = onRefresh, enabled = !state.loading && !requestBusy, modifier = Modifier.fillMaxWidth()) { Text("Atualizar") }
            OutlinedButton(onClick = { AppPermissionManager.openSettings(context) }, modifier = Modifier.fillMaxWidth()) { Text("Configurações do aparelho") }
        }
    }
}

@Composable
private fun DeviceFeatureRow(label: String, value: String, ready: Boolean) {
    Row(
        Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(label, fontWeight = FontWeight.SemiBold)
            Text(value, color = if (ready) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (ready) Text("Pronto", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
    }
}
