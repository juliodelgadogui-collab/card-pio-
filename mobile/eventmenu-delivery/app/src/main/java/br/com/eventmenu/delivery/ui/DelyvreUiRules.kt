package br.com.eventmenu.delivery.ui

import java.net.URI

fun isSafeDelyvreImageUrl(value: String): Boolean {
    val uri = runCatching { URI(value.trim()) }.getOrNull() ?: return false
    return uri.scheme.equals("https", ignoreCase = true) &&
        !uri.host.isNullOrBlank() &&
        uri.userInfo.isNullOrBlank()
}

fun delyvreStoreStatusLabel(acceptingOrders: Boolean): String =
    if (acceptingOrders) "Aberto" else "Fechado agora"

fun delyvreDeliveryFeeLabel(cents: Int): String =
    if (cents <= 0) "Grátis" else "R$ %.2f".format(java.util.Locale("pt", "BR"), cents / 100.0)
