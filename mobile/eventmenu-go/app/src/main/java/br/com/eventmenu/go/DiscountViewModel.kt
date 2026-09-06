package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DiscountRepository
import br.com.eventmenu.go.data.DiscountRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DiscountState(
    val pending: List<DiscountRequest> = emptyList(),
    val orderRequest: DiscountRequest? = null,
    val orderId: Int? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class DiscountViewModel(private val repository: DiscountRepository) : ViewModel() {
    private val _state = MutableStateFlow(DiscountState())
    val state: StateFlow<DiscountState> = _state.asStateFlow()

    fun loadPending() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.pending() }
            .onSuccess { rows -> _state.update { it.copy(pending = rows, loading = false) } }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao carregar descontos.") } }
    }

    fun watchOrder(orderId: Int) = viewModelScope.launch {
        if (orderId < 1) return@launch
        val before = _state.value.orderRequest
        runCatching { repository.forOrder(orderId) }
            .onSuccess { request ->
                val changed = before?.id == request?.id && before?.status != request?.status
                _state.update {
                    it.copy(
                        orderId = orderId,
                        orderRequest = request,
                        error = null,
                        changeVersion = if (changed) it.changeVersion + 1 else it.changeVersion,
                    )
                }
            }
            .onFailure { e -> _state.update { it.copy(error = e.message ?: "Falha ao consultar desconto.") } }
    }

    fun request(orderId: Int, amountCents: Int, reason: String) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repository.request(orderId, amountCents, reason) }
            .onSuccess { request ->
                _state.update {
                    it.copy(
                        orderId = orderId,
                        orderRequest = request,
                        loading = false,
                        message = "Solicitação enviada ao gerente.",
                        changeVersion = it.changeVersion + 1,
                    )
                }
            }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao solicitar desconto.") } }
    }

    fun approve(requestId: Int) = decide(requestId, true, "")
    fun reject(requestId: Int, reason: String) = decide(requestId, false, reason)

    private fun decide(requestId: Int, approve: Boolean, reason: String) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching {
            if (approve) repository.approve(requestId) else repository.reject(requestId, reason)
            repository.pending()
        }.onSuccess { rows ->
            _state.update {
                it.copy(
                    pending = rows,
                    loading = false,
                    message = if (approve) "Desconto aprovado." else "Desconto rejeitado.",
                    changeVersion = it.changeVersion + 1,
                )
            }
        }.onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao decidir desconto.") } }
    }

    fun clearOrder() = _state.update { it.copy(orderId = null, orderRequest = null) }
    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: DiscountRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = DiscountViewModel(repository) as T
    }
}
