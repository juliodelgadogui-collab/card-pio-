package br.com.eventmenu.go.util

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.util.TimeZone

class ServerTimeTest {
    private val saoPaulo = TimeZone.getTimeZone("America/Sao_Paulo")

    @Test
    fun convertsUtcDatabaseTimeToSaoPaulo() {
        assertEquals("22:10", ServerTime.localTime("2026-09-09 01:10:00", saoPaulo))
        assertEquals("22:10", ServerTime.localTime("2026-09-09T01:10:00Z", saoPaulo))
        assertEquals("08/09 · 22:10", ServerTime.localDateTime("2026-09-09 01:10:00", saoPaulo))
    }

    @Test
    fun parsesFractionalUtcTimestampAndRejectsInvalidInput() {
        assertEquals("22:10", ServerTime.localTime("2026-09-09T01:10:00.987Z", saoPaulo))
        assertNull(ServerTime.parseUtcMillis("horario-invalido"))
        assertEquals("—", ServerTime.localTime("horario-invalido", saoPaulo))
    }
}
