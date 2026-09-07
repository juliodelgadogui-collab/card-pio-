package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.FinanceRepository
import br.com.eventmenu.go.data.FinanceSummary
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class FinanceState(
    val summary: FinanceSummary? = null,
    val loading: Boolean = false,
    val error: String? = null,
)

class FinanceViewModel(private val repository: FinanceRepository) : ViewModel() {
    private val _state = MutableStateFlow(FinanceState())
    val state: StateFlow<FinanceState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.summary() }
            .onSuccess { value -> _state.update { it.copy(summary = value, loading = false) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao carregar financeiro.") } }
    }

    class Factory(private val repository: FinanceRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = FinanceViewModel(repository) as T
    }
}
