package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.CreatedOrder
import br.com.eventmenu.go.data.EventBarRepository
import br.com.eventmenu.go.data.PaymentBalance
import br.com.eventmenu.go.data.PixCharge
import br.com.eventmenu.go.data.Product
import br.com.eventmenu.go.data.TapOnRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class EventBarState(
    val eventId: Int? = null,
    val eventName: String = "",
    val products: List<Product> = emptyList(),
    val cart: Map<Int, Int> = emptyMap(),
    val order: CreatedOrder? = null,
    val balance: PaymentBalance? = null,
    val pixCharge: PixCharge? = null,
    val tapOnRequest: TapOnRequest? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val completedVersion: Int = 0,
)

class EventBarViewModel(private val repository: EventBarRepository) : ViewModel() {
    private val _state = MutableStateFlow(EventBarState())
    val state: StateFlow<EventBarState> = _state.asStateFlow()

    fun open(eventId: Int, eventName: String) = launchBusy {
        if (eventId < 1) throw IllegalStateException("Evento inválido.")
        _state.value = EventBarState(eventId = eventId, eventName = eventName, loading = true)
        val products = repository.catalog()
        _state.update { it.copy(products = products, loading = false) }
    }

    fun close() {
        val state = _state.value
        if (state.order != null && (state.balance?.remainingCents ?: state.order.totalCents) > 0) {
            _state.update { it.copy(error = "Finalize o pagamento desta venda antes de sair do Bar.") }
            return
        }
        _state.value = EventBarState(completedVersion = state.completedVersion)
    }

    fun add(productId: Int) {
        val product = _state.value.products.firstOrNull { it.id == productId } ?: return
        val current = _state.value.cart[productId] ?: 0
        if (product.trackStock && current + 1 > product.stockQty.toInt()) {
            _state.update { it.copy(error = "Estoque disponível insuficiente para ${product.name}.") }
            return
        }
        _state.update { it.copy(cart = it.cart + (productId to current + 1)) }
    }

    fun remove(productId: Int) {
        val current = _state.value.cart[productId] ?: return
        _state.update { state ->
            if (current <= 1) state.copy(cart = state.cart - productId)
            else state.copy(cart = state.cart + (productId to current - 1))
        }
    }

    fun clearCart() = _state.update { it.copy(cart = emptyMap()) }

    fun create(notes: String = "") = launchBusy {
        val eventId = _state.value.eventId ?: throw IllegalStateException("Evento do Bar não selecionado.")
        val order = repository.create(eventId, _state.value.cart, notes)
        val balance = repository.balance(order.id)
        _state.update { it.copy(order = order, balance = balance, cart = emptyMap(), message = "Venda #${order.id} criada. Escolha a forma de pagamento.") }
    }

    fun refreshPayment() = launchBusy {
        val orderId = _state.value.order?.id ?: return@launchBusy
        _state.update { it.copy(balance = repository.balance(orderId)) }
    }

    fun payCash(amountCents: Int) = launchBusy {
        val orderId = _state.value.order?.id ?: throw IllegalStateException("Venda do Bar não encontrada.")
        val balance = repository.cash(orderId, amountCents)
        _state.update { it.copy(balance = balance, message = "Dinheiro recebido: ${money(amountCents)}") }
    }

    fun requestPix(amountCents: Int, taxId: String) = launchBusy {
        val orderId = _state.value.order?.id ?: throw IllegalStateException("Venda do Bar não encontrada.")
        _state.update { it.copy(pixCharge = repository.pix(orderId, amountCents, taxId)) }
    }

    fun pollPix() {
        val charge = _state.value.pixCharge ?: return
        viewModelScope.launch {
            runCatching { repository.balance(charge.orderId) }.onSuccess { balance ->
                val part = balance.payments.firstOrNull { it.id == charge.paymentId }
                _state.update { it.copy(balance = balance) }
                if (part?.status == "paid") {
                    _state.update { it.copy(pixCharge = null, message = "✅ PIX RECEBIDO — ${money(charge.amountCents)}") }
                }
            }
        }
    }

    fun dismissPix() = _state.update { it.copy(pixCharge = null) }

    fun requestNfc(amountCents: Int) = launchBusy {
        val orderId = _state.value.order?.id ?: throw IllegalStateException("Venda do Bar não encontrada.")
        _state.update { it.copy(tapOnRequest = repository.nfc(orderId, amountCents)) }
    }

    fun consumeTapOnLaunch() = _state.update { it.copy(tapOnRequest = null) }

    fun verifyNfc(request: TapOnRequest, transactionCode: String) = launchBusy {
        val balance = repository.verifyNfc(request.intentToken, transactionCode)
        _state.update { it.copy(balance = balance, tapOnRequest = null, message = "Cartão confirmado pelo servidor.") }
    }

    fun nfcCancelled() = _state.update { it.copy(tapOnRequest = null, message = "Pagamento NFC cancelado.") }

    fun finishSale() = launchBusy {
        val order = _state.value.order ?: throw IllegalStateException("Venda do Bar não encontrada.")
        val balance = repository.balance(order.id)
        if (balance.remainingCents != 0 || balance.paymentStatus != "paid") {
            _state.update { it.copy(balance = balance) }
            throw IllegalStateException("O pagamento ainda não foi confirmado integralmente pelo servidor.")
        }
        repository.complete(order.id)
        val nextVersion = _state.value.completedVersion + 1
        _state.value = EventBarState(completedVersion = nextVersion, message = "✅ Venda #${order.id} entregue e concluída.")
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    private fun launchBusy(block: suspend () -> Unit) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { block() }.onFailure { error ->
            _state.update { it.copy(error = if (error is ApiException) error.message else error.message ?: "Falha na venda do Bar.") }
        }
        _state.update { it.copy(loading = false) }
    }

    private fun money(cents: Int) = "R$ %.2f".format(cents / 100.0).replace('.', ',')

    class Factory(private val repository: EventBarRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = EventBarViewModel(repository) as T
    }
}
