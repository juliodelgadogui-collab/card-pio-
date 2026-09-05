package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.DeviceStatusState

@Composable
fun DeviceStatusCard(state: DeviceStatusState, onRefresh: () -> Unit) {
    val status = state.status
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text("Aparelho e Tap On", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                if (state.loading) CircularProgressIndicator()
            }
            if (status == null) {
                Text("Status do aparelho ainda não carregado.")
            } else {
                Text("Sessão do app: ✅ Registrada", fontWeight = FontWeight.Bold)
                if (status.session.deviceLabel.isNotBlank()) Text("Aparelho: ${status.session.deviceLabel}")
                Text("Sessão expira: ${status.session.expiresAt}")
                if (status.session.lastUsedAt.isNotBlank()) Text("Último uso: ${status.session.lastUsedAt}")

                val nfc = status.nfc
                when {
                    !status.nfcPermission -> Text("NFC/Tap On: sem permissão para este funcionário")
                    nfc == null -> Text("NFC/Tap On: aparelho ainda não registrado")
                    nfc.status == "active" && status.tapOnReady -> Text("NFC/Tap On: ✅ Pronto para cobrar", fontWeight = FontWeight.Bold)
                    nfc.status == "pending" -> Text("NFC/Tap On: ⏳ aguardando autorização administrativa")
                    nfc.status == "revoked" -> Text("NFC/Tap On: 🔴 dispositivo revogado")
                    else -> Text("NFC/Tap On: ${nfc.status}")
                }
                nfc?.let {
                    Text("Dispositivo NFC: ${it.name.ifBlank { "#${it.id}" }}")
                    Text("Provedor: ${it.provider}")
                    if (it.pairedAt.isNotBlank()) Text("Pareado em: ${it.pairedAt}")
                    if (!it.boundToCurrentUser) Text("⚠️ Dispositivo vinculado a outro funcionário.")
                }
                if (status.nfcPermission && !status.pagbankActive) Text("⚠️ PagBank não está ativo para a empresa.")
                Text("Pareamento, revogação e configuração do gateway continuam disponíveis somente no painel administrativo web.")
            }
            OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR STATUS") }
        }
    }
}
