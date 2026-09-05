package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray

class EventOperationsRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun overview(): List<EventOverview> {
        val array = api.getEvents("overview", requireToken()).optJSONArray("events") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val e = array.getJSONObject(i)
                add(
                    EventOverview(
                        id = e.getInt("id"),
                        name = e.optString("name"),
                        venue = e.optString("venue"),
                        address = e.optString("address"),
                        startsAt = e.optString("starts_at"),
                        endsAt = e.optString("ends_at"),
                        status = e.optString("status"),
                        ticketsPaid = e.optInt("tickets_paid"),
                        ticketsCheckedIn = e.optInt("tickets_checked_in"),
                        ticketsReserved = e.optInt("tickets_reserved"),
                        guestsPending = e.optInt("guests_pending"),
                        guestsCheckedIn = e.optInt("guests_checked_in"),
                        revenueCents = e.optInt("revenue_cents"),
                    )
                )
            }
        }
    }

    suspend fun recent(eventId: Int): List<EventEntry> {
        val array = api.getEvents("recent", requireToken(), mapOf("event_id" to eventId.toString())).optJSONArray("entries") ?: JSONArray()
        return buildList {
            for (i in 0 until array.length()) {
                val e = array.getJSONObject(i)
                add(
                    EventEntry(
                        id = e.getInt("id"),
                        type = e.optString("entry_type"),
                        personName = e.optString("person_name").ifBlank { "Sem nome" },
                        detail = e.optString("detail"),
                        checkedInAt = e.optString("checked_in_at"),
                        operatorName = e.optString("operator_name"),
                    )
                )
            }
        }
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
