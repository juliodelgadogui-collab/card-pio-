package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.CardVerification
import br.com.eventmenu.go.payments.CardPaymentCoordinator
import br.com.eventmenu.go.payments.CardPaymentEvent
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class CardPaymentPhase {
    IDLE,
    PREPARING,
    AWAITING_CARD,
    PROCESSING,
    VALIDATING,
    PENDING_CONFIRMATION,
    APPROVED,
    CANCELLED,
    FAILED,
}

data class CardPaymentUiState(
    val orderId: Int? = null,
    val amountCents: Int = 0,
    val paymentMethod: String = "credit",
    val installments: Int = 1,
    val provider: String = "",
    val intentToken: String = "",
    val phase: CardPaymentPhase = CardPaymentPhase.IDLE,
    val message: String? = null,
    val verification: CardVerification? = null,
    val completedVersion: Int = 0,
) {
    val visible: Boolean get() = phase != CardPaymentPhase.IDLE
    val canRetryVerification: Boolean get() = phase == CardPaymentPhase.PENDING_CONFIRMATION && intentToken.isNotBlank()
    val blocking: Boolean get() = phase in setOf(
        CardPaymentPhase.PREPARING,
        CardPaymentPhase.AWAITING_CARD,
        CardPaymentPhase.PROCESSING,
        CardPaymentPhase.VALIDATING,
    )
}

class CardPaymentViewModel(
    private val coordinator: CardPaymentCoordinator,
) : ViewModel() {
    private val _state = MutableStateFlow(CardPaymentUiState())
    val state: StateFlow<CardPaymentUiState> = _state.asStateFlow()
    private var chargeJob: Job? = null

    fun charge(orderId: Int, amountCents: Int, method: String, installments: Int) {
        require(amountCents > 0) { "Valor da cobrança inválido." }
        startCharge(orderId, amountCents, method, installments)
    }

    /** Usado quando a tela não conhece com segurança o saldo após pagamentos parciais. */
    fun chargeRemaining(orderId: Int, method: String, installments: Int) {
        startCharge(orderId, null, method, installments)
    }

    private fun startCharge(orderId: Int, amountCents: Int?, method: String, installments: Int) {
        if (_state.value.blocking || chargeJob?.isActive == true) return
        require(method in setOf("credit", "debit")) { "Forma de cartão inválida." }
        val count = if (method == "debit") 1 else installments.coerceIn(1, 12)
        _state.value = CardPaymentUiState(
            orderId = orderId,
            amountCents = amountCents ?: 0,
            paymentMethod = method,
            installments = count,
            phase = CardPaymentPhase.PREPARING,
            message = if (amountCents == null) "Consultando o saldo restante no servidor…" else "Preparando pagamento seguro…",
            completedVersion = _state.value.completedVersion,
        )
        chargeJob = viewModelScope.launch {
            runCatching {
                coordinator.charge(
                    orderId = orderId,
                    amountCents = amountCents,
                    paymentMethod = method,
                    installmentsCount = count,
                ).collect(::consume)
            }.onFailure { error ->
                _state.update {
                    it.copy(
                        phase = if (it.intentToken.isNotBlank()) CardPaymentPhase.PENDING_CONFIRMATION else CardPaymentPhase.FAILED,
                        message = error.message ?: if (it.intentToken.isNotBlank()) {
                            "Não foi possível concluir a confirmação. Não cobre novamente até verificar."
                        } else {
                            "Não foi possível iniciar a cobrança."
                        },
                    )
                }
            }
        }
    }

    fun retryVerification() {
        val token = _state.value.intentToken
        if (token.isBlank() || _state.value.blocking) return
        viewModelScope.launch {
            _state.update { it.copy(phase = CardPaymentPhase.VALIDATING, message = "Consultando a transação diretamente no provedor…") }
            runCatching { coordinator.reconcile(token) }
                .onSuccess(::approved)
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            phase = CardPaymentPhase.PENDING_CONFIRMATION,
                            message = error.message ?: "Ainda não há confirmação. Não refaça a cobrança.",
                        )
                    }
                }
        }
    }

    fun dismiss() {
        if (_state.value.blocking) return
        _state.value = CardPaymentUiState(completedVersion = _state.value.completedVersion)
    }

    fun closeProviderSession() {
        viewModelScope.launch {
            runCatching { coordinator.tearDownProvider("sumup") }
            _state.value = CardPaymentUiState(completedVersion = _state.value.completedVersion)
        }
    }

    private suspend fun consume(event: CardPaymentEvent) {
        when (event) {
            CardPaymentEvent.Preparing -> _state.update { it.copy(phase = CardPaymentPhase.PREPARING, message = "Preparando Tap to Pay…") }
            is CardPaymentEvent.IntentCreated -> _state.update {
                it.copy(
                    provider = event.intent.provider,
                    intentToken = event.intent.intentToken,
                    orderId = event.intent.orderId,
                    amountCents = event.intent.amountCents,
                    paymentMethod = event.intent.paymentMethod,
                    installments = event.intent.installmentsCount,
                    message = "Sessão criada com segurança.",
                )
            }
            CardPaymentEvent.AwaitingCard -> _state.update { it.copy(phase = CardPaymentPhase.AWAITING_CARD, message = "Aproxime o cartão ou celular do cliente.") }
            CardPaymentEvent.Processing -> _state.update { it.copy(phase = CardPaymentPhase.PROCESSING, message = "Cartão lido. Processando…") }
            CardPaymentEvent.ValidatingServer -> _state.update { it.copy(phase = CardPaymentPhase.VALIDATING, message = "Validando a venda diretamente no servidor…") }
            is CardPaymentEvent.ServerVerified -> approved(event.verification)
            is CardPaymentEvent.VerificationPending -> _state.update {
                it.copy(phase = CardPaymentPhase.PENDING_CONFIRMATION, message = event.reason)
            }
            is CardPaymentEvent.Cancelled -> _state.update {
                it.copy(phase = CardPaymentPhase.CANCELLED, message = event.reason)
            }
            is CardPaymentEvent.Failed -> _state.update {
                it.copy(phase = CardPaymentPhase.FAILED, message = event.reason)
            }
        }
    }

    private fun approved(verification: CardVerification) {
        _state.update {
            it.copy(
                phase = CardPaymentPhase.APPROVED,
                verification = verification,
                message = if (verification.remainingCents <= 0) {
                    "Pagamento confirmado pelo servidor. Pedido quitado."
                } else {
                    "Pagamento confirmado. Restante: R$ %.2f".format(verification.remainingCents / 100.0).replace('.', ',')
                },
                completedVersion = it.completedVersion + 1,
            )
        }
    }

    class Factory(private val coordinator: CardPaymentCoordinator) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = CardPaymentViewModel(coordinator) as T
    }
}
