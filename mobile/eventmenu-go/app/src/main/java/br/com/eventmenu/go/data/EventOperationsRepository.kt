package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

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
                        ticketRevenueCents = e.optInt("ticket_revenue_cents"),
                        barRevenueCents = e.optInt("bar_revenue_cents"),
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

    suspend fun ticketCheckIn(eventId: Int, value: String): JSONObject {
        if (eventId < 1) throw ApiException("Selecione o evento antes de validar o ingresso.")
        if (value.isBlank()) throw ApiException("Código do ingresso vazio.")
        return api.postEvents(
            "ticket-checkin",
            requireToken(),
            JSONObject().put("event_id", eventId).put("token", value.trim()),
        )
    }

    suspend fun guestCheckIn(eventId: Int, value: String): JSONObject {
        if (eventId < 1) throw ApiException("Selecione o evento antes de validar o convidado.")
        if (value.isBlank()) throw ApiException("Código do convidado vazio.")
        return api.postEvents(
            "guest-checkin",
            requireToken(),
            JSONObject().put("event_id", eventId).put("code", value.trim()),
        )
    }

    suspend fun resolveBarOrder(eventId: Int, value: String): EventPickupOrder {
        if (eventId < 1) throw ApiException("Selecione o evento antes de ler o pedido.")
        if (value.isBlank()) throw ApiException("QR do pedido vazio.")
        val order = api.getEvents(
            "bar-order-resolve",
            requireToken(),
            mapOf("event_id" to eventId.toString(), "value" to value.trim()),
        ).getJSONObject("order")
        return parsePickup(order)
    }

    suspend fun deliverBarOrder(eventId: Int, value: String): EventPickupOrder {
        if (eventId < 1) throw ApiException("Selecione o evento antes de entregar o pedido.")
        val order = api.postEvents(
            "bar-order-deliver",
            requireToken(),
            JSONObject().put("event_id", eventId).put("value", value.trim()),
        ).getJSONObject("order")
        return parsePickup(order)
    }

    private fun parsePickup(order: JSONObject): EventPickupOrder {
        val array = order.optJSONArray("items") ?: JSONArray()
        val items = buildList {
            for (i in 0 until array.length()) {
                val item = array.getJSONObject(i)
                add(
                    EventPickupItem(
                        id = item.getInt("id"),
                        name = item.optString("name_snapshot").ifBlank { "Item" },
                        quantity = item.optDouble("quantity", 0.0),
                        unitPriceCents = item.optInt("unit_price_cents"),
                        totalCents = item.optInt("total_cents"),
                    )
                )
            }
        }
        return EventPickupOrder(
            id = order.getInt("id"),
            eventId = order.getInt("event_id"),
            eventName = order.optString("event_name"),
            publicToken = order.optString("public_token"),
            status = order.optString("status"),
            paymentStatus = order.optString("payment_status"),
            totalCents = order.optInt("total_cents"),
            customerName = order.optString("customer_name"),
            customerPhone = order.optString("customer_phone"),
            alreadyDelivered = order.optBoolean("already_delivered", false),
            canDeliver = order.optBoolean("can_deliver", false),
            items = items,
        )
    }

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
