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
    @Volatile private var lastPrimaryProbeAtMs = 0L

    fun initialize(context: Context, primaryBaseUrl: String) = synchronized(lock) {
        if (initialized) return@synchronized
        appContext = context.applicationContext
        compiledPrimary = normalize(primaryBaseUrl) ?: error("URL principal inválida no build do EventMenu GO.")
        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val primary = normalize(prefs.getString(KEY_PRIMARY, null)) ?: compiledPrimary
        val contingency = normalize(prefs.getString(KEY_CONTINGENCY, null)).orEmpty()
        val enabled = prefs.getBoolean(KEY_ENABLED, false) && contingency.isNotBlank()
        val mode = prefs.getString(KEY_MODE, "read_only").orEmpty().takeIf { it in setOf("shared_db", "read_only") } ?: "read_only"
        val writable = prefs.getBoolean(KEY_WRITABLE, false) && mode == "shared_db"
        val role = runCatching { ApiServerRole.valueOf(prefs.getString(KEY_ACTIVE, ApiServerRole.PRIMARY.name).orEmpty()) }.getOrDefault(ApiServerRole.PRIMARY)
        _state.value = FailoverRoutingState(
            primaryUrl = primary,
            contingencyUrl = contingency,
            enabled = enabled,
            mode = mode,
            contingencyWritable = writable,
            clusterId = prefs.getString(KEY_CLUSTER, "").orEmpty(),
            configVersion = prefs.getLong(KEY_VERSION, 0L),
            activeRole = if (role == ApiServerRole.CONTINGENCY && enabled) role else ApiServerRole.PRIMARY,
        )
        initialized = true
    }

    fun currentBaseUrl(forWrite: Boolean = false): String {
        val s = _state.value
        return if (s.activeRole == ApiServerRole.CONTINGENCY && s.enabled && s.contingencyUrl.isNotBlank() && (!forWrite || s.contingencyWritable)) s.contingencyUrl else s.primaryUrl
    }

    fun primaryBaseUrl(): String = _state.value.primaryUrl.ifBlank { compiledPrimary }
    fun contingencyBaseUrl(forWrite: Boolean): String? = _state.value.let { s -> if (!s.enabled || s.contingencyUrl.isBlank() || (forWrite && !s.contingencyWritable)) null else s.contingencyUrl }
    fun contingencyWritable(): Boolean = _state.value.enabled && _state.value.contingencyWritable
    fun activeRole(): ApiServerRole = _state.value.activeRole
    fun clusterId(): String = _state.value.clusterId

    fun updateFromRouting(root: JSONObject) {
        ensureInitialized()
        val routing = root.optJSONObject("routing") ?: return
        val current = _state.value
        val incomingVersion = routing.optLong("config_version", 0L)
        if (current.configVersion > 0L && incomingVersion < current.configVersion) return

        val primary = normalize(routing.optString("primary_url")) ?: current.primaryUrl.ifBlank { compiledPrimary }
        val enabled = routing.optBoolean("enabled", false)
        val contingency = if (enabled) normalize(routing.optString("contingency_url")).orEmpty() else ""
        val mode = routing.optString("mode", "read_only").takeIf { it in setOf("shared_db", "read_only") } ?: "read_only"
        val writable = enabled && mode == "shared_db" && routing.optBoolean("contingency_writable", false)
        val cluster = routing.optString("cluster_id").trim()
        val safeEnabled = enabled && contingency.isNotBlank() && cluster.isNotBlank() && !same(primary, contingency)
        val updated = FailoverRoutingState(
            primaryUrl = primary,
            contingencyUrl = if (safeEnabled) contingency else "",
            enabled = safeEnabled,
            mode = mode,
            contingencyWritable = safeEnabled && writable,
            clusterId = if (safeEnabled) cluster else "",
            configVersion = incomingVersion.coerceAtLeast(current.configVersion),
            activeRole = if (current.activeRole == ApiServerRole.CONTINGENCY && safeEnabled) ApiServerRole.CONTINGENCY else ApiServerRole.PRIMARY,
        )
        persist(updated)
        _state.value = updated
    }

    fun activate(role: ApiServerRole) = synchronized(lock) {
        ensureInitialized()
        val current = _state.value
        if (role == ApiServerRole.CONTINGENCY && (!current.enabled || current.contingencyUrl.isBlank())) return@synchronized
        val updated = current.copy(activeRole = role)
        persist(updated)
        _state.value = updated
    }

    fun roleFor(baseUrl: String): ApiServerRole {
        val normalized = normalize(baseUrl).orEmpty()
        return if (_state.value.contingencyUrl.isNotBlank() && same(normalized, _state.value.contingencyUrl)) ApiServerRole.CONTINGENCY else ApiServerRole.PRIMARY
    }

    fun oppositeBaseUrl(currentBaseUrl: String, forWrite: Boolean): String? =
        if (roleFor(currentBaseUrl) == ApiServerRole.PRIMARY) contingencyBaseUrl(forWrite) else primaryBaseUrl()

    fun shouldProbePrimary(nowMs: Long = System.currentTimeMillis()): Boolean = _state.value.activeRole == ApiServerRole.CONTINGENCY && nowMs - lastPrimaryProbeAtMs >= 60_000L
    fun markPrimaryProbe(nowMs: Long = System.currentTimeMillis()) { lastPrimaryProbeAtMs = nowMs }

    private fun persist(s: FailoverRoutingState) {
        appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_PRIMARY, s.primaryUrl).putString(KEY_CONTINGENCY, s.contingencyUrl)
            .putBoolean(KEY_ENABLED, s.enabled).putString(KEY_MODE, s.mode)
            .putBoolean(KEY_WRITABLE, s.contingencyWritable).putString(KEY_CLUSTER, s.clusterId)
            .putLong(KEY_VERSION, s.configVersion).putString(KEY_ACTIVE, s.activeRole.name).apply()
    }

    private fun ensureInitialized() = check(initialized) { "FailoverEndpointRouter não inicializado." }
    private fun same(a: String, b: String): Boolean = a.trimEnd('/').equals(b.trimEnd('/'), ignoreCase = true)
    private fun normalize(value: String?): String? {
        val clean = value?.trim()?.trimEnd('/').orEmpty()
        if (clean.isBlank()) return null
        val uri = runCatching { Uri.parse(clean) }.getOrNull() ?: return null
        val host = uri.host.orEmpty()
        val local = host == "localhost" || host == "127.0.0.1" || host == "::1"
        if (!uri.scheme.equals("https", true) && !local) return null
        if (host.isBlank() || !uri.query.isNullOrBlank() || !uri.fragment.isNullOrBlank()) return null
        return clean
    }
}
