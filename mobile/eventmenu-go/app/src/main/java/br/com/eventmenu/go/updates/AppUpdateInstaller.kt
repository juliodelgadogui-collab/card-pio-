package br.com.eventmenu.go.updates

import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.Locale

/**
 * Atualizador assistido do EventMenu GO.
 *
 * A origem (URL, versão e SHA-256) vem do manifesto RS256 validado pelo
 * ClientPolicyManager. Este componente ainda confere o arquivo baixado antes de
 * entregar a instalação ao Package Installer do Android. A instalação nunca é
 * silenciosa e continua exigindo a confirmação normal do usuário.
 */
class AppUpdateInstaller(private val context: Context) {
    data class PreparedUpdate(
        val file: File,
        val version: String,
        val sha256: String,
    )

    sealed interface InstallResult {
        data object Started : InstallResult
        data class PermissionRequired(val intent: Intent) : InstallResult
    }

    suspend fun prepare(version: String, downloadUrl: String, expectedSha256: String): PreparedUpdate =
        withContext(Dispatchers.IO) {
            val cleanVersion = version.trim()
            require(cleanVersion.isNotBlank()) { "Versão da atualização inválida." }
            val expected = normalizeSha256(expectedSha256)
                ?: throw IllegalArgumentException("SHA-256 da atualização inválido.")
            val initialUrl = validateHttpsUrl(downloadUrl)
            val updatesDir = File(context.cacheDir, "updates").apply { mkdirs() }
            val safeVersion = cleanVersion.replace(Regex("[^A-Za-z0-9._-]"), "_").take(80)
            val finalFile = File(updatesDir, "EventMenu-GO-$safeVersion.apk")

            if (finalFile.isFile && finalFile.length() in 1..MAX_APK_BYTES) {
                val cachedHash = sha256(finalFile)
                if (cachedHash == expected) {
                    validateArchive(finalFile, cleanVersion)
                    return@withContext PreparedUpdate(finalFile, cleanVersion, expected)
                }
                finalFile.delete()
            }

            val tmp = File(updatesDir, ".download-${System.nanoTime()}.apk")
            try {
                val connection = openDownload(initialUrl)
                try {
                    val length = connection.contentLengthLong
                    if (length > MAX_APK_BYTES) throw IllegalStateException("Arquivo de atualização maior que o limite permitido.")
                    val digest = MessageDigest.getInstance("SHA-256")
                    var total = 0L
                    connection.inputStream.use { input ->
                        FileOutputStream(tmp).use { output ->
                            val buffer = ByteArray(64 * 1024)
                            while (true) {
                                val read = input.read(buffer)
                                if (read < 0) break
                                if (read == 0) continue
                                total += read
                                if (total > MAX_APK_BYTES) throw IllegalStateException("Arquivo de atualização maior que o limite permitido.")
                                digest.update(buffer, 0, read)
                                output.write(buffer, 0, read)
                            }
                            output.fd.sync()
                        }
                    }
                    if (total <= 0L) throw IllegalStateException("O servidor retornou um arquivo de atualização vazio.")
                    val actual = digest.digest().toHex()
                    if (actual != expected) throw SecurityException("A atualização baixada não confere com o SHA-256 publicado pelo servidor.")
                    validateArchive(tmp, cleanVersion)
                    if (finalFile.exists()) finalFile.delete()
                    if (!tmp.renameTo(finalFile)) {
                        tmp.copyTo(finalFile, overwrite = true)
                        tmp.delete()
                    }
                    PreparedUpdate(finalFile, cleanVersion, expected)
                } finally {
                    connection.disconnect()
                }
            } catch (error: Throwable) {
                tmp.delete()
                throw error
            }
        }

    suspend fun launchInstall(prepared: PreparedUpdate): InstallResult {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !context.packageManager.canRequestPackageInstalls()) {
            return InstallResult.PermissionRequired(
                Intent(
                    Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:${context.packageName}"),
                ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        }

        // A segunda conferência pode ler dezenas de MB; mantenha essa I/O fora da UI.
        withContext(Dispatchers.IO) {
            val actual = sha256(prepared.file)
            if (actual != prepared.sha256) throw SecurityException("O arquivo da atualização foi alterado depois do download.")
            validateArchive(prepared.file, prepared.version)
        }

        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.updates",
            prepared.file,
        )
        val install = Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, APK_MIME)
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        withContext(Dispatchers.Main) { context.startActivity(install) }
        return InstallResult.Started
    }

    private fun validateArchive(file: File, expectedVersion: String) {
        val pm = context.packageManager
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) PackageManager.GET_SIGNING_CERTIFICATES else PackageManager.GET_SIGNATURES
        @Suppress("DEPRECATION")
        val archive = pm.getPackageArchiveInfo(file.absolutePath, flags)
            ?: throw SecurityException("O arquivo baixado não é um APK Android válido.")
        if (archive.packageName != context.packageName) {
            throw SecurityException("O APK baixado pertence a outro aplicativo.")
        }
        if (archive.versionName.orEmpty() != expectedVersion) {
            throw SecurityException("A versão interna do APK não corresponde à versão publicada pelo servidor.")
        }

        @Suppress("DEPRECATION")
        val current = pm.getPackageInfo(context.packageName, flags)
        val currentSigners = signerFingerprints(current)
        val archiveSigners = signerFingerprints(archive)
        if (currentSigners.isEmpty() || archiveSigners.isEmpty() || currentSigners.intersect(archiveSigners).isEmpty()) {
            throw SecurityException("O APK baixado não foi assinado pelo mesmo certificado do EventMenu GO instalado.")
        }

        val currentCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) current.longVersionCode else {
            @Suppress("DEPRECATION") current.versionCode.toLong()
        }
        val archiveCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) archive.longVersionCode else {
            @Suppress("DEPRECATION") archive.versionCode.toLong()
        }
        if (archiveCode < currentCode) throw SecurityException("Downgrade do EventMenu GO foi bloqueado.")
    }

    private fun signerFingerprints(info: PackageInfo): Set<String> {
        @Suppress("DEPRECATION")
        val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            val signing = info.signingInfo ?: return emptySet()
            if (signing.hasMultipleSigners()) signing.apkContentsSigners else signing.signingCertificateHistory
        } else {
            info.signatures
        }
        return signatures.orEmpty().mapTo(linkedSetOf()) { signature ->
            MessageDigest.getInstance("SHA-256").digest(signature.toByteArray()).toHex()
        }
    }

    private fun openDownload(start: URL): HttpURLConnection {
        var current = start
        repeat(MAX_REDIRECTS + 1) { hop ->
            val connection = current.openConnection() as HttpURLConnection
            connection.instanceFollowRedirects = false
            connection.connectTimeout = 10_000
            connection.readTimeout = 30_000
            connection.setRequestProperty("Accept", APK_MIME)
            connection.setRequestProperty("Cache-Control", "no-cache")
            val status = connection.responseCode
            if (status == HttpURLConnection.HTTP_OK) return connection
            if (status in REDIRECT_CODES && hop < MAX_REDIRECTS) {
                val location = connection.getHeaderField("Location").orEmpty()
                connection.disconnect()
                if (location.isBlank()) throw IllegalStateException("Redirecionamento da atualização sem destino.")
                current = validateHttpsUrl(URL(current, location).toString())
                return@repeat
            }
            connection.disconnect()
            throw IllegalStateException("Servidor da atualização respondeu HTTP $status.")
        }
        throw IllegalStateException("A atualização excedeu o limite de redirecionamentos.")
    }

    private fun validateHttpsUrl(value: String): URL {
        val url = URL(value.trim())
        if (!url.protocol.equals("https", ignoreCase = true) || url.host.isBlank()) {
            throw SecurityException("A atualização precisa usar HTTPS.")
        }
        if (url.userInfo != null || url.ref != null) throw SecurityException("URL da atualização contém componentes não permitidos.")
        return url
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read < 0) break
                if (read > 0) digest.update(buffer, 0, read)
            }
        }
        return digest.digest().toHex()
    }

    private fun normalizeSha256(value: String): String? {
        val clean = value.uppercase(Locale.US).replace(Regex("[^A-F0-9]"), "")
        return clean.takeIf { it.length == 64 }
    }

    private fun ByteArray.toHex(): String = joinToString("") { byte -> "%02X".format(Locale.US, byte.toInt() and 0xFF) }

    companion object {
        private const val APK_MIME = "application/vnd.android.package-archive"
        private const val MAX_APK_BYTES = 250L * 1024L * 1024L
        private const val MAX_REDIRECTS = 3
        private val REDIRECT_CODES = setOf(301, 302, 303, 307, 308)
    }
}
