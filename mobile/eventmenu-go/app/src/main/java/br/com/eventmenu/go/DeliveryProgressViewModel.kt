package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.DeliveryProgressRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeliveryProgressState(
    val items: Map<Int, DeliveryProgress> = emptyMap(),
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class DeliveryProgressViewModel(private val repository: DeliveryProgressRepository) : ViewModel() {
    private val _state = MutableStateFlow(DeliveryProgressState())
    val state: StateFlow<DeliveryProgressState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.listMine() }
            .onSuccess { rows -> _state.update { it.copy(items = rows.associateBy { row -> row.orderId }, loading = false) } }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao carregar progresso das entregas.") } }
    }

    fun pickup(orderId: Int) = runAction(orderId, "Pedido retirado no balcão.") { repository.pickup(orderId) }
    fun startRoute(orderId: Int) = runAction(orderId, "Rota iniciada.") { repository.startRoute(orderId) }
    fun arrive(orderId: Int) = runAction(orderId, "Chegada registrada. Pagamento liberado pelo servidor.") { repository.arrive(orderId) }
    fun complete(orderId: Int) = runAction(orderId, "Entrega concluída.") { repository.complete(orderId) }

    private fun runAction(orderId: Int, message: String, block: suspend () -> DeliveryProgress) = viewModelScope.launch {
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
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao atualizar entrega.") } }
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: DeliveryProgressRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = DeliveryProgressViewModel(repository) as T
    }
}
