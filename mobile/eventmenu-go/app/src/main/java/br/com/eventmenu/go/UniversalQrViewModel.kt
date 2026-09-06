package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.IssuedUniversalQr
import br.com.eventmenu.go.data.ResolvedUniversalQr
import br.com.eventmenu.go.data.UniversalQrRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class UniversalQrState(
    val resolved: ResolvedUniversalQr? = null,
    val issued: IssuedUniversalQr? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
)

class UniversalQrViewModel(private val repository: UniversalQrRepository) : ViewModel() {
    private val _state = MutableStateFlow(UniversalQrState())
    val state: StateFlow<UniversalQrState> = _state.asStateFlow()

    fun resolve(value: String) = launchBusy {
        _state.update { it.copy(resolved = repository.resolve(value)) }
    }

    fun issueSelf(userId: Int, name: String, delivery: Boolean) = launchBusy {
        val type = if (delivery) "delivery_user" else "employee"
        val issued = repository.issue(type, userId, name)
        _state.update { it.copy(issued = issued, message = if (delivery) "QR de entregador gerado." else "QR do funcionário gerado.") }
    }

    fun revokeSelf(userId: Int, delivery: Boolean) = launchBusy {
        val type = if (delivery) "delivery_user" else "employee"
        repository.revoke(type, userId)
        _state.update { it.copy(issued = null, message = "QR revogado. Um código antigo não será mais aceito.") }
    }

    fun clearResolved() = _state.update { it.copy(resolved = null) }
    fun clearIssued() = _state.update { it.copy(issued = null) }
    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    private fun launchBusy(block: suspend () -> Unit) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { block() }.onFailure { error ->
            _state.update { it.copy(error = if (error is ApiException) error.message else error.message ?: "Falha no QR EventMenu.") }
        }
        _state.update { it.copy(loading = false) }
    }

    class Factory(private val repository: UniversalQrRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = UniversalQrViewModel(repository) as T
    }
}
