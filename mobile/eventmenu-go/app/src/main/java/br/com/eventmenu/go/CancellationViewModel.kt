package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.CancellationRepository
import br.com.eventmenu.go.data.CancellationRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class CancellationState(
    val pending: List<CancellationRequest> = emptyList(),
    val orderRequest: CancellationRequest? = null,
    val orderId: Int? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changeVersion: Int = 0,
)

class CancellationViewModel(private val repository: CancellationRepository) : ViewModel() {
    private val _state = MutableStateFlow(CancellationState())
    val state: StateFlow<CancellationState> = _state.asStateFlow()

    fun loadPending() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.pending() }
            .onSuccess { rows -> _state.update { it.copy(pending = rows, loading = false) } }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao carregar cancelamentos.") } }
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
            .onFailure { e -> _state.update { it.copy(error = e.message ?: "Falha ao consultar cancelamento.") } }
    }

    fun request(orderId: Int, reason: String) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repository.request(orderId, reason) }
            .onSuccess { request ->
                _state.update {
                    it.copy(
                        orderId = orderId,
                        orderRequest = request,
                        loading = false,
                        message = "Solicitação de cancelamento enviada ao gerente.",
                        changeVersion = it.changeVersion + 1,
                    )
                }
            }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao solicitar cancelamento.") } }
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
                    message = if (approve) "Cancelamento aprovado." else "Cancelamento rejeitado.",
                    changeVersion = it.changeVersion + 1,
                )
            }
        }.onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Falha ao decidir cancelamento.") } }
    }

    fun clearOrder() = _state.update { it.copy(orderId = null, orderRequest = null) }
    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: CancellationRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = CancellationViewModel(repository) as T
    }
}
