package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ManagerDetails
import br.com.eventmenu.go.data.ManagerOperationsRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ManagerActionsState(
    val details: ManagerDetails? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class ManagerActionsViewModel(private val repo: ManagerOperationsRepository) : ViewModel() {
    private val _state = MutableStateFlow(ManagerActionsState())
    val state: StateFlow<ManagerActionsState> = _state.asStateFlow()

    fun refresh() = launch { _state.update { it.copy(details = repo.details()) } }

    fun transferDelivery(orderId: Int, deliveryUserId: Int) = launch {
        repo.transferDelivery(orderId, deliveryUserId)
        _state.update { it.copy(message = "Entrega #$orderId transferida.", changeVersion = it.changeVersion + 1) }
        _state.update { it.copy(details = repo.details()) }
    }

    fun cancelOrder(orderId: Int) = launch {
        repo.cancelOrder(orderId)
        _state.update { it.copy(message = "Pedido #$orderId cancelado.", changeVersion = it.changeVersion + 1) }
        _state.update { it.copy(details = repo.details()) }
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
