package br.com.eventmenu.delivery.ui

fun isSafeDelyvreImageUrl(value: String): Boolean =
    value.trim().startsWith("https://", ignoreCase = true)

fun delyvreStoreStatusLabel(acceptingOrders: Boolean): String =
    if (acceptingOrders) "Aberto" else "Fechado agora"

fun delyvreDeliveryFeeLabel(cents: Int): String =
    if (cents <= 0) "Grátis" else "R$ %.2f".format(java.util.Locale("pt", "BR"), cents / 100.0)
