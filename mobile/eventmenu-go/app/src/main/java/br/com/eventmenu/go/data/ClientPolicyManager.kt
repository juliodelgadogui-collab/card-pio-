package br.com.eventmenu.go.data

import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.util.Base64
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.json.JSONObject
import java.security.KeyFactory
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.X509EncodedKeySpec
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * Política remota do cliente EventMenu GO.
 *
 * A chave pública só é aceita quando vem do servidor principal autenticado.
 * O servidor de contingência recebe/serve apenas o manifesto já assinado. Isso
 * permite mudar versão mínima, manutenção, flags e distribuição da atualização
 * sem recompilar o APK, sem transformar o arquivo remoto em substituto do
 * login/token do backend.
 */
object ClientPolicyManager {
    private const val PREFS = "eventmenu_client_policy"
    private const val KEY_TRUST_ID = "trust_key_id"
    private const val KEY_TRUST_PEM = "trust_public_key_pem"
    private const val KEY_PAYLOAD_B64 = "payload_b64"
    private const val KEY_SIGNATURE_B64 = "signature_b64"
    private const val KEY_ENVELOPE_KEY_ID = "envelope_key_id"
    private const val KEY_ALGORITHM = "algorithm"
    private const val CLOCK_SKEW_MS = 5L * 60L * 1000L
    private const val MAX_POLICY_TTL_MS = 7L * 24L * 60L * 60L * 1000L

    data class State(
        val trusted: Boolean = false,
        val enabled: Boolean = true,
        val maintenance: Boolean = false,
        val maintenanceMessage: String = "",
        val minVersion: String = "",
        val recommendedVersion: String = "",
        val features: Map<String, Boolean> = emptyMap(),
        val configVersion: Long = 0L,
        val issuedAtMs: Long = 0L,
        val expiresAtMs: Long = 0L,
        val signingAllowed: Boolean = true,
        val signingFingerprint: String = "",
        val releaseVersion: String = "",
        val releaseUrl: String = "",
        val releaseSha256: String = "",
        val releaseNotes: String = "",
        val releaseConfigVersion: Long = 0L,
        val lastError: String = "",
    )

    private lateinit var appContext: Context
    private var initialized = false
    private val lock = Any()
    private val _state = MutableStateFlow(State())
    val state: StateFlow<State> = _state.asStateFlow()

    fun initialize(context: Context) {
        synchronized(lock) {
            if (initialized) return
            appContext = context.applicationContext
            val fingerprint = calculateSigningFingerprint(appContext)
            _state.value = State(signingFingerprint = fingerprint)
            initialized = true
            restoreCachedEnvelope()
        }
    }

    /**
     * A raiz de confiança pode ser atualizada somente por resposta declarada do
     * principal. O ApiClient garante que esta chamada só ocorre após uma rota
     * autenticada; respostas do nó de contingência não podem trocar a chave.
     */
    fun updateTrustFromRouting(root: JSONObject) {
        ensureInitialized()
        if (!root.optString("served_by").equals("primary", ignoreCase = true)) return
        val signing = root.optJSONObject("policy_signing") ?: return
        val keyId = signing.optString("key_id").trim()
        val pem = signing.optString("public_key_pem").trim()
        if (keyId.length < 8 || !pem.contains("BEGIN PUBLIC KEY") || !pem.contains("END PUBLIC KEY")) return

        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        prefs.edit().putString(KEY_TRUST_ID, keyId).putString(KEY_TRUST_PEM, pem).apply()
        restoreCachedEnvelope()
    }

    /** Retorna true apenas quando o envelope foi validado e salvo. */
    fun applyEnvelope(root: JSONObject): Boolean {
        ensureInitialized()
        val algorithm = root.optString("algorithm").trim()
        val keyId = root.optString("key_id").trim()
        val payloadB64 = root.optString("payload_b64").trim()
        val signatureB64 = root.optString("signature_b64").trim()
        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val trustedId = prefs.getString(KEY_TRUST_ID, "").orEmpty()
        val publicPem = prefs.getString(KEY_TRUST_PEM, "").orEmpty()

        if (algorithm != "RS256" || keyId.isBlank() || !keyId.equals(trustedId, ignoreCase = false) || publicPem.isBlank()) {
            _state.value = _state.value.copy(lastError = "Política recebida sem uma chave de confiança válida.")
            return false
        }

        val parsed = verifyAndParse(algorithm, keyId, payloadB64, signatureB64, publicPem) ?: run {
            _state.value = _state.value.copy(lastError = "Política remota inválida, expirada ou com assinatura incorreta.")
            return false
        }

        // Bloqueia replay/rollback de um manifesto antigo ainda dentro do TTL.
        // A release tem versão de configuração própria porque pode mudar sem que
        // a política geral precise ser editada no mesmo momento.
        val current = _state.value
        if (current.trusted) {
            if (parsed.configVersion < current.configVersion) return false
            if (parsed.configVersion == current.configVersion) {
                if (parsed.releaseConfigVersion < current.releaseConfigVersion) return false
                if (parsed.releaseConfigVersion == current.releaseConfigVersion && parsed.issuedAtMs < current.issuedAtMs) return false
            }
        }

        prefs.edit()
            .putString(KEY_ALGORITHM, algorithm)
            .putString(KEY_ENVELOPE_KEY_ID, keyId)
            .putString(KEY_PAYLOAD_B64, payloadB64)
            .putString(KEY_SIGNATURE_B64, signatureB64)
            .apply()
        _state.value = parsed.copy(signingFingerprint = current.signingFingerprint)
        return true
    }

    fun blockReason(currentVersion: String): String? {
        val policy = _state.value
        if (!policy.trusted) return null
        if (policy.expiresAtMs > 0 && System.currentTimeMillis() > policy.expiresAtMs + CLOCK_SKEW_MS) return null
        if (!policy.signingAllowed) return "A assinatura deste EventMenu GO não está autorizada."
        if (!policy.enabled) return "Este EventMenu GO foi desativado pelo administrador."
        if (policy.maintenance) return policy.maintenanceMessage.ifBlank { "EventMenu GO temporariamente em manutenção." }
        if (policy.minVersion.isNotBlank() && isBelowVersion(currentVersion, policy.minVersion)) {
            return "Atualização obrigatória. Versão mínima: ${policy.minVersion}."
        }
        return null
    }

    fun recommendedUpdate(currentVersion: String): String? {
        val policy = _state.value
        if (!policy.trusted) return null
        if (policy.expiresAtMs > 0 && System.currentTimeMillis() > policy.expiresAtMs + CLOCK_SKEW_MS) return null
        val candidates = listOf(policy.recommendedVersion, policy.releaseVersion)
            .filter { it.isNotBlank() && isBelowVersion(currentVersion, it) }
        return candidates.maxWithOrNull { a, b -> compareVersions(a, b) }
    }

    fun featureEnabled(name: String, defaultWhenUnspecified: Boolean = true): Boolean {
        val policy = _state.value
        if (!policy.trusted || (policy.expiresAtMs > 0 && System.currentTimeMillis() > policy.expiresAtMs + CLOCK_SKEW_MS)) {
            return defaultWhenUnspecified
        }
        val features = policy.features
        if (features.isEmpty()) return defaultWhenUnspecified
        return features[name] ?: false
    }

    fun signingFingerprint(): String {
        ensureInitialized()
        return _state.value.signingFingerprint
    }

    private fun restoreCachedEnvelope() {
        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val algorithm = prefs.getString(KEY_ALGORITHM, "").orEmpty()
        val keyId = prefs.getString(KEY_ENVELOPE_KEY_ID, "").orEmpty()
        val payload = prefs.getString(KEY_PAYLOAD_B64, "").orEmpty()
        val signature = prefs.getString(KEY_SIGNATURE_B64, "").orEmpty()
        val trustedId = prefs.getString(KEY_TRUST_ID, "").orEmpty()
        val publicPem = prefs.getString(KEY_TRUST_PEM, "").orEmpty()
        val fingerprint = _state.value.signingFingerprint
        if (algorithm.isBlank() || keyId.isBlank() || payload.isBlank() || signature.isBlank() || publicPem.isBlank() || keyId != trustedId) {
            _state.value = _state.value.copy(signingFingerprint = fingerprint)
            return
        }
        val parsed = verifyAndParse(algorithm, keyId, payload, signature, publicPem)
        _state.value = (parsed ?: State(lastError = "Política local descartada por estar inválida ou expirada.")).copy(signingFingerprint = fingerprint)
    }

    private fun verifyAndParse(
        algorithm: String,
        keyId: String,
        payloadB64: String,
        signatureB64: String,
        publicPem: String,
    ): State? = runCatching {
        if (algorithm != "RS256") return@runCatching null
        val payloadBytes = Base64.decode(payloadB64, Base64.DEFAULT)
        val signatureBytes = Base64.decode(signatureB64, Base64.DEFAULT)
        val keyBody = publicPem
            .replace("-----BEGIN PUBLIC KEY-----", "")
            .replace("-----END PUBLIC KEY-----", "")
            .replace(Regex("\\s+"), "")
        val keyBytes = Base64.decode(keyBody, Base64.DEFAULT)
        val publicKey = KeyFactory.getInstance("RSA").generatePublic(X509EncodedKeySpec(keyBytes))
        val verifier = Signature.getInstance("SHA256withRSA")
        verifier.initVerify(publicKey)
        verifier.update(payloadBytes)
        if (!verifier.verify(signatureBytes)) return@runCatching null

        val payload = JSONObject(String(payloadBytes, Charsets.UTF_8))
        if (payload.optInt("schema", 0) != 1 || payload.optString("platform") != "android") return@runCatching null
        val expectedCluster = runCatching { FailoverEndpointRouter.clusterId() }.getOrDefault("")
        val receivedCluster = payload.optString("cluster_id").trim()
        if (expectedCluster.isNotBlank() && receivedCluster.isNotBlank() && expectedCluster != receivedCluster) return@runCatching null

        val issuedAtMs = parseIsoMillis(payload.optString("issued_at"))
        val expiresAtMs = parseIsoMillis(payload.optString("expires_at"))
        val now = System.currentTimeMillis()
        if (issuedAtMs <= 0L || expiresAtMs <= issuedAtMs) return@runCatching null
        if (issuedAtMs > now + CLOCK_SKEW_MS) return@runCatching null
        if (expiresAtMs < now - CLOCK_SKEW_MS) return@runCatching null
        if (expiresAtMs - issuedAtMs > MAX_POLICY_TTL_MS + CLOCK_SKEW_MS) return@runCatching null

        val featureObject = payload.optJSONObject("features") ?: JSONObject()
        val features = buildMap {
            val keys = featureObject.keys()
            while (keys.hasNext()) {
                val key = keys.next()
                put(key, featureObject.optBoolean(key, false))
            }
        }
        val allowedArray = payload.optJSONArray("allowed_signing_fingerprints")
        val allowed = mutableSetOf<String>()
        if (allowedArray != null) {
            for (i in 0 until allowedArray.length()) normalizeFingerprint(allowedArray.optString(i))?.let(allowed::add)
        }
        val localFingerprint = normalizeFingerprint(_state.value.signingFingerprint).orEmpty()
        val signingAllowed = allowed.isEmpty() || (localFingerprint.isNotBlank() && localFingerprint in allowed)

        val release = payload.optJSONObject("release")
        val releasePublished = release?.optBoolean("published", false) == true
        val releaseVersionRaw = release?.optString("version").orEmpty().trim()
        val releaseUrlRaw = release?.optString("download_url").orEmpty().trim()
        val releaseShaRaw = release?.optString("sha256").orEmpty().trim()
        val validReleaseUrl = normalizeReleaseUrl(releaseUrlRaw)
        val validReleaseSha = normalizeSha256(releaseShaRaw)
        val validRelease = releasePublished && releaseVersionRaw.isNotBlank() && validReleaseUrl != null && validReleaseSha != null

        State(
            trusted = true,
            enabled = payload.optBoolean("enabled", true),
            maintenance = payload.optBoolean("maintenance", false),
            maintenanceMessage = payload.optString("maintenance_message"),
            minVersion = payload.optString("min_version"),
            recommendedVersion = payload.optString("recommended_version"),
            features = features,
            configVersion = payload.optLong("config_version", 0L),
            issuedAtMs = issuedAtMs,
            expiresAtMs = expiresAtMs,
            signingAllowed = signingAllowed,
            signingFingerprint = _state.value.signingFingerprint,
            releaseVersion = if (validRelease) releaseVersionRaw else "",
            releaseUrl = if (validRelease) validReleaseUrl.orEmpty() else "",
            releaseSha256 = if (validRelease) validReleaseSha.orEmpty() else "",
            releaseNotes = if (validRelease) release?.optString("release_notes").orEmpty().take(1000) else "",
            releaseConfigVersion = release?.optLong("config_version", 0L) ?: 0L,
            lastError = "",
        )
    }.getOrNull()

    private fun calculateSigningFingerprint(context: Context): String = runCatching {
        @Suppress("DEPRECATION")
        val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            val info = context.packageManager.getPackageInfo(context.packageName, PackageManager.GET_SIGNING_CERTIFICATES)
            val signingInfo = info.signingInfo ?: return@runCatching ""
            if (signingInfo.hasMultipleSigners()) signingInfo.apkContentsSigners else signingInfo.signingCertificateHistory
        } else {
            @Suppress("DEPRECATION")
            context.packageManager.getPackageInfo(context.packageName, PackageManager.GET_SIGNATURES).signatures
        }
        val bytes = signatures?.firstOrNull()?.toByteArray() ?: return@runCatching ""
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString(":") { byte ->
            "%02X".format(Locale.US, byte.toInt() and 0xFF)
        }
    }.getOrDefault("")

    private fun parseIsoMillis(value: String): Long {
        if (value.isBlank()) return 0L
        val formats = listOf("yyyy-MM-dd'T'HH:mm:ssXXX", "yyyy-MM-dd'T'HH:mm:ss'Z'")
        for (pattern in formats) {
            val parsed = runCatching {
                SimpleDateFormat(pattern, Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(value)?.time ?: 0L
            }.getOrDefault(0L)
            if (parsed > 0L) return parsed
        }
        return 0L
    }

    private fun normalizeFingerprint(value: String): String? {
        val hex = value.uppercase(Locale.US).replace(Regex("[^A-F0-9]"), "")
        return hex.takeIf { it.length == 64 }
    }

    private fun normalizeSha256(value: String): String? {
        val hex = value.uppercase(Locale.US).replace(Regex("[^A-F0-9]"), "")
        return hex.takeIf { it.length == 64 }
    }

    private fun normalizeReleaseUrl(value: String): String? {
        if (value.isBlank()) return null
        val uri = runCatching { Uri.parse(value) }.getOrNull() ?: return null
        if (!uri.scheme.equals("https", ignoreCase = true)) return null
        if (uri.host.isNullOrBlank() || !uri.userInfo.isNullOrBlank() || !uri.fragment.isNullOrBlank()) return null
        return value
    }

    private fun isBelowVersion(current: String, minimum: String): Boolean = compareVersions(current, minimum) < 0

    private fun compareVersions(leftVersion: String, rightVersion: String): Int {
        fun parts(value: String): List<Int> = Regex("\\d+").findAll(value).take(4).map { it.value.toIntOrNull() ?: 0 }.toList()
        val left = parts(leftVersion)
        val right = parts(rightVersion)
        for (i in 0 until maxOf(left.size, right.size, 3)) {
            val a = left.getOrElse(i) { 0 }
            val b = right.getOrElse(i) { 0 }
            if (a != b) return a.compareTo(b)
        }
        return 0
    }

    private fun ensureInitialized() {
        check(initialized) { "ClientPolicyManager não inicializado." }
    }
}
