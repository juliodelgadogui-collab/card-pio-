package br.com.eventmenu.connect

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Small Android Keystore backed value store.
 *
 * Ciphertext remains in the app SharedPreferences, but the AES key never leaves
 * AndroidKeyStore. Existing plaintext values are migrated on first read.
 */
class SecureValueStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    private val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }

    @Synchronized
    fun get(name: String): String {
        val secureKey = SECURE_PREFIX + name
        val encoded = prefs.getString(secureKey, "").orEmpty()
        if (encoded.isNotBlank()) {
            return runCatching { decrypt(encoded) }.getOrElse {
                prefs.edit().remove(secureKey).commit()
                ""
            }
        }

        // One-time migration from pre-hardening builds.
        val legacy = prefs.getString(name, "").orEmpty()
        if (legacy.isNotBlank() && put(name, legacy)) {
            prefs.edit().remove(name).commit()
            return legacy
        }
        return legacy
    }

    @Synchronized
    fun put(name: String, value: String): Boolean {
        if (value.isEmpty()) return remove(name)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        val payload = Base64.encodeToString(cipher.iv + encrypted, Base64.NO_WRAP)
        return prefs.edit()
            .putString(SECURE_PREFIX + name, payload)
            .remove(name)
            .commit()
    }

    @Synchronized
    fun remove(name: String): Boolean = prefs.edit()
        .remove(SECURE_PREFIX + name)
        .remove(name)
        .commit()

    private fun decrypt(encoded: String): String {
        val raw = Base64.decode(encoded, Base64.NO_WRAP)
        require(raw.size > IV_BYTES) { "Payload seguro inválido" }
        val iv = raw.copyOfRange(0, IV_BYTES)
        val encrypted = raw.copyOfRange(IV_BYTES, raw.size)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, iv))
        return cipher.doFinal(encrypted).toString(Charsets.UTF_8)
    }

    private fun secretKey(): SecretKey {
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .setKeySize(256)
                .build()
        )
        return generator.generateKey()
    }

    companion object {
        private const val PREFS = "eventmenu_connect"
        private const val SECURE_PREFIX = "secure_v1_"
        private const val KEY_ALIAS = "eventmenu_connect_secure_v1"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val IV_BYTES = 12
    }
}
