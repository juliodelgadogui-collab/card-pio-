package br.com.eventmenu.go.data

import br.com.eventmenu.go.security.SecureSessionStore
import org.json.JSONArray
import org.json.JSONObject

class NotificationRepository(baseUrl: String, deviceId: String, private val sessionStore: SecureSessionStore) {
    private val api = ApiClient(baseUrl, deviceId)

    suspend fun inbox(limit: Int = 100): NotificationInbox {
        val root = api.getNotifications("list", requireToken(), mapOf("limit" to limit.coerceIn(1, 200).toString()))
            .getJSONObject("notifications")
        val array = root.optJSONArray("items") ?: JSONArray()
        val items = buildList {
            for (i in 0 until array.length()) {
                val n = array.getJSONObject(i)
                add(
                    AppNotification(
                        id = n.getInt("id"),
                        mode = n.optString("mode"),
                        type = n.optString("type"),
                        priority = n.optString("priority", "info"),
                        title = n.optString("title"),
                        message = n.optString("message"),
                        entityType = n.optString("entity_type"),
                        entityId = n.optString("entity_id"),
                        readAt = n.optString("read_at"),
                        expiresAt = n.optString("expires_at"),
                        createdAt = n.optString("created_at"),
                    )
                )
            }
        }
        return NotificationInbox(items, root.optInt("unread_count"), root.optString("mode"))
    }

    suspend fun markRead(notificationId: Int) {
        api.postNotifications("read", requireToken(), JSONObject().put("notification_id", notificationId))
    }

    suspend fun markAllRead() {
        api.postNotifications("read-all", requireToken())
    }

    suspend fun registerPushToken(pushToken: String) {
        val token = pushToken.trim()
        if (token.length < 20) return
        api.postNotifications(
            "push-register",
            requireToken(),
            JSONObject()
                .put("push_token", token)
                .put("platform", "android"),
        )
    }

    suspend fun unregisterPushDevice() {
        api.postNotifications("push-unregister", requireToken())
    }

    suspend fun pushStatus(): JSONObject =
        api.getNotifications("push-status", requireToken()).getJSONObject("push")

    private fun requireToken(): String = sessionStore.token() ?: throw ApiException("Sessão não encontrada.", 401)
}
