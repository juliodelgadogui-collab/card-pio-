package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeviceStatus
import br.com.eventmenu.go.data.DeviceStatusRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeviceStatusState(
    val status: DeviceStatus? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
)

class DeviceStatusViewModel(private val repo: DeviceStatusRepository) : ViewModel() {
    private val _state = MutableStateFlow(DeviceStatusState())
    val state: StateFlow<DeviceStatusState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repo.status() }
            .onSuccess { value -> _state.update { it.copy(status = value, loading = false) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao consultar aparelho.") } }
    }

    fun requestNfcAuthorization() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repo.requestNfcAuthorization() }
            .onSuccess { value ->
                _state.update {
                    it.copy(
                        status = value,
                        loading = false,
                        message = if (value.tapOnReady) "Este aparelho já está autorizado para Tap On." else "Solicitação enviada ao administrador. Não é necessário reinstalar o app.",
                    )
                }
            }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao solicitar autorização.") } }
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }
    fun clearError() = clearFeedback()

    class Factory(private val repo: DeviceStatusRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = DeviceStatusViewModel(repo) as T
    }
}
