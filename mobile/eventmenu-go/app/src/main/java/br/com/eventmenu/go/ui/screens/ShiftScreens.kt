package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.GoState

@Composable
fun ShiftStartScreen(state: GoState, onStart: () -> Unit, onChangeMode: (() -> Unit)? = null, onLogout: () -> Unit) {
    val user = state.session?.user ?: return
    val mode = state.mode ?: return
    Column(Modifier.fillMaxSize().padding(28.dp), verticalArrangement = Arrangement.Center) {
        Text("Olá, ${user.name}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
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
    onSavePin: (String) -> Unit,
    onBiometric: (Boolean) -> Unit,
    onCloseShift: () -> Unit,
    onLogout: () -> Unit,
) {
    val user = state.session?.user ?: return
    var pin by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(user.name, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
        Text("${state.mode?.label ?: user.role} · ${user.email}")
        state.workShift?.let { shift ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text("Turno aberto", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                    Text("Iniciado: ${shift.startedAt}")
                    Text("Modo: ${shift.mode}")
                }
            }
            Button(onClick = onCloseShift, modifier = Modifier.fillMaxWidth()) { Text("ENCERRAR TURNO") }
        }
        HorizontalDivider()
        Text("Segurança deste aparelho", style = MaterialTheme.typography.titleMedium)
        OutlinedTextField(pin, { pin = it.filter(Char::isDigit).take(8) }, label = { Text("Novo PIN (4 a 8 dígitos)") }, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
        OutlinedButton(onClick = { onSavePin(pin); pin = "" }, enabled = pin.length >= 4, modifier = Modifier.fillMaxWidth()) { Text("SALVAR PIN") }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text("Entrar com biometria")
            Switch(checked = state.biometricEnabled, onCheckedChange = onBiometric)
        }
        Text("PIN e biometria só desbloqueiam a sessão local. A API revalida usuário, empresa, aparelho e permissões em todas as ações.")
        Spacer(Modifier.weight(1f))
        OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("SAIR DO APP") }
    }
}
