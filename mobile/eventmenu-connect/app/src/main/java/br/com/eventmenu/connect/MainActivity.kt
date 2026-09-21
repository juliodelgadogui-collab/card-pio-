package br.com.eventmenu.connect

import android.Manifest
import android.app.Activity
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.QrCode2
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val EventRed = Color(0xFFE31C24)
private val EventDark = Color(0xFF15171A)
private val EventGreen = Color(0xFF1FAD5B)
private val EventMuted = Color(0xFF666A70)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 300)
        }
        setContent {
            MaterialTheme(
                colorScheme = lightColorScheme(
                    primary = EventRed,
                    onPrimary = Color.White,
                    secondary = EventDark,
                    surface = Color.White,
                    background = Color(0xFFF7F7F8),
                )
            ) {
                Surface(modifier = Modifier.fillMaxSize()) { EventMenuConnectApp() }
            }
        }
    }
}

@Composable
private fun EventMenuConnectApp() {
    val context = androidx.compose.ui.platform.LocalContext.current
    var loggedIn by remember { mutableStateOf(SessionStore(context).hasSession()) }
    if (loggedIn) DashboardScreen(onLogout = { loggedIn = false })
    else LoginScreen(onLoggedIn = { loggedIn = true })
}

@Composable
private fun LoginScreen(onLoggedIn: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }

    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(Icons.Default.Link, contentDescription = null, tint = EventRed, modifier = Modifier.height(70.dp))
            Spacer(Modifier.height(16.dp))
            Text("EventMenu", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black, color = EventDark)
            Text("Connect", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Black, color = EventRed)
            Spacer(Modifier.height(8.dp))
            Text("WhatsApp do seu restaurante conectado sem abrir o aplicativo durante os envios.", color = EventMuted, textAlign = TextAlign.Center)
            Spacer(Modifier.height(28.dp))
            OutlinedTextField(
                value = email,
                onValueChange = { email = it; error = "" },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("E-mail") },
                singleLine = true,
                leadingIcon = { Icon(Icons.Default.PhoneAndroid, contentDescription = null) },
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = password,
                onValueChange = { password = it; error = "" },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Senha") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                leadingIcon = { Icon(Icons.Default.Lock, contentDescription = null) },
            )
            if (error.isNotBlank()) {
                Spacer(Modifier.height(12.dp))
                Text(error, color = EventRed, style = MaterialTheme.typography.bodyMedium)
            }
            Spacer(Modifier.height(18.dp))
            Button(
                onClick = {
                    if (email.isBlank() || password.isBlank()) {
                        error = "Informe o e-mail e a senha."
                        return@Button
                    }
                    loading = true
                    scope.launch {
                        try {
                            withContext(Dispatchers.IO) { EventMenuApi(context).login(email.trim(), password) }
                            startConnectService(context)
                            error = ""
                            onLoggedIn()
                        } catch (e: Exception) {
                            error = e.message ?: "Não foi possível entrar."
                        } finally {
                            loading = false
                        }
                    }
                },
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(14.dp),
                colors = ButtonDefaults.buttonColors(containerColor = EventRed),
            ) {
                if (loading) CircularProgressIndicator(color = Color.White, modifier = Modifier.height(24.dp))
                else Text("Entrar", fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.height(14.dp))
            Text("Acesso vinculado ao seu restaurante", style = MaterialTheme.typography.bodySmall, color = Color(0xFF8A8D92))
        }
    }
}

@Composable
private fun DashboardScreen(onLogout: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val store = remember { SessionStore(context) }
    val scope = rememberCoroutineScope()
    var status by remember { mutableStateOf(store.runtimeStatus()) }
    var pending by remember { mutableStateOf(store.runtimePending()) }
    var lastError by remember { mutableStateOf(store.runtimeError()) }
    var lastSync by remember { mutableStateOf(store.runtimeLastSync()) }
    var phone by remember { mutableStateOf(store.runtimePhone()) }
    var pairingCode by remember { mutableStateOf(store.runtimePairingCode()) }
    var qr by remember { mutableStateOf(store.runtimeQr()) }
    var phoneInput by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        startConnectService(context)
        while (true) {
            status = store.runtimeStatus()
            pending = store.runtimePending()
            lastError = store.runtimeError()
            lastSync = store.runtimeLastSync()
            phone = store.runtimePhone()
            pairingCode = store.runtimePairingCode()
            qr = store.runtimeQr()
            delay(750)
        }
    }

    val connected = status.equals("connected", true)
    val statusLabel = when (status.lowercase()) {
        "connected" -> "Conectado"
        "pairing" -> "Aguardando código"
        "qr" -> "Aguardando QR Code"
        "starting" -> "Iniciando"
        "reconnecting" -> "Reconectando"
        "error" -> "Erro"
        else -> "Desconectado"
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Spacer(Modifier.height(8.dp))
        Text("EventMenu Connect", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black, color = EventDark)
        Text(store.tenantName().ifBlank { "Seu restaurante" }, style = MaterialTheme.typography.titleMedium, color = Color(0xFF5F6368))

        StatusCard(
            title = "Conexão do WhatsApp",
            ok = connected,
            detail = if (connected) "Ativa em segundo plano${if (phone.isNotBlank()) " • ${formatPhone(phone)}" else ""}" else statusLabel,
        )
        StatusCard(
            title = "Envio silencioso",
            ok = status != "error",
            detail = "O EventMenu envia sem abrir o WhatsApp e sem usar Acessibilidade.",
        )

        Card(colors = CardDefaults.cardColors(containerColor = Color.White), modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Fila do WhatsApp", fontWeight = FontWeight.Bold)
                Text("Mensagens pendentes: $pending")
                Text("Última sincronização: ${if (lastSync.isBlank()) "ainda não sincronizado" else lastSync}", color = Color(0xFF70747A))
            }
        }

        if (!connected) {
            Card(colors = CardDefaults.cardColors(containerColor = Color.White), modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Conectar WhatsApp", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Text("Use o código de conexão. É a melhor opção quando o WhatsApp está neste mesmo celular.", color = EventMuted)
                    OutlinedTextField(
                        value = phoneInput,
                        onValueChange = { phoneInput = it.filter { ch -> ch.isDigit() || ch == '+' || ch == '(' || ch == ')' || ch == ' ' || ch == '-' } },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Número do WhatsApp com DDD") },
                        placeholder = { Text("(22) 99999-9999") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                        leadingIcon = { Icon(Icons.Default.PhoneAndroid, contentDescription = null) },
                    )
                    Button(
                        onClick = {
                            if (phoneInput.filter(Char::isDigit).length < 10) return@Button
                            busy = true
                            startConnectService(context, ConnectWorkerService.ACTION_PAIR, phoneInput)
                            scope.launch { delay(2500); busy = false }
                        },
                        enabled = !busy && phoneInput.filter(Char::isDigit).length >= 10,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = EventRed),
                    ) {
                        if (busy) CircularProgressIndicator(color = Color.White, modifier = Modifier.height(22.dp))
                        else {
                            Icon(Icons.Default.Link, contentDescription = null)
                            Spacer(Modifier.padding(4.dp))
                            Text("Gerar código de conexão", fontWeight = FontWeight.Bold)
                        }
                    }
                    OutlinedButton(
                        onClick = { startConnectService(context, ConnectWorkerService.ACTION_QR) },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                    ) {
                        Icon(Icons.Default.QrCode2, contentDescription = null)
                        Spacer(Modifier.padding(4.dp))
                        Text("Usar QR Code")
                    }
                }
            }
        }

        if (pairingCode.isNotBlank() && !connected) {
            Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF7ED)), modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Código de conexão", fontWeight = FontWeight.Bold, color = Color(0xFF9A3412))
                    Text(formatPairingCode(pairingCode), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black, color = EventDark)
                    Text("No WhatsApp, abra Aparelhos conectados → Conectar aparelho → Conectar com número de telefone e digite este código.", textAlign = TextAlign.Center, color = Color(0xFF7C4A22))
                    OutlinedButton(onClick = { copyText(context, pairingCode) }) {
                        Icon(Icons.Default.ContentCopy, contentDescription = null)
                        Spacer(Modifier.padding(4.dp))
                        Text("Copiar código")
                    }
                }
            }
        }

        if (qr.isNotBlank() && !connected) {
            val bitmap = remember(qr) { runCatching { makeQrBitmap(qr) }.getOrNull() }
            if (bitmap != null) {
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("QR Code", fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(12.dp))
                        Image(bitmap = bitmap.asImageBitmap(), contentDescription = "QR Code do WhatsApp", modifier = Modifier.fillMaxWidth())
                        Spacer(Modifier.height(8.dp))
                        Text("WhatsApp → Aparelhos conectados → Conectar aparelho", color = EventMuted, textAlign = TextAlign.Center)
                    }
                }
            }
        }

        if (connected) {
            Button(
                onClick = { startConnectService(context, ConnectWorkerService.ACTION_START) },
                modifier = Modifier.fillMaxWidth().height(52.dp),
                colors = ButtonDefaults.buttonColors(containerColor = EventGreen),
            ) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.padding(4.dp))
                Text("Atualizar conexão", fontWeight = FontWeight.Bold)
            }
            OutlinedButton(
                onClick = { startConnectService(context, ConnectWorkerService.ACTION_LOGOUT_WHATSAPP) },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Desconectar WhatsApp") }
        }

        if (lastError.isNotBlank()) {
            Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF0F1)), modifier = Modifier.fillMaxWidth()) {
                Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Warning, contentDescription = null, tint = EventRed)
                    Spacer(Modifier.padding(6.dp))
                    Text(lastError, color = Color(0xFF963742))
                }
            }
        }

        Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFECFDF5)), modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("Não interrompe o uso do celular", fontWeight = FontWeight.Bold, color = Color(0xFF166534))
                Text("As mensagens são enviadas pelo mecanismo interno do EventMenu Connect. Ele não abre o aplicativo oficial do WhatsApp e não clica na tela.", color = Color(0xFF15803D))
            }
        }

        HorizontalDivider()
        OutlinedButton(
            onClick = {
                scope.launch {
                    withContext(Dispatchers.IO) {
                        runCatching { EmbeddedWhatsAppEngine(context).logout() }
                        runCatching { EventMenuApi(context).logout() }
                    }
                    context.stopService(Intent(context, ConnectWorkerService::class.java))
                    store.clearAuth()
                    onLogout()
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Icon(Icons.Default.Logout, contentDescription = null)
            Spacer(Modifier.padding(4.dp))
            Text("Sair")
        }
        Spacer(Modifier.height(18.dp))
    }
}

@Composable
private fun StatusCard(title: String, ok: Boolean, detail: String) {
    Card(colors = CardDefaults.cardColors(containerColor = Color.White), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(
                if (ok) Icons.Default.CheckCircle else Icons.Default.Warning,
                contentDescription = null,
                tint = if (ok) EventGreen else Color(0xFFE58A00),
            )
            Spacer(Modifier.padding(7.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Bold)
                Text(detail, style = MaterialTheme.typography.bodySmall, color = Color(0xFF6C7075))
            }
        }
    }
}

private fun makeQrBitmap(value: String): Bitmap {
    val size = 720
    val matrix = QRCodeWriter().encode(value, BarcodeFormat.QR_CODE, size, size)
    val pixels = IntArray(size * size)
    for (y in 0 until size) {
        for (x in 0 until size) {
            pixels[y * size + x] = if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE
        }
    }
    return Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888).apply { setPixels(pixels, 0, size, 0, 0, size, size) }
}

private fun formatPairingCode(value: String): String {
    val compact = value.filter(Char::isLetterOrDigit).uppercase()
    return compact.chunked(4).joinToString("-")
}

private fun copyText(context: Context, value: String) {
    val manager = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    manager.setPrimaryClip(ClipData.newPlainText("Código EventMenu Connect", value))
}

private fun formatPhone(value: String): String {
    val digits = value.filter(Char::isDigit)
    if (digits.startsWith("55") && digits.length >= 12) {
        val ddd = digits.substring(2, 4)
        val number = digits.substring(4)
        if (number.length == 9) return "+55 ($ddd) ${number.take(5)}-${number.drop(5)}"
    }
    return if (digits.isBlank()) "" else "+$digits"
}
