package br.com.eventmenu.connect

import android.content.Context
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.atomic.AtomicBoolean
import java.util.zip.ZipInputStream

object EmbeddedNodeRuntime {
    private val started = AtomicBoolean(false)

    init {
        System.loadLibrary("node")
        System.loadLibrary("eventmenu_node")
    }

    private external fun startNodeWithArguments(arguments: Array<String>): Int

    fun ensureStarted(context: Context) {
        if (started.get()) return
        synchronized(this) {
            if (started.get()) return
            val appContext = context.applicationContext
            val projectDir = extractProject(appContext)
            val configFile = writeConfig(appContext)
            val main = File(projectDir, "main.js")
            if (!main.isFile) throw IllegalStateException("O mecanismo interno do WhatsApp está incompleto.")

            started.set(true)
            Thread({
                try {
                    val exit = startNodeWithArguments(arrayOf("node", main.absolutePath, configFile.absolutePath))
                    android.util.Log.e("EventMenu-Node", "Node encerrou com código $exit")
                } catch (t: Throwable) {
                    android.util.Log.e("EventMenu-Node", "Falha no Node interno", t)
                } finally {
                    started.set(false)
                }
            }, "EventMenu-Embedded-Node").apply {
                isDaemon = true
                start()
            }
        }
    }

    private fun extractProject(context: Context): File {
        val root = File(context.filesDir, "eventmenu-node-runtime")
        val project = File(root, "project")
        val marker = File(root, "version.txt")
        val expected = BuildConfig.VERSION_NAME
        if (project.isDirectory && marker.readTextOrEmpty() == expected) return project

        root.deleteRecursively()
        project.mkdirs()
        val projectCanonical = project.canonicalFile
        ZipInputStream(context.assets.open("nodejs-project.zip")).use { zip ->
            var entry = zip.nextEntry
            while (entry != null) {
                val target = File(project, entry.name).canonicalFile
                if (!target.path.startsWith(projectCanonical.path + File.separator) && target != projectCanonical) {
                    throw SecurityException("Arquivo inválido no mecanismo interno.")
                }
                if (entry.isDirectory) {
                    target.mkdirs()
                } else {
                    target.parentFile?.mkdirs()
                    FileOutputStream(target).use { output -> zip.copyTo(output) }
                }
                zip.closeEntry()
                entry = zip.nextEntry
            }
        }
        marker.parentFile?.mkdirs()
        marker.writeText(expected)
        return project
    }

    private fun writeConfig(context: Context): File {
        val store = SessionStore(context)
        val root = File(context.filesDir, "eventmenu-node-runtime").apply { mkdirs() }
        val session = File(context.filesDir, "eventmenu-whatsapp-session").apply { mkdirs() }
        val config = JSONObject()
            .put("port", store.enginePort())
            .put("secret", store.engineSecret())
            .put("session_root", session.absolutePath)
            .put("country_code", store.pairingCountryRegion())
        return File(root, "config.json").apply { writeText(config.toString()) }
    }

    private fun File.readTextOrEmpty(): String = runCatching { readText() }.getOrDefault("").trim()
}

data class EmbeddedWhatsAppState(
    val status: String,
    val qr: String,
    val pairingCode: String,
    val phone: String,
    val error: String,
)

data class EmbeddedSendResult(val messageId: String)

class EmbeddedWhatsAppEngine(private val context: Context) {
    private val store = SessionStore(context)
    private val base = "http://127.0.0.1:${store.enginePort()}/"

    fun ensureStarted() {
        EmbeddedNodeRuntime.ensureStarted(context)
        val deadline = System.currentTimeMillis() + 30_000
        var lastError: Exception? = null
        while (System.currentTimeMillis() < deadline) {
            try {
                request("health", "GET", null)
                return
            } catch (e: Exception) {
                lastError = e
                Thread.sleep(350)
            }
        }
        throw IllegalStateException(lastError?.message ?: "O mecanismo interno do WhatsApp demorou para iniciar.")
    }

    fun state(): EmbeddedWhatsAppState = stateFrom(request("state", "GET", null))

    fun startQr(): EmbeddedWhatsAppState {
        ensureStarted()
        request("start", "POST", JSONObject())
        Thread.sleep(700)
        return state()
    }

    fun pair(phone: String, countryCode: String): EmbeddedWhatsAppState {
        ensureStarted()
        return stateFrom(
            request(
                "pair",
                "POST",
                JSONObject()
                    .put("phone", phone)
                    .put("country_code", countryCode.uppercase()),
            )
        )
    }

    fun logout(): EmbeddedWhatsAppState {
        ensureStarted()
        return stateFrom(request("logout", "POST", JSONObject()))
    }

    fun send(phone: String, message: String): EmbeddedSendResult {
        ensureStarted()
        val result = request("send", "POST", JSONObject().put("phone", phone).put("message", message))
        return EmbeddedSendResult(jsonText(result, "message_id"))
    }

    fun sendMedia(
        phone: String,
        message: String,
        mediaType: String,
        mediaUrl: String,
        filename: String = "",
        mimeType: String = "",
    ): EmbeddedSendResult {
        ensureStarted()
        val body = JSONObject()
            .put("phone", phone)
            .put("message", message)
            .put("media_type", mediaType)
            .put("media_url", mediaUrl)
            .put("media_filename", filename)
            .put("media_mime", mimeType)
        val result = request("send", "POST", body)
        return EmbeddedSendResult(jsonText(result, "message_id"))
    }

    private fun stateFrom(json: JSONObject): EmbeddedWhatsAppState = EmbeddedWhatsAppState(
        status = jsonText(json, "status", "disconnected"),
        qr = jsonText(json, "qr"),
        pairingCode = jsonText(json, "pairing_code"),
        phone = jsonText(json, "phone"),
        error = jsonText(json, "error"),
    )

    private fun jsonText(json: JSONObject, key: String, fallback: String = ""): String {
        if (!json.has(key) || json.isNull(key)) return fallback
        return json.optString(key, fallback).takeUnless { it.equals("null", ignoreCase = true) } ?: fallback
    }

    private fun request(path: String, method: String, body: JSONObject?): JSONObject {
        val connection = (URL(base + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 4_000
            readTimeout = if (path == "pair") 25_000 else 45_000
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Authorization", "Bearer ${store.engineSecret()}")
            if (body != null) {
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
                doOutput = true
            }
        }
        if (body != null) connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        val code = connection.responseCode
        val raw = runCatching {
            (if (code in 200..299) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() }
        }.getOrNull().orEmpty()
        connection.disconnect()
        val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
        if (code !in 200..299) throw IllegalStateException(jsonText(json, "error").ifBlank { "Falha no mecanismo local (HTTP $code)." })
        if (json.has("ok") && !json.optBoolean("ok", false)) throw IllegalStateException(jsonText(json, "error").ifBlank { "Falha no mecanismo local." })
        return json
    }
}
