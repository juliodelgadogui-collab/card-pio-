package br.com.eventmenu.go.util

import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Horários persistidos pelo backend EventMenu são tratados como UTC.
 * A interface converte apenas na apresentação, usando o fuso do aparelho.
 */
object ServerTime {
    private val utc: TimeZone = TimeZone.getTimeZone("UTC")

    fun localTime(value: String, timeZone: TimeZone = TimeZone.getDefault()): String {
        val instant = parseUtcMillis(value) ?: return "—"
        return SimpleDateFormat("HH:mm", Locale.getDefault()).apply {
            this.timeZone = timeZone
        }.format(Date(instant))
    }

    fun localDateTime(value: String, timeZone: TimeZone = TimeZone.getDefault()): String {
        val instant = parseUtcMillis(value) ?: return "—"
        return SimpleDateFormat("dd/MM · HH:mm", Locale.getDefault()).apply {
            this.timeZone = timeZone
        }.format(Date(instant))
    }

    fun elapsedClock(value: String, nowMillis: Long = System.currentTimeMillis()): String {
        val instant = parseUtcMillis(value) ?: return "—"
        val seconds = ((nowMillis - instant) / 1000L).coerceAtLeast(0L)
        val minutes = seconds / 60L
        val remainder = seconds % 60L
        return "%02d:%02d".format(minutes, remainder)
    }

    internal fun parseUtcMillis(value: String): Long? {
        val clean = value.trim()
        if (clean.isBlank() || clean.equals("null", true) || clean.equals("undefined", true)) return null

        // O banco entrega normalmente yyyy-MM-dd HH:mm:ss. Também aceitamos ISO com T/Z
        // e frações de segundo, mantendo UTC como contrato do backend.
        val normalized = clean
            .replace('T', ' ')
            .removeSuffix("Z")
            .substringBefore('.')
            .take(19)

        if (normalized.length != 19) return null

        return runCatching {
            SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
                isLenient = false
                timeZone = utc
            }.parse(normalized)?.time
        }.getOrNull()
    }
}
