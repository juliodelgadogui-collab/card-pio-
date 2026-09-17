package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.DeliveryProgressRepository
import br.com.eventmenu.go.location.DeliveryLocationService
import br.com.eventmenu.go.location.DeliveryRoutePermissionCoordinator
import br.com.eventmenu.go.location.LocationPermissionActivity
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
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
    private var autoRefreshJob: Job? = null

    init {
        viewModelScope.launch {
            DeliveryRoutePermissionCoordinator.grantedOrders.collectLatest { orderId ->
                startRouteInternal(orderId)
            }
        }
    }

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.listMine() }
            .onSuccess { rows ->
                applyRows(rows, loading = false)
                syncGps(rows)
                ensureAutoRefresh()
            }
            .onFailure { _state.update { it.copy(loading = false, error = "Não foi possível atualizar as entregas.") } }
    }

    private fun ensureAutoRefresh() {
        if (autoRefreshJob?.isActive == true) return
        autoRefreshJob = viewModelScope.launch {
            while (true) {
                delay(8_000)
                val rows = runCatching { repository.listMine() }.getOrElse {
                    // Normalmente significa que o turno Delivery foi encerrado/trocado.
                    // A próxima entrada na tela chama refresh() e reinicia a sincronização.
                    return@launch
                }
                applyRows(rows, loading = false)
                syncGps(rows)
            }
        }
    }

    private fun applyRows(rows: List<DeliveryProgress>, loading: Boolean) {
        val next = rows.associateBy { it.orderId }
        _state.update {
            it.copy(
                items = next,
                loading = loading,
                error = null,
                // O EventMenuGoApp observa esta versão e atualiza também state.orders.
                // Isso elimina a divergência entre api-go-delivery e a lista usada pela tela.
                changeVersion = it.changeVersion + 1,
            )
        }
    }

    fun pickup(orderId: Int) = runAction(orderId, "Pedido retirado.") { repository.pickup(orderId) }

    fun startRoute(orderId: Int) {
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
        runCatching { repository.startRoute(orderId) }
            .onSuccess { progress ->
                _state.update {
                    it.copy(
                        items = it.items + (orderId to progress),
                        loading = false,
                        message = "Rota iniciada. GPS ao vivo ativado para esta entrega.",
                        changeVersion = it.changeVersion + 1,
                    )
                }
                DeliveryLocationService.start(EventMenuGoApplication.instance)
            }
            .onFailure {
                _state.update { state -> state.copy(loading = false, error = "Não foi possível iniciar a rota. Atualize a entrega e tente novamente.") }
            }
    }

    fun arrive(orderId: Int) = runAction(
        orderId,
        "Chegada confirmada. Você já pode receber o pagamento, se necessário.",
    ) { repository.arrive(orderId) }

    fun complete(orderId: Int) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repository.complete(orderId) }
            .onSuccess { progress ->
                _state.update {
                    it.copy(
                        items = it.items + (orderId to progress),
                        loading = false,
                        message = "Entrega concluída. Rastreamento encerrado.",
                        changeVersion = it.changeVersion + 1,
                    )
                }
                refresh()
            }
            .onFailure { _state.update { it.copy(loading = false, error = "Não foi possível concluir a entrega.") } }
    }

    private fun runAction(
        orderId: Int,
        message: String,
        block: suspend () -> DeliveryProgress,
    ) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { block() }
            .onSuccess { progress ->
                _state.update {
                    it.copy(
                        items = it.items + (orderId to progress),
                        loading = false,
                        message = message,
                        changeVersion = it.changeVersion + 1,
                    )
                }
            }
            .onFailure { _state.update { it.copy(loading = false, error = "Não foi possível atualizar a entrega. Tente novamente.") } }
    }

    private fun syncGps(rows: List<DeliveryProgress>) {
        val hasActiveRoute = rows.any { it.routeStarted && !it.completed && it.orderStatus == "out_for_delivery" }
        if (hasActiveRoute && DeliveryLocationService.hasLocationPermission(EventMenuGoApplication.instance)) {
            DeliveryLocationService.start(EventMenuGoApplication.instance)
        } else if (!hasActiveRoute) {
            DeliveryLocationService.stop(EventMenuGoApplication.instance)
        }
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
