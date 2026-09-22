package br.com.eventmenu.go.notifications

object PushMessagePolicy {
    fun isExpired(expiresEpochRaw: String?, nowEpochSeconds: Long = System.currentTimeMillis() / 1000L): Boolean {
        val raw = expiresEpochRaw?.trim().orEmpty()
        if (raw.isEmpty()) return false
        val expiresEpoch = raw.toLongOrNull() ?: return true
        return expiresEpoch <= nowEpochSeconds
    }
}
