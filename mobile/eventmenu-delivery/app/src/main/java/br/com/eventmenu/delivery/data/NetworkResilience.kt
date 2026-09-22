package br.com.eventmenu.delivery.data

import java.net.ConnectException
import java.net.NoRouteToHostException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

internal fun isDeliveryNetworkFailure(throwable: Throwable): Boolean =
    throwable is SocketTimeoutException ||
        throwable is ConnectException ||
        throwable is NoRouteToHostException ||
        throwable is UnknownHostException

internal fun shouldRetryDeliveryRequest(method: String, attempt: Int, throwable: Throwable): Boolean {
    if (!method.equals("GET", ignoreCase = true) || attempt >= 2) return false
    return isDeliveryNetworkFailure(throwable)
}

internal fun retryDelayMillis(attempt: Int): Long = when (attempt) {
    0 -> 300L
    else -> 900L
}

internal fun friendlyNetworkException(throwable: Throwable): ApiException = when (throwable) {
    is SocketTimeoutException -> ApiException(
        "A conexão está lenta. Tente novamente em alguns instantes.",
        "NETWORK_TIMEOUT",
    )
    is UnknownHostException, is NoRouteToHostException -> ApiException(
        "Sem conexão com a internet. Verifique sua rede e tente novamente.",
        "NETWORK_OFFLINE",
    )
    is ConnectException -> ApiException(
        "Não foi possível conectar ao EventMenu agora. Tente novamente em instantes.",
        "NETWORK_UNAVAILABLE",
    )
    else -> ApiException(
        "Não foi possível concluir agora. Tente novamente.",
        "NETWORK_ERROR",
    )
}
