package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
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
    val missingPermissions = AppPermissionManager.missingLabels(context)
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
                        "Este aparelho já está autorizado para Tap On."
                    } else {
                        "Solicitação enviada ao administrador. Não é necessário reinstalar o app."
                    }
                    onRefresh()
                }
                .onFailure { requestError = it.message ?: "Não foi possível solicitar autorização." }
            requestBusy = false
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Column {
                    Text("Aparelho e autorizações", style = MaterialTheme.typography.titleLarge)
                    Text("Gerencie permissões sem reinstalar o app", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                }
                if (state.loading || requestBusy) CircularProgressIndicator()
            }

            if (missingPermissions.isNotEmpty()) {
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer.copy(alpha = .55f),
                    shape = MaterialTheme.shapes.medium,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Text("Permissões do Android", fontWeight = FontWeight.Bold)
                        Text("Pendente: ${missingPermissions.joinToString(" · ")}", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = { AppPermissionManager.request(context) }, modifier = Modifier.fillMaxWidth()) { Text("Autorizar agora") }
                        OutlinedButton(onClick = { AppPermissionManager.openSettings(context) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir configurações do Android") }
                    }
                }
            } else {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Permissões do Android", fontWeight = FontWeight.SemiBold)
                        Text("Notificações e Bluetooth liberados", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                    }
                    Text("✓", color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.titleLarge)
                }
            }

            requestMessage?.let { Text(it, color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.SemiBold) }
            requestError?.let { Text(it, color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold) }

            if (status == null) {
                Text("Status do aparelho ainda não carregado.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = .7f)),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text("Sessão deste aparelho", fontWeight = FontWeight.Bold)
                        Text("Registrada e vinculada ao usuário", color = MaterialTheme.colorScheme.secondary)
                        if (status.session.deviceLabel.isNotBlank()) Text(status.session.deviceLabel)
                        Text("Sessão expira: ${status.session.expiresAt}", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
                    }
                }

                val nfc = status.nfc
                when {
                    !status.nfcPermission -> {
                        Text("Tap On indisponível para este perfil", fontWeight = FontWeight.Bold)
                        Text("O administrador precisa liberar a permissão de receber por aproximação para este funcionário.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    nfc == null -> {
                        Text("Tap On ainda não autorizado", fontWeight = FontWeight.Bold)
                        Text("Envie a solicitação pelo app. Ela aparecerá no painel do administrador e não será necessário desinstalar nem limpar os dados.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Button(onClick = ::requestNfcAuthorization, enabled = !state.loading && !requestBusy, modifier = Modifier.fillMaxWidth()) { Text("Solicitar autorização Tap On") }
                    }
                    nfc.status == "pending" -> {
                        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = MaterialTheme.shapes.medium, modifier = Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text("Aguardando autorização", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer)
                                Text("O pedido já está no painel administrativo. Depois da aprovação, toque em Atualizar status.", color = MaterialTheme.colorScheme.onPrimaryContainer)
                            }
                        }
                    }
                    nfc.status == "active" && status.tapOnReady -> Text("Tap On pronto para cobrar", color = MaterialTheme.colorScheme.secondary, fontWeight = FontWeight.Bold)
                    nfc.status == "revoked" -> {
                        Text("Dispositivo revogado", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold)
                        Text("Por segurança, um aparelho revogado só pode ser reativado pelo administrador.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    else -> Text("Tap On: ${nfc.status}")
                }

                nfc?.let {
                    Text("Dispositivo: ${it.name.ifBlank { "#${it.id}" }}", style = MaterialTheme.typography.bodyMedium)
                    if (!it.boundToCurrentUser) Text("Dispositivo vinculado a outro funcionário.", color = MaterialTheme.colorScheme.error)
                }
                if (status.nfcPermission && !status.pagbankActive) Text("PagBank ainda não está ativo para esta empresa.", color = MaterialTheme.colorScheme.error)
            }

            OutlinedButton(onClick = onRefresh, enabled = !state.loading && !requestBusy, modifier = Modifier.fillMaxWidth()) { Text("Atualizar status") }
            OutlinedButton(onClick = { AppPermissionManager.openSettings(context) }, modifier = Modifier.fillMaxWidth()) { Text("Permissões do aparelho") }
        }
    }
}
