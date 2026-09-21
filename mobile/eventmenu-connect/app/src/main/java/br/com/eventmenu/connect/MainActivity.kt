package br.com.eventmenu.connect

import android.accessibilityservice.AccessibilityService
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.provider.Settings
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
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
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

private val EventRed = Color(0xFFE31C24)
private val EventDark = Color(0xFF15171A)
private val EventGreen = Color(0xFF1FAD5B)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 300)
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
    val context = LocalContext.current
    var loggedIn by remember { mutableStateOf(SessionStore(context).hasSession()) }
    if (loggedIn) {
        DashboardScreen(onLogout = { loggedIn = false })
    } else {
        LoginScreen(onLoggedIn = { loggedIn = true })
    }
}

@Composable
private fun LoginScreen(onLoggedIn: () -> Unit) {
    val context = LocalContext.current
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
            Text("Conecte o WhatsApp do seu restaurante ao EventMenu.", color = Color(0xFF666A70))
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
    val context = LocalContext.current
    val store = remember { SessionStore(context) }
    val scope = rememberCoroutineScope()
    var accessibility by remember { mutableStateOf(isAccessibilityEnabled(context)) }
    var whatsapp by remember { mutableStateOf(isWhatsAppInstalled(context)) }
    var status by remember { mutableStateOf(store.runtimeStatus()) }
    var pending by remember { mutableStateOf(store.runtimePending()) }
    var lastError by remember { mutableStateOf(store.runtimeError()) }
    var lastSync by remember { mutableStateOf(store.runtimeLastSync()) }

    LaunchedEffect(Unit) {
        while (true) {
            accessibility = isAccessibilityEnabled(context)
            whatsapp = isWhatsAppInstalled(context)
            status = store.runtimeStatus()
            pending = store.runtimePending()
            lastError = store.runtimeError()
            lastSync = store.runtimeLastSync()
            delay(1000)
        }
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Spacer(Modifier.height(8.dp))
        Text("EventMenu Connect", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black, color = EventDark)
        Text(store.tenantName().ifBlank { "Seu restaurante" }, style = MaterialTheme.typography.titleMedium, color = Color(0xFF5F6368))

        StatusCard(
            title = "WhatsApp instalado",
            ok = whatsapp,
            detail = if (whatsapp) "WhatsApp encontrado neste aparelho" else "Instale WhatsApp ou WhatsApp Business",
        )
        StatusCard(
            title = "Permissão de automação",
            ok = accessibility,
            detail = if (accessibility) "Ativa" else "Precisa ser ativada uma única vez",
        )
        StatusCard(
            title = "Serviço EventMenu",
            ok = status == "connected",
            detail = when (status) {
                "connected" -> "Ativo em segundo plano"
                "error" -> "Erro: ${lastError.ifBlank { "verifique a configuração" }}"
                else -> "Aguardando ativação"
            },
        )

        Card(
            colors = CardDefaults.cardColors(containerColor = Color.White),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Fila do WhatsApp", fontWeight = FontWeight.Bold)
                Text("Mensagens pendentes: $pending")
                Text("Última sincronização: ${if (lastSync.isBlank()) "ainda não sincronizado" else lastSync}", color = Color(0xFF70747A))
            }
        }

        if (!accessibility) {
            Button(
                onClick = { context.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)) },
                modifier = Modifier.fillMaxWidth().height(54.dp),
                colors = ButtonDefaults.buttonColors(containerColor = EventRed),
            ) {
                Icon(Icons.Default.Settings, contentDescription = null)
                Spacer(Modifier.padding(4.dp))
                Text("Ativar EventMenu Connect", fontWeight = FontWeight.Bold)
            }
        } else {
            Button(
                onClick = { startConnectService(context) },
                enabled = whatsapp,
                modifier = Modifier.fillMaxWidth().height(54.dp),
                colors = ButtonDefaults.buttonColors(containerColor = EventRed),
            ) {
                Icon(Icons.Default.Refresh, contentDescription = null)
                Spacer(Modifier.padding(4.dp))
                Text("Iniciar / Reconectar", fontWeight = FontWeight.Bold)
            }
        }

        Text(
            "O app usa o WhatsApp já instalado neste Android. Quando existir uma mensagem na fila do EventMenu, o Connect abre a conversa, envia e confirma ao servidor. Não usa VPS.",
            style = MaterialTheme.typography.bodySmall,
            color = Color(0xFF777B80),
        )

        OutlinedButton(
            onClick = {
                scope.launch {
                    withContext(Dispatchers.IO) { runCatching { EventMenuApi(context).logout() } }
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

private fun startConnectService(context: Context) {
    ContextCompat.startForegroundService(context, Intent(context, ConnectWorkerService::class.java))
}

private fun isWhatsAppInstalled(context: Context): Boolean =
    context.packageManager.getLaunchIntentForPackage("com.whatsapp.w4b") != null ||
        context.packageManager.getLaunchIntentForPackage("com.whatsapp") != null

private fun isAccessibilityEnabled(context: Context): Boolean {
    val enabled = Settings.Secure.getString(context.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES) ?: return false
    val expected = ComponentName(context, WhatsAppAccessibilityService::class.java).flattenToString()
    return enabled.split(':').any { it.equals(expected, ignoreCase = true) }
}

private class SessionStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences("eventmenu_connect", Context.MODE_PRIVATE)

    fun deviceId(): String {
        val current = prefs.getString("device_id", "").orEmpty()
        if (current.length >= 8) return current
        val created = "android-connect-" + UUID.randomUUID().toString()
        prefs.edit().putString("device_id", created).apply()
        return created
    }

    fun saveAuth(token: String, refresh: String, user: JSONObject) {
        prefs.edit()
            .putString("token", token)
            .putString("refresh_token", refresh)
            .putString("user_name", user.optString("name"))
            .putString("tenant_name", user.optString("tenant_name"))
            .apply()
    }

    fun updateTokens(token: String, refresh: String) {
        prefs.edit().putString("token", token).putString("refresh_token", refresh).apply()
    }

    fun token(): String = prefs.getString("token", "").orEmpty()
    fun refreshToken(): String = prefs.getString("refresh_token", "").orEmpty()
    fun tenantName(): String = prefs.getString("tenant_name", "").orEmpty()
    fun hasSession(): Boolean = token().length >= 32 && refreshToken().length >= 32
    fun clearAuth() { prefs.edit().clear().apply() }

    fun setRuntime(status: String, pending: Int = runtimePending(), error: String = "") {
        prefs.edit()
            .putString("runtime_status", status)
            .putInt("runtime_pending", pending)
            .putString("runtime_error", error)
            .putString("runtime_last_sync", java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale("pt", "BR")).format(java.util.Date()))
            .apply()
    }

    fun runtimeStatus(): String = prefs.getString("runtime_status", "disconnected").orEmpty()
    fun runtimePending(): Int = prefs.getInt("runtime_pending", 0)
    fun runtimeError(): String = prefs.getString("runtime_error", "").orEmpty()
    fun runtimeLastSync(): String = prefs.getString("runtime_last_sync", "").orEmpty()
}

private class ApiException(val status: Int, message: String) : Exception(message)

private class EventMenuApi(private val context: Context) {
    private val store = SessionStore(context)
    private val base = BuildConfig.API_BASE_URL

    fun login(email: String, password: String) {
        val body = JSONObject()
            .put("email", email)
            .put("password", password)
            .put("device_id", store.deviceId())
            .put("device_label", "EventMenu Connect Android - ${Build.MANUFACTURER} ${Build.MODEL}")
        val result = request("api.php?action=login", "POST", body, auth = false)
        val token = result.optString("token")
        val refresh = result.optString("refresh_token")
        if (token.length < 32 || refresh.length < 32) throw Exception("O servidor não retornou uma sessão válida.")
        store.saveAuth(token, refresh, result.optJSONObject("user") ?: JSONObject())
    }

    fun logout() {
        runCatching { request("api.php?action=logout", "POST", JSONObject(), auth = true) }
    }

    fun heartbeat(status: String): JSONObject {
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("device_label", "EventMenu Connect Android - ${Build.MANUFACTURER} ${Build.MODEL}")
            .put("status", status)
            .put("phone", "")
            .put("error", "")
        return agentRequest("heartbeat", "POST", body)
    }

    fun claim(limit: Int = 1): JSONArray {
        val body = JSONObject().put("device_id", store.deviceId()).put("limit", limit)
        return agentRequest("claim", "POST", body).optJSONArray("messages") ?: JSONArray()
    }

    fun ack(id: Int, claimToken: String) {
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("id", id)
            .put("claim_token", claimToken)
            .put("external_message_id", "android-local")
        agentRequest("ack", "POST", body)
    }

    fun fail(id: Int, claimToken: String, error: String) {
        val body = JSONObject()
            .put("device_id", store.deviceId())
            .put("id", id)
            .put("claim_token", claimToken)
            .put("error", error.take(450))
        agentRequest("fail", "POST", body)
    }

    private fun agentRequest(action: String, method: String, body: JSONObject): JSONObject {
        return try {
            request("api-whatsapp-desktop.php?action=$action", method, body, auth = true)
        } catch (e: ApiException) {
            if (e.status == 401 && refresh()) request("api-whatsapp-desktop.php?action=$action", method, body, auth = true) else throw e
        }
    }

    private fun refresh(): Boolean {
        val refresh = store.refreshToken()
        if (refresh.length < 32) return false
        return try {
            val body = JSONObject().put("refresh_token", refresh).put("device_id", store.deviceId())
            val result = request("api.php?action=refresh", "POST", body, auth = false)
            val token = result.optString("token")
            val nextRefresh = result.optString("refresh_token")
            if (token.length < 32 || nextRefresh.length < 32) false
            else {
                store.updateTokens(token, nextRefresh)
                true
            }
        } catch (_: Exception) {
            false
        }
    }

    private fun request(path: String, method: String, body: JSONObject?, auth: Boolean): JSONObject {
        val connection = (URL(base + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 10_000
            readTimeout = 18_000
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("X-Device-Id", store.deviceId())
            setRequestProperty("User-Agent", "EventMenu-Connect-Android/${BuildConfig.VERSION_NAME}")
            if (auth) setRequestProperty("Authorization", "Bearer ${store.token()}")
            if (body != null && method != "GET") doOutput = true
        }
        if (body != null && method != "GET") connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        val code = connection.responseCode
        val raw = runCatching {
            (if (code in 200..299) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() }
        }.getOrNull().orEmpty()
        connection.disconnect()
        val json = runCatching { if (raw.isBlank()) JSONObject() else JSONObject(raw) }.getOrElse { JSONObject() }
        if (code !in 200..299) throw ApiException(code, json.optString("error").ifBlank { "Erro HTTP $code" })
        if (json.has("ok") && !json.optBoolean("ok", false)) throw ApiException(code, json.optString("error").ifBlank { "Falha no servidor." })
        return json
    }
}

class ConnectWorkerService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var worker: Job? = null
    private var lastHeartbeat = 0L

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        startForeground(1010, notification("Aguardando mensagens do EventMenu"))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (worker?.isActive != true) worker = scope.launch { loop() }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    private suspend fun loop() {
        val store = SessionStore(this)
        val api = EventMenuApi(this)
        while (scope.isActive) {
            if (!store.hasSession()) {
                stopSelf()
                break
            }
            val automation = WhatsAppAccessibilityService.instance
            if (!isAccessibilityEnabled(this) || !isWhatsAppInstalled(this) || automation == null) {
                store.setRuntime("disconnected", error = "Ative a permissão do EventMenu Connect e mantenha o WhatsApp instalado.")
                delay(5_000)
                continue
            }
            try {
                val now = System.currentTimeMillis()
                if (now - lastHeartbeat > 20_000) {
                    val hb = api.heartbeat("connected")
                    store.setRuntime("connected", hb.optInt("pending", store.runtimePending()), "")
                    lastHeartbeat = now
                }
                val messages = api.claim(1)
                if (messages.length() == 0) {
                    delay(4_000)
                    continue
                }
                val message = messages.getJSONObject(0)
                val id = message.optInt("id")
                val claimToken = message.optString("claim_token")
                val recipient = message.optString("recipient")
                val text = message.optString("message_text")
                val sent = SendCoordinator.send(automation, recipient, text)
                if (sent) {
                    api.ack(id, claimToken)
                    store.setRuntime("connected", pending = (store.runtimePending() - 1).coerceAtLeast(0), error = "")
                } else {
                    api.fail(id, claimToken, "O Android não conseguiu concluir o envio pelo WhatsApp.")
                    store.setRuntime("error", error = "Não foi possível concluir um envio. O sistema tentará novamente.")
                }
                delay(1_500)
            } catch (e: Exception) {
                store.setRuntime("error", error = e.message ?: "Falha de sincronização")
                delay(8_000)
            }
        }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(NotificationChannel("eventmenu_connect", "EventMenu Connect", NotificationManager.IMPORTANCE_LOW))
        }
    }

    private fun notification(text: String): android.app.Notification {
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, "eventmenu_connect")
            .setSmallIcon(R.drawable.ic_eventmenu_connect)
            .setContentTitle("EventMenu Connect")
            .setContentText(text)
            .setOngoing(true)
            .setContentIntent(pendingIntent)
            .build()
    }
}

private object SendCoordinator {
    private val mutex = Mutex()
    @Volatile private var waiter: CompletableDeferred<Boolean>? = null

    suspend fun send(service: WhatsAppAccessibilityService, phone: String, text: String): Boolean = mutex.withLock {
        val deferred = CompletableDeferred<Boolean>()
        waiter = deferred
        if (!service.openConversation(phone, text)) {
            waiter = null
            return@withLock false
        }
        val result = withTimeoutOrNull(30_000) { deferred.await() } ?: false
        waiter = null
        result
    }

    fun hasPending(): Boolean = waiter?.isActive == true
    fun complete(success: Boolean) { waiter?.complete(success) }
}

class WhatsAppAccessibilityService : AccessibilityService() {
    companion object {
        @Volatile var instance: WhatsAppAccessibilityService? = null
            private set
    }

    override fun onServiceConnected() {
        super.onServiceConnected()
        instance = this
        if (SessionStore(this).hasSession()) startConnectService(this)
    }

    override fun onDestroy() {
        if (instance === this) instance = null
        SendCoordinator.complete(false)
        super.onDestroy()
    }

    override fun onInterrupt() { SendCoordinator.complete(false) }

    fun openConversation(rawPhone: String, text: String): Boolean {
        val phone = normalizePhone(rawPhone) ?: return false
        val pkg = when {
            packageManager.getLaunchIntentForPackage("com.whatsapp.w4b") != null -> "com.whatsapp.w4b"
            packageManager.getLaunchIntentForPackage("com.whatsapp") != null -> "com.whatsapp"
            else -> return false
        }
        return try {
            val uri = Uri.parse("https://wa.me/$phone?text=${Uri.encode(text)}")
            startActivity(Intent(Intent.ACTION_VIEW, uri).setPackage(pkg).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            true
        } catch (_: Exception) {
            false
        }
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (!SendCoordinator.hasPending()) return
        val pkg = event?.packageName?.toString().orEmpty()
        if (pkg != "com.whatsapp" && pkg != "com.whatsapp.w4b") return
        val root = rootInActiveWindow ?: return
        val direct = runCatching { root.findAccessibilityNodeInfosByViewId("$pkg:id/send") }.getOrNull().orEmpty()
        val node = direct.firstOrNull { it.isClickable } ?: findSendNode(root)
        if (node != null && node.performAction(AccessibilityNodeInfo.ACTION_CLICK)) {
            SendCoordinator.complete(true)
            Handler(Looper.getMainLooper()).postDelayed({ performGlobalAction(GLOBAL_ACTION_BACK) }, 900)
        }
    }

    private fun findSendNode(node: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        val label = listOfNotNull(node.text?.toString(), node.contentDescription?.toString()).joinToString(" ").lowercase()
        if (node.isClickable && (label.contains("enviar") || label == "send" || label.contains("send message"))) return node
        for (i in 0 until node.childCount) {
            val child = node.getChild(i) ?: continue
            val found = findSendNode(child)
            if (found != null) return found
        }
        return null
    }

    private fun normalizePhone(value: String): String? {
        var digits = value.filter { it.isDigit() }.trimStart('0')
        if (digits.length == 10 || digits.length == 11) digits = "55$digits"
        return digits.takeIf { it.length in 12..15 }
    }
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action == Intent.ACTION_BOOT_COMPLETED && SessionStore(context).hasSession()) {
            startConnectService(context)
        }
    }
}
