package br.com.eventmenu.go.ui.screens

import android.os.Build
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.R
import br.com.eventmenu.go.security.AppPermissionManager

@Composable
fun PremiumLoginScreen(
    state: GoState,
    onLogin: (String, String, String) -> Unit,
    onPin: (String) -> Unit,
    onBiometric: () -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var showDeviceSetup by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val missingPermissions = AppPermissionManager.missingLabels(context)

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 22.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item {
            Column(
                modifier = Modifier.fillMaxWidth().padding(top = 28.dp, bottom = 18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Surface(
                    shape = RoundedCornerShape(24.dp),
                    color = MaterialTheme.colorScheme.primaryContainer,
                ) {
                    Image(
                        painter = painterResource(R.drawable.ic_eventmenu_logo),
                        contentDescription = "EventMenu GO",
                        modifier = Modifier.padding(13.dp).size(62.dp),
                    )
                }
                Text("EventMenu GO", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black)
                Text("Sua operação em um só lugar", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }

        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
            ) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Entrar", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                    Text("Use seu acesso da empresa.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    OutlinedTextField(
                        value = email,
                        onValueChange = { email = it },
                        label = { Text("E-mail") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("Senha") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Button(
                        onClick = { onLogin(email, password, "${Build.MANUFACTURER} ${Build.MODEL}") },
                        enabled = email.isNotBlank() && password.isNotBlank() && !state.loading,
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text(if (state.loading) "Entrando..." else "Entrar") }

                    state.error?.takeIf { it.isNotBlank() }?.let {
                        Text("Não foi possível entrar. Confira seus dados e tente novamente.", color = MaterialTheme.colorScheme.error)
                    }

                    if (state.hasStoredSession && (state.pinConfigured || state.biometricEnabled)) {
                        HorizontalDivider(Modifier.padding(vertical = 2.dp))
                        Text("Acesso rápido", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (state.pinConfigured) {
                            OutlinedTextField(
                                value = pin,
                                onValueChange = { pin = it.filter(Char::isDigit).take(8) },
                                label = { Text("PIN") },
                                visualTransformation = PasswordVisualTransformation(),
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth(),
                            )
                            OutlinedButton(
                                onClick = { onPin(pin) },
                                enabled = pin.length >= 4,
                                modifier = Modifier.fillMaxWidth(),
                            ) { Text("Entrar com PIN") }
                        }
                        if (state.biometricEnabled) {
                            OutlinedButton(onClick = onBiometric, modifier = Modifier.fillMaxWidth()) {
                                Text("Entrar com biometria")
                            }
                        }
                    }
                }
            }
        }

        if (missingPermissions.isNotEmpty()) {
            item {
                Spacer(Modifier.height(12.dp))
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = .48f)),
                ) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("Configurar este aparelho", fontWeight = FontWeight.Bold)
                                Text("Alguns recursos precisam de permissão.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            TextButton(onClick = { showDeviceSetup = !showDeviceSetup }) {
                                Text(if (showDeviceSetup) "Ocultar" else "Configurar")
                            }
                        }
                        if (showDeviceSetup) {
                            Button(onClick = { AppPermissionManager.request(context) }, modifier = Modifier.fillMaxWidth()) { Text("Liberar permissões") }
                            OutlinedButton(onClick = { AppPermissionManager.openSettings(context) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir ajustes do aparelho") }
                        }
                    }
                }
            }
        }
        item { Spacer(Modifier.height(30.dp)) }
    }
}
