package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ManagerDetails
import br.com.eventmenu.go.data.ManagerOperationsRepository
import br.com.eventmenu.go.data.ManagerReopenCandidate
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ManagerActionsState(
    val details: ManagerDetails? = null,
    val reopenCandidates: List<ManagerReopenCandidate> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class ManagerActionsViewModel(private val repo: ManagerOperationsRepository) : ViewModel() {
    private val _state = MutableStateFlow(ManagerActionsState())
    val state: StateFlow<ManagerActionsState> = _state.asStateFlow()

    fun refresh() = launch { _state.update { it.copy(details = repo.details()) } }

    fun refreshReopenCandidates() = launch {
        _state.update { it.copy(reopenCandidates = repo.reopenCandidates()) }
    }

    fun transferDelivery(orderId: Int, deliveryUserId: Int) = launch {
        repo.transferDelivery(orderId, deliveryUserId)
        _state.update { it.copy(message = "Entrega #$orderId transferida.", changeVersion = it.changeVersion + 1) }
        _state.update { it.copy(details = repo.details()) }
    }

    fun reopenOrder(orderId: Int, reason: String) = launch {
        repo.reopenOrder(orderId, reason)
        _state.update { it.copy(message = "Pedido #$orderId reaberto e devolvido para Prontos.", changeVersion = it.changeVersion + 1) }
        _state.update { it.copy(reopenCandidates = repo.reopenCandidates(), details = repo.details()) }
    }

    @Deprecated("Cancelamento direto foi removido; use o fluxo de solicitação/autorização.")
    fun cancelOrder(orderId: Int) {
        _state.update { it.copy(error = "Cancelamento direto foi desativado para o pedido #$orderId. Use Solicitar cancelamento.") }
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    private fun launch(block: suspend () -> Unit) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { block() }
            .onFailure { error -> _state.update { it.copy(error = error.message ?: "Falha na operação gerencial.") } }
        _state.update { it.copy(loading = false) }
    }

    class Factory(private val repo: ManagerOperationsRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = ManagerActionsViewModel(repo) as T
    }
}
