package br.com.eventmenu.delivery.ui

import br.com.eventmenu.delivery.data.Address
import br.com.eventmenu.delivery.data.Store
import java.net.URL

/**
 * Validates a fully-qualified remote image URL. DELYVRE never loads clear-text
 * HTTP images and never accepts credentials embedded in the URL.
 */
fun isSafeDelyvreImageUrl(value: String): Boolean = normalizeAbsoluteDelyvreImageUrl(value) != null

/**
 * Resolves image paths returned by EventMenu without hard-coding a host in the
 * application. Absolute HTTPS/CDN URLs are preserved. Relative and legacy
 * app-relative paths are resolved against the configured DELYVRE API base.
 *
 * The API base itself must be HTTPS, so a relative value can never downgrade
 * transport security or redirect to an arbitrary host.
 */
fun resolveDelyvreImageUrl(value: String, apiBaseUrl: String): String? {
    val raw = value.trim().replace('\\', '/')
    if (raw.isBlank()) return null

    normalizeAbsoluteDelyvreImageUrl(raw)?.let { return it }

    // Reject protocol-relative, explicit non-HTTPS and malformed schemes. Only
    // genuine application-relative paths are allowed through this branch.
    if (raw.startsWith("//") || raw.contains("://") || SCHEME_PREFIX.matches(raw)) return null

    val base = normalizeAbsoluteDelyvreImageUrl(apiBaseUrl) ?: return null
    val candidate = base.trimEnd('/') + "/" + raw.trimStart('/')
    return normalizeAbsoluteDelyvreImageUrl(candidate)
}

private fun normalizeAbsoluteDelyvreImageUrl(value: String): String? {
    val prepared = value.trim().replace(" ", "%20")
    if (prepared.isBlank()) return null
    val url = runCatching { URL(prepared) }.getOrNull() ?: return null
    if (!url.protocol.equals("https", ignoreCase = true)) return null
    if (url.host.isBlank() || !url.userInfo.isNullOrBlank()) return null
    return runCatching { url.toURI().toASCIIString() }.getOrNull()
}

private val SCHEME_PREFIX = Regex("^[A-Za-z][A-Za-z0-9+.-]*:.*$")

fun delyvreStoreStatusLabel(acceptingOrders: Boolean): String =
    if (acceptingOrders) "Aberto" else "Fechado agora"

fun delyvreDeliveryFeeLabel(cents: Int): String =
    if (cents <= 0) "Grátis" else "R$ %.2f".format(java.util.Locale("pt", "BR"), cents / 100.0)

fun delyvreAddressSummary(address: Address?): String {
    if (address == null) return "Informe onde deseja receber"
    val firstLine = listOf(address.street.trim(), address.number.trim()).filter { it.isNotBlank() }.joinToString(", ")
    val secondLine = listOf(address.neighborhood.trim(), address.city.trim()).filter { it.isNotBlank() }.joinToString(" · ")
    return listOf(firstLine, secondLine).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { address.label.ifBlank { "Endereço selecionado" } }
}

fun delyvreMatchesHomeFilters(
    store: Store,
    openOnly: Boolean,
    freeOnly: Boolean,
    pickupOnly: Boolean,
    category: String?,
): Boolean {
    if (openOnly && !store.acceptingOrders) return false
    if (freeOnly && store.deliveryFeeCents > 0) return false
    if (pickupOnly && !store.pickupEnabled) return false
    if (!category.isNullOrBlank() && store.categories.none { it.equals(category, ignoreCase = true) }) return false
    return true
}
