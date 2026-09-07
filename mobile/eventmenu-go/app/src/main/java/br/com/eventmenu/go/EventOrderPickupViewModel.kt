package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.EventOperationsRepository
import br.com.eventmenu.go.data.EventPickupOrder
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class EventOrderPickupState(
    val order: EventPickupOrder? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val completedVersion: Int = 0,
)

class EventOrderPickupViewModel(private val repository: EventOperationsRepository) : ViewModel() {
    private val _state = MutableStateFlow(EventOrderPickupState())
    val state: StateFlow<EventOrderPickupState> = _state.asStateFlow()

    fun resolve(eventId: Int, raw: String) = launchBusy {
        _state.update { it.copy(order = null, message = null) }
        val order = repository.resolveBarOrder(eventId, raw)
        _state.update { it.copy(order = order) }
    }

    fun deliver() = launchBusy {
        val current = _state.value.order ?: throw IllegalStateException("Leia o QR do pedido primeiro.")
        if (current.alreadyDelivered) {
            _state.update { it.copy(message = "Este pedido já foi entregue.") }
            return@launchBusy
        }
        val delivered = repository.deliverBarOrder(current.eventId, current.publicToken)
        _state.update {
            it.copy(
                order = delivered,
                message = "✅ Pedido #${delivered.id} entregue.",
                completedVersion = it.completedVersion + 1,
            )
        }
    }

    fun dismiss() = _state.update { it.copy(order = null, error = null, message = null) }
    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    private fun launchBusy(block: suspend () -> Unit) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { block() }
            .onFailure { error ->
                val message = if (error is ApiException) {
                    error.message.orEmpty()
                } else {
                    error.message ?: "Não foi possível consultar o pedido."
                }
                _state.update { it.copy(error = friendly(message)) }
            }
        _state.update { it.copy(loading = false) }
    }

    private fun friendly(raw: String): String {
        val clean = raw.trim().replace(Regex("^(Financeiro|API|Servidor|Gateway)\\s*:\\s*", RegexOption.IGNORE_CASE), "")
        return if (Regex("sql|sqlite|mysql|pdo|http\\s*\\d|json|exception|database|stack", RegexOption.IGNORE_CASE).containsMatchIn(clean)) {
            "Não foi possível consultar o pedido. Tente novamente."
        } else clean.ifBlank { "Não foi possível consultar o pedido. Tente novamente." }
    }

    class Factory(private val repository: EventOperationsRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = EventOrderPickupViewModel(repository) as T
    }
}
