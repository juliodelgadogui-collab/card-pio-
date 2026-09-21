package br.com.eventmenu.go.notifications

object PushMessagePolicy {
    fun isExpired(expiresEpochRaw: String?, nowEpochSeconds: Long = System.currentTimeMillis() / 1000L): Boolean {
        val expiresEpoch = expiresEpochRaw?.toLongOrNull() ?: return false
        return expiresEpoch <= nowEpochSeconds
    }
}
