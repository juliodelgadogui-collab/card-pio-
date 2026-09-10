package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.DeliveryProgressRepository
import br.com.eventmenu.go.location.DeliveryLocationService
import br.com.eventmenu.go.location.DeliveryRoutePermissionCoordinator
import br.com.eventmenu.go.location.LocationPermissionActivity
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeliveryProgressState(
    val items: Map<Int, DeliveryProgress> = emptyMap(),
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class DeliveryProgressViewModel(
    private val repository: DeliveryProgressRepository,
) : ViewModel() {
    private val _state = MutableStateFlow(DeliveryProgressState())
    val state: StateFlow<DeliveryProgressState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            DeliveryRoutePermissionCoordinator.grantedOrders.collectLatest { orderId ->
                startRouteInternal(orderId)
            }
        }
    }

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        try {
            val rows = repository.listMine()
            _state.update { it.copy(items = rows.associateBy { row -> row.orderId }, loading = false) }
            syncGps(rows)
        } catch (error: Throwable) {
            _state.update {
                it.copy(
                    loading = false,
                    error = deliveryError(error, "Não foi possível atualizar as entregas."),
                )
            }
        }
    }

    fun pickup(orderId: Int) = runAction(orderId, "Pedido retirado.") { repository.pickup(orderId) }

    fun startRoute(orderId: Int) {
        val current = _state.value.items[orderId]
        if (current?.completed == true) {
            _state.update { it.copy(error = null, message = "Esta entrega já foi concluída.") }
            return
        }
        if (current?.routeStarted == true || current?.arrived == true) {
            val app = EventMenuGoApplication.instance
            if (DeliveryLocationService.hasLocationPermission(app)) {
                DeliveryLocationService.start(app)
            }
            _state.update {
                it.copy(
                    error = null,
                    message = if (current.arrived) {
                        "A chegada já foi confirmada. Continue com a finalização da entrega."
                    } else {
                        "A rota já está iniciada. GPS ao vivo mantido."
                    },
                )
            }
            return
        }

        val app = EventMenuGoApplication.instance
        if (!DeliveryLocationService.hasLocationPermission(app)) {
            _state.update {
                it.copy(
                    error = null,
                    message = "Para acompanhar a entrega, permita a localização. A rota começa automaticamente após a autorização.",
                )
            }
            LocationPermissionActivity.request(app, orderId)
            return
        }
        startRouteInternal(orderId)
    }

    private fun startRouteInternal(orderId: Int) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        try {
            val progress = repository.startRoute(orderId)
            _state.update {
                it.copy(
                    items = it.items + (orderId to progress),
                    loading = false,
                    message = "Rota iniciada. GPS ao vivo ativado para esta entrega.",
                    changeVersion = it.changeVersion + 1,
                )
            }
            DeliveryLocationService.start(EventMenuGoApplication.instance)
        } catch (error: Throwable) {
            // Se a tela estava com um pedido atrasado em cache/estado local, consulta o
            // progresso atual antes de exibir erro. Assim um segundo toque ou uma
            // resposta tardia não tenta reiniciar uma rota que já existe.
            val latest = try {
                repository.listMine().firstOrNull { it.orderId == orderId }
            } catch (_: Throwable) {
                null
            }

            when {
                latest?.completed == true -> {
                    _state.update {
                        it.copy(
                            items = it.items + (orderId to latest),
                            loading = false,
                            error = null,
                            message = "Esta entrega já foi concluída.",
                            changeVersion = it.changeVersion + 1,
                        )
                    }
                    syncGps(_state.value.items.values.toList())
                }

                latest?.routeStarted == true || latest?.arrived == true -> {
                    _state.update {
                        it.copy(
                            items = it.items + (orderId to latest),
                            loading = false,
                            error = null,
                            message = if (latest.arrived) {
                                "A chegada já estava confirmada. Estado da entrega atualizado."
                            } else {
                                "A rota já estava iniciada. GPS ao vivo mantido."
                            },
                            changeVersion = it.changeVersion + 1,
                        )
                    }
                    if (DeliveryLocationService.hasLocationPermission(EventMenuGoApplication.instance)) {
                        DeliveryLocationService.start(EventMenuGoApplication.instance)
                    }
                }

                else -> {
                    _state.update {
                        it.copy(
                            loading = false,
                            error = deliveryError(error, "Não foi possível iniciar a rota. Atualize a entrega e tente novamente."),
                        )
                    }
                }
            }
        }
    }

    fun arrive(orderId: Int) = runAction(
        orderId,
        "Chegada confirmada. Você já pode receber o pagamento, se necessário.",
    ) { repository.arrive(orderId) }

    fun complete(orderId: Int) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        try {
            val progress = repository.complete(orderId)
            _state.update {
                it.copy(
                    items = it.items + (orderId to progress),
                    loading = false,
                    message = "Entrega concluída. Rastreamento encerrado.",
                    changeVersion = it.changeVersion + 1,
                )
            }
            refresh()
        } catch (error: Throwable) {
            _state.update {
                it.copy(
                    loading = false,
                    error = deliveryError(error, "Não foi possível concluir a entrega."),
                )
            }
        }
    }

    private fun runAction(
        orderId: Int,
        message: String,
        block: suspend () -> DeliveryProgress,
    ) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        try {
            val progress = block()
            _state.update {
                it.copy(
                    items = it.items + (orderId to progress),
                    loading = false,
                    message = message,
                    changeVersion = it.changeVersion + 1,
                )
            }
        } catch (error: Throwable) {
            _state.update {
                it.copy(
                    loading = false,
                    error = deliveryError(error, "Não foi possível atualizar a entrega. Tente novamente."),
                )
            }
        }
    }

    private fun syncGps(rows: List<DeliveryProgress>) {
        val hasActiveRoute = rows.any { it.routeStarted && !it.completed && it.orderStatus == "out_for_delivery" }
        if (hasActiveRoute && DeliveryLocationService.hasLocationPermission(EventMenuGoApplication.instance)) {
            DeliveryLocationService.start(EventMenuGoApplication.instance)
        } else if (!hasActiveRoute) {
            DeliveryLocationService.stop(EventMenuGoApplication.instance)
        }
    }

    private fun deliveryError(error: Throwable, fallback: String): String {
        val message = error.message?.trim().orEmpty()
        return message.takeIf { it.isNotBlank() } ?: fallback
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(
        private val repository: DeliveryProgressRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            DeliveryProgressViewModel(repository) as T
    }
}
