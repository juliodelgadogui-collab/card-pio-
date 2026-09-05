package br.com.eventmenu.go.data

data class AppNotification(
    val id: Int,
    val mode: String,
    val type: String,
    val priority: String,
    val title: String,
    val message: String,
    val entityType: String,
    val entityId: String,
    val readAt: String,
    val expiresAt: String,
    val createdAt: String,
) {
    val unread: Boolean get() = readAt.isBlank()
}

data class NotificationInbox(
    val items: List<AppNotification>,
    val unreadCount: Int,
    val mode: String,
)
