package br.com.eventmenu.go.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import java.security.MessageDigest
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Cache somente de leitura para manter telas operacionais consultáveis durante oscilações de rede.
 * O conteúdo fica criptografado pelo AndroidKeyStore e é isolado pelo token da sessão.
 */
class OfflineReadCache(context: Context) {
    private val prefs = context.getSharedPreferences("eventmenu_go_offline_read_cache", Context.MODE_PRIVATE)
    private val alias = "eventmenu_go_offline_read_cache_key"

    fun save(scope: String, requestKey: String, value: JSONObject) {
        if (scope.isBlank() || requestKey.isBlank()) return
        val id = id(scope, requestKey)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.doFinal(value.toString().toByteArray(Charsets.UTF_8))
        val now = System.currentTimeMillis()
        val index = prefs.getStringSet(INDEX_KEY, emptySet()).orEmpty().toMutableSet().apply { add(id) }
        prefs.edit()
            .putString("${id}_iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString("${id}_data", Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .putLong("${id}_at", now)
            .putStringSet(INDEX_KEY, index)
            .apply()
        prune(index)
    }

    fun read(scope: String, requestKey: String, maxAgeMs: Long = DEFAULT_MAX_AGE_MS): JSONObject? = runCatching {
        if (scope.isBlank() || requestKey.isBlank()) return@runCatching null
        val id = id(scope, requestKey)
        val savedAt = prefs.getLong("${id}_at", 0L)
        if (savedAt <= 0L || System.currentTimeMillis() - savedAt > maxAgeMs) return@runCatching null
        val ivText = prefs.getString("${id}_iv", null) ?: return@runCatching null
        val dataText = prefs.getString("${id}_data", null) ?: return@runCatching null
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(
            Cipher.DECRYPT_MODE,
            key(),
            GCMParameterSpec(128, Base64.decode(ivText, Base64.NO_WRAP)),
        )
        val clear = cipher.doFinal(Base64.decode(dataText, Base64.NO_WRAP))
        JSONObject(String(clear, Charsets.UTF_8))
            .put("_eventmenu_cached", true)
            .put("_eventmenu_cached_at", savedAt)
    }.getOrNull()

    fun clear() {
        prefs.edit().clear().apply()
    }

    private fun prune(currentIndex: MutableSet<String>) {
        if (currentIndex.size <= MAX_ENTRIES) return
        val remove = currentIndex
            .sortedBy { prefs.getLong("${it}_at", 0L) }
            .take(currentIndex.size - MAX_ENTRIES)
        if (remove.isEmpty()) return
        val editor = prefs.edit()
        remove.forEach { id ->
            editor.remove("${id}_iv").remove("${id}_data").remove("${id}_at")
            currentIndex.remove(id)
        }
        editor.putStringSet(INDEX_KEY, currentIndex).apply()
    }

    private fun id(scope: String, requestKey: String): String {
        val digest = MessageDigest.getInstance("SHA-256")
            .digest("$scope|$requestKey".toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it) }
    }

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(alias, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build()
        )
        return generator.generateKey()
    }

    companion object {
        private const val INDEX_KEY = "cache_index"
        private const val MAX_ENTRIES = 60
        private const val DEFAULT_MAX_AGE_MS = 30L * 60L * 1000L
    }
}
