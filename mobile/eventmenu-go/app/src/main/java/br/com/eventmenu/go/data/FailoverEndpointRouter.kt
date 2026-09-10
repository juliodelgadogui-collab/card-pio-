package br.com.eventmenu.go.data

import android.content.Context
import android.net.Uri
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.json.JSONObject

enum class ApiServerRole { PRIMARY, CONTINGENCY }

data class FailoverRoutingState(
    val primaryUrl: String,
    val contingencyUrl: String = "",
    val enabled: Boolean = false,
    val mode: String = "read_only",
    val contingencyWritable: Boolean = false,
    val clusterId: String = "",
    val configVersion: Long = 0L,
    val activeRole: ApiServerRole = ApiServerRole.PRIMARY,
)

/**
 * O endereço principal compilado no APK continua sendo a raiz de confiança.
 * Quando o usuário autentica, o servidor principal pode entregar uma rota de
 * contingência. Essa rota fica salva localmente para continuar disponível caso
 * o servidor principal caia depois.
 */
object FailoverEndpointRouter {
    private const val PREFS = "eventmenu_server_failover"
    private const val KEY_PRIMARY = "primary_url"
    private const val KEY_CONTINGENCY = "contingency_url"
    private const val KEY_ENABLED = "enabled"
    private const val KEY_MODE = "mode"
    private const val KEY_WRITABLE = "contingency_writable"
    private const val KEY_CLUSTER = "cluster_id"
    private const val KEY_VERSION = "config_version"
    private const val KEY_ACTIVE = "active_role"

    private var initialized = false
    private lateinit var appContext: Context
    private var compiledPrimary = ""
    private val lock = Any()

    private val _state = MutableStateFlow(FailoverRoutingState(primaryUrl = ""))
    val state: StateFlow<FailoverRoutingState> = _state.asStateFlow()

    @Volatile private var lastPrimaryProbeAtMs: Long = 0L

    fun initialize(context: Context, primaryBaseUrl: String) {
        synchronized(lock) {
            if (initialized) return
            appContext = context.applicationContext
            compiledPrimary = normalize(primaryBaseUrl) ?: error("URL principal inválida no build do EventMenu GO.")
            val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            val cachedPrimary = normalize(prefs.getString(KEY_PRIMARY, null)) ?: compiledPrimary
            val cachedContingency = normalize(prefs.getString(KEY_CONTINGENCY, null)).orEmpty()
            val enabled = prefs.getBoolean(KEY_ENABLED, false) && cachedContingency.isNotBlank()
            val mode = prefs.getString(KEY_MODE, "read_only").orEmpty().takeIf { it in setOf("shared_db", "read_only") } ?: "read_only"
            val writable = prefs.getBoolean(KEY_WRITABLE, false) && mode == "shared_db"
            val cluster = prefs.getString(KEY_CLUSTER, "").orEmpty()
            val version = prefs.getLong(KEY_VERSION, 0L)
            val requestedRole = runCatching {
                ApiServerRole.valueOf(prefs.getString(KEY_ACTIVE, ApiServerRole.PRIMARY.name).orEmpty())
            }.getOrDefault(ApiServerRole.PRIMARY)
            val active = if (requestedRole == ApiServerRole.CONTINGENCY && enabled) requestedRole else ApiServerRole.PRIMARY
            _state.value = FailoverRoutingState(
                primaryUrl = cachedPrimary,
                contingencyUrl = cachedContingency,
                enabled = enabled,
                mode = mode,
                contingencyWritable = writable,
                clusterId = cluster,
                configVersion = version,
                activeRole = active,
            )
            initialized = true
        }
    }

    fun currentBaseUrl(forWrite: Boolean = false): String {
        val current = _state.value
        if (current.activeRole == ApiServerRole.CONTINGENCY) {
            if (current.enabled && current.contingencyUrl.isNotBlank() && (!forWrite || current.contingencyWritable)) {
                return current.contingencyUrl
            }
            return current.primaryUrl
        }
        return current.primaryUrl
    }

    fun primaryBaseUrl(): String = _state.value.primaryUrl.ifBlank { compiledPrimary }

    fun contingencyBaseUrl(forWrite: Boolean): String? {
        val current = _state.value
        if (!current.enabled || current.contingencyUrl.isBlank()) return null
        if (forWrite && !current.contingencyWritable) return null
        return current.contingencyUrl
    }

    fun contingencyWritable(): Boolean = _state.value.enabled && _state.value.contingencyWritable
    fun activeRole(): ApiServerRole = _state.value.activeRole
    fun clusterId(): String = _state.value.clusterId

    fun updateFromRouting(root: JSONObject) {
        ensureInitialized()
        val routing = root.optJSONObject("routing") ?: return
        val incomingVersion = routing.optLong("config_version", 0L)
        val current = _state.value
        if (incomingVersion in 1 until current.configVersion) return

        val primary = normalize(routing.optString("primary_url")) ?: current.primaryUrl.ifBlank { compiledPrimary }
        val enabled = routing.optBoolean("enabled", false)
        val contingency = if (enabled) normalize(routing.optString("contingency_url")).orEmpty() else ""
        val mode = routing.optString("mode", "read_only").takeIf { it in setOf("shared_db", "read_only") } ?: "read_only"
        val writable = enabled && mode == "shared_db" && routing.optBoolean("contingency_writable", false)
        val cluster = routing.optString("cluster_id").trim()
        val safeEnabled = enabled && contingency.isNotBlank() && cluster.isNotBlank() && !same(primary, contingency)
        val active = if (current.activeRole == ApiServerRole.CONTINGENCY && safeEnabled) ApiServerRole.CONTINGENCY else ApiServerRole.PRIMARY

        val updated = FailoverRoutingState(
            primaryUrl = primary,
            contingencyUrl = if (safeEnabled) contingency else "",
            enabled = safeEnabled,
            mode = mode,
            contingencyWritable = safeEnabled && writable,
            clusterId = if (safeEnabled) cluster else "",
            configVersion = incomingVersion.coerceAtLeast(current.configVersion),
            activeRole = active,
        )
        persist(updated)
        _state.value = updated
    }

    fun activate(role: ApiServerRole) {
        ensureInitialized()
        synchronized(lock) {
            val current = _state.value
            if (role == ApiServerRole.CONTINGENCY && (!current.enabled || current.contingencyUrl.isBlank())) return
            val updated = current.copy(activeRole = role)
            persist(updated)
            _state.value = updated
        }
    }

    fun roleFor(baseUrl: String): ApiServerRole {
        val normalized = normalize(baseUrl).orEmpty()
        return if (_state.value.contingencyUrl.isNotBlank() && same(normalized, _state.value.contingencyUrl)) {
            ApiServerRole.CONTINGENCY
        } else {
            ApiServerRole.PRIMARY
        }
    }

    fun oppositeBaseUrl(currentBaseUrl: String, forWrite: Boolean): String? {
        val role = roleFor(currentBaseUrl)
        return if (role == ApiServerRole.PRIMARY) {
            contingencyBaseUrl(forWrite)
        } else {
            primaryBaseUrl()
        }
    }

    fun shouldProbePrimary(nowMs: Long = System.currentTimeMillis()): Boolean {
        if (_state.value.activeRole != ApiServerRole.CONTINGENCY) return false
        return nowMs - lastPrimaryProbeAtMs >= 60_000L
    }

    fun markPrimaryProbe(nowMs: Long = System.currentTimeMillis()) {
        lastPrimaryProbeAtMs = nowMs
    }

    private fun persist(state: FailoverRoutingState) {
        if (!initialized && !::appContext.isInitialized) return
        appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_PRIMARY, state.primaryUrl)
            .putString(KEY_CONTINGENCY, state.contingencyUrl)
            .putBoolean(KEY_ENABLED, state.enabled)
            .putString(KEY_MODE, state.mode)
            .putBoolean(KEY_WRITABLE, state.contingencyWritable)
            .putString(KEY_CLUSTER, state.clusterId)
            .putLong(KEY_VERSION, state.configVersion)
            .putString(KEY_ACTIVE, state.activeRole.name)
            .apply()
    }

    private fun ensureInitialized() {
        check(initialized) { "FailoverEndpointRouter não inicializado." }
    }

    private fun same(a: String, b: String): Boolean = a.trimEnd('/').equals(b.trimEnd('/'), ignoreCase = true)

    private fun normalize(value: String?): String? {
        val clean = value?.trim()?.trimEnd('/').orEmpty()
        if (clean.isBlank()) return null
        val uri = runCatching { Uri.parse(clean) }.getOrNull() ?: return null
        val host = uri.host.orEmpty()
        val local = host == "localhost" || host == "127.0.0.1" || host == "::1"
        if (!uri.scheme.equals("https", ignoreCase = true) && !local) return null
        if (host.isBlank()) return null
        if (!uri.query.isNullOrBlank() || !uri.fragment.isNullOrBlank()) return null
        return clean
    }
}
