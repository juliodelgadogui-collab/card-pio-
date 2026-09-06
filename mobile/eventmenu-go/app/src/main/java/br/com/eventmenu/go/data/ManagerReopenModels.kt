package br.com.eventmenu.go.data

data class ManagerReopenCandidate(
    val id: Int,
    val channel: String,
    val paymentStatus: String,
    val totalCents: Int,
    val customerName: String,
    val tableName: String,
    val updatedAt: String,
    val eligible: Boolean,
    val blockReason: String,
)
