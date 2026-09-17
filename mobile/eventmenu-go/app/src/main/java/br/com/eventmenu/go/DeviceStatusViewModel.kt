package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeviceStatus
import br.com.eventmenu.go.data.DeviceStatusRepository
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeviceStatusState(
    val status: DeviceStatus? = null,
    val loading: Boolean = false,
    val error: String? = null,
)

class DeviceStatusViewModel(private val repo: DeviceStatusRepository) : ViewModel() {
    private val _state = MutableStateFlow(DeviceStatusState())
    val state: StateFlow<DeviceStatusState> = _state.asStateFlow()
    private var refreshJob: Job? = null

    fun refresh() {
        if (refreshJob?.isActive == true) return
        refreshJob = viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            runCatching { repo.status() }
                .onSuccess { value -> _state.update { it.copy(status = value, loading = false) } }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            loading = false,
                            error = error.message?.takeIf(String::isNotBlank) ?: "Falha ao consultar aparelho.",
                        )
                    }
                }
        }
    }

    fun clearError() = _state.update { it.copy(error = null) }

    class Factory(private val repo: DeviceStatusRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = DeviceStatusViewModel(repo) as T
    }
}
