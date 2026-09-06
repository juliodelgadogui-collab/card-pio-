package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.OrderOperationalDetail
import br.com.eventmenu.go.data.OrderOperationsRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class OrderOperationsState(
    val detail: OrderOperationalDetail? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changedVersion: Int = 0,
)

class OrderOperationsViewModel(private val repository: OrderOperationsRepository) : ViewModel() {
    private val _state = MutableStateFlow(OrderOperationsState())
    val state: StateFlow<OrderOperationsState> = _state.asStateFlow()

    fun open(orderId: Int) = viewModelScope.launch {
        if(orderId < 1) return@launch
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.detail(orderId) }
            .onSuccess { detail -> _state.update { it.copy(detail = detail, loading = false) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao abrir pedido.") } }
    }

    fun accept(orderId: Int) = viewModelScope.launch {
        if(orderId < 1) return@launch
        _state.update { it.copy(loading = true, error = null) }
        runCatching {
            repository.accept(orderId)
            repository.detail(orderId)
        }.onSuccess { detail ->
            _state.update {
                it.copy(
                    detail = detail,
                    loading = false,
                    message = "✅ Pedido #$orderId aceito e enviado para a cozinha.",
                    changedVersion = it.changedVersion + 1,
                )
            }
        }.onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao aceitar pedido.") } }
    }

    fun close() = _state.update { it.copy(detail = null) }
    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: OrderOperationsRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = OrderOperationsViewModel(repository) as T
    }
}
