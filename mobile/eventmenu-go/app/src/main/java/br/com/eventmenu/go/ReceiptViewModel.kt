package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ReceiptRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ReceiptState(
    val loading: Boolean = false,
    val shareText: String? = null,
    val error: String? = null,
)

class ReceiptViewModel(private val repo: ReceiptRepository) : ViewModel() {
    private val _state = MutableStateFlow(ReceiptState())
    val state: StateFlow<ReceiptState> = _state.asStateFlow()

    fun prepare(orderId: Int) = viewModelScope.launch {
        if (orderId < 1) return@launch
        _state.update { it.copy(loading = true, error = null, shareText = null) }
        runCatching { repo.shareText(repo.order(orderId)) }
            .onSuccess { text -> _state.update { it.copy(loading = false, shareText = text) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao gerar comprovante.") } }
    }

    fun consumed() = _state.update { it.copy(shareText = null) }
    fun clearError() = _state.update { it.copy(error = null) }

    class Factory(private val repo: ReceiptRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = ReceiptViewModel(repo) as T
    }
}
