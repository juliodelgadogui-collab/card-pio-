package br.com.eventmenu.go.security

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.PBEKeySpec

class SecureSessionStore(context: Context) {
    private val prefs = context.getSharedPreferences("eventmenu_go_secure", Context.MODE_PRIVATE)
    private val alias = "eventmenu_go_session_key"

    fun saveToken(token: String) = saveEncrypted("token", token)

    fun saveSessionTokens(accessToken: String, refreshToken: String, accessExpiresAt: String = "", refreshExpiresAt: String = "") {
        saveEncrypted("token", accessToken)
        saveEncrypted("refresh_token", refreshToken)
        prefs.edit()
            .putString("access_expires_at", accessExpiresAt)
            .putString("refresh_expires_at", refreshExpiresAt)
            .apply()
    }

    fun token(): String? = readEncrypted("token")
    fun refreshToken(): String? = readEncrypted("refresh_token")
    fun accessExpiresAt(): String = prefs.getString("access_expires_at", "").orEmpty()
    fun refreshExpiresAt(): String = prefs.getString("refresh_expires_at", "").orEmpty()

    fun clearSessionTokens() {
        prefs.edit()
            .remove("token_iv").remove("token_data")
            .remove("refresh_token_iv").remove("refresh_token_data")
            .remove("access_expires_at").remove("refresh_expires_at")
            .apply()
    }

    fun clear() = prefs.edit().clear().apply()

    fun setPin(pin: String) {
        require(pin.matches(Regex("\\d{4,8}")))
        val salt = ByteArray(16).also(SecureRandom()::nextBytes)
        val spec = PBEKeySpec(pin.toCharArray(), salt, 120_000, 256)
        val hash = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded
        prefs.edit()
            .putString("pin_salt", Base64.encodeToString(salt, Base64.NO_WRAP))
            .putString("pin_hash", Base64.encodeToString(hash, Base64.NO_WRAP))
            .apply()
    }

    fun hasPin(): Boolean = prefs.contains("pin_hash")

    fun verifyPin(pin: String): Boolean = runCatching {
        val salt = Base64.decode(prefs.getString("pin_salt", null), Base64.NO_WRAP)
        val expected = Base64.decode(prefs.getString("pin_hash", null), Base64.NO_WRAP)
        val actual = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256")
            .generateSecret(PBEKeySpec(pin.toCharArray(), salt, 120_000, 256)).encoded
        java.security.MessageDigest.isEqual(expected, actual)
    }.getOrDefault(false)

    var biometricEnabled: Boolean
        get() = prefs.getBoolean("biometric_enabled", false)
        set(value) { prefs.edit().putBoolean("biometric_enabled", value).apply() }

    private fun saveEncrypted(name: String, value: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        prefs.edit()
            .putString("${name}_iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString("${name}_data", Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .apply()
    }

    private fun readEncrypted(name: String): String? = runCatching {
        val ivText = prefs.getString("${name}_iv", null) ?: return@runCatching null
        val dataText = prefs.getString("${name}_data", null) ?: return@runCatching null
        val iv = Base64.decode(ivText, Base64.NO_WRAP)
        val data = Base64.decode(dataText, Base64.NO_WRAP)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, iv))
        String(cipher.doFinal(data), Charsets.UTF_8)
    }.getOrNull()

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(alias, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build()
        )
        return generator.generateKey()
    }
}
