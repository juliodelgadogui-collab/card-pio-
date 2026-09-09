package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryExpeditionStatus
import br.com.eventmenu.go.data.ExpeditionStatusRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ExpeditionStatusState(
    val items: Map<Int, DeliveryExpeditionStatus> = emptyMap(),
    val loading: Boolean = false,
    val error: String? = null,
)

class ExpeditionStatusViewModel(
    private val repository: ExpeditionStatusRepository,
) : ViewModel() {
    private val _state = MutableStateFlow(ExpeditionStatusState())
    val state: StateFlow<ExpeditionStatusState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.status() }
            .onSuccess { rows ->
                _state.update { it.copy(items = rows.associateBy { row -> row.orderId }, loading = false) }
            }
            .onFailure {
                _state.update { state -> state.copy(loading = false, error = "Não foi possível atualizar o andamento das entregas.") }
            }
    }

    class Factory(
        private val repository: ExpeditionStatusRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            ExpeditionStatusViewModel(repository) as T
    }
}
