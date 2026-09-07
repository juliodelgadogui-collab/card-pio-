package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.PaymentCapabilities
import br.com.eventmenu.go.data.PaymentRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class PaymentOptionsState(
    val capabilities: PaymentCapabilities? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val completedVersion: Int = 0,
) {
    val pixAvailable: Boolean get() = capabilities?.let { it.pixEnabled && it.pixProvider != null } ?: false
    val cashAvailable: Boolean get() = capabilities?.cashEnabled ?: true
    val externalTerminalAvailable: Boolean get() = capabilities?.externalTerminalEnabled ?: true
    val externalTerminalReferenceRequired: Boolean get() = capabilities?.externalTerminalReferenceRequired ?: true
    val cardPresentConfigured: Boolean get() = capabilities?.let { it.cardPresentEnabled && it.cardPresentProvider != null } ?: false
    val cardPresentAvailableInThisApk: Boolean get() = BuildConfig.SUMUP_TAP_TO_PAY && cardPresentConfigured
    val nfcConfiguredButUnavailable: Boolean get() = cardPresentConfigured && !BuildConfig.SUMUP_TAP_TO_PAY
}

class PaymentOptionsViewModel(private val repository: PaymentRepository) : ViewModel() {
    private val _state = MutableStateFlow(PaymentOptionsState())
    val state: StateFlow<PaymentOptionsState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.capabilities() }
            .onSuccess { caps -> _state.update { it.copy(capabilities = caps, loading = false) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao carregar formas de pagamento.") } }
    }

    fun confirmExternalTerminal(orderId: Int, amountCents: Int, paymentMethod: String, machineLabel: String, transactionReference: String) = viewModelScope.launch {
        if (_state.value.loading) return@launch
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repository.confirmExternalTerminal(orderId, amountCents, paymentMethod, machineLabel, transactionReference) }
            .onSuccess {
                _state.update {
                    it.copy(
                        loading = false,
                        message = "Pagamento da maquininha registrado e auditado.",
                        completedVersion = it.completedVersion + 1,
                    )
                }
            }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao registrar pagamento da maquininha.") } }
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: PaymentRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = PaymentOptionsViewModel(repository) as T
    }
}
