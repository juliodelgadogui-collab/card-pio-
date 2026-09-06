package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.GroupPixCharge
import br.com.eventmenu.go.data.PaymentGroup
import br.com.eventmenu.go.data.TabSplitAccount
import br.com.eventmenu.go.data.TabSplitPaymentRepository
import br.com.eventmenu.go.data.TapOnRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

data class TabSplitPaymentState(
    val tabId: Int? = null,
    val account: TabSplitAccount? = null,
    val group: PaymentGroup? = null,
    val pix: GroupPixCharge? = null,
    val pixVisible: Boolean = false,
    val tapOnRequest: TapOnRequest? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val paidVersion: Int = 0,
)

class TabSplitPaymentViewModel(private val repository: TabSplitPaymentRepository) : ViewModel() {
    private val _state = MutableStateFlow(TabSplitPaymentState())
    val state: StateFlow<TabSplitPaymentState> = _state.asStateFlow()

    fun open(tabId: Int) = launchBusy {
        val account = repository.account(tabId)
        val openGroup = account.openGroup?.let { repository.groupStatus(it.id) }
        _state.update { it.copy(tabId = tabId, account = account, group = openGroup, pix = null, pixVisible = false) }
    }

    fun close() {
        val group = _state.value.group
        if (group?.status in setOf("created", "pending")) {
            _state.update { it.copy(error = "Existe uma cobrança dividida em andamento. Finalize ou cancele antes de sair.") }
            return
        }
        _state.value = TabSplitPaymentState(paidVersion = _state.value.paidVersion)
    }

    fun refresh() = launchBusy { refreshInternal() }

    fun startValue(method: String, amountCents: Int, taxId: String = "") =
        start("value", method, JSONObject().put("amount_cents", amountCents), taxId)

    fun startPercentage(method: String, percentage: Double, taxId: String = "") =
        start("percentage", method, JSONObject().put("percentage", percentage), taxId)

    fun startPerson(method: String, peopleRemaining: Int, taxId: String = "") =
        start("person", method, JSONObject().put("people_remaining", peopleRemaining), taxId)

    fun startProducts(method: String, itemIds: Set<Int>, taxId: String = "") {
        val ids = JSONArray(); itemIds.sorted().forEach(ids::put)
        start("product", method, JSONObject().put("item_ids", ids), taxId)
    }

    private fun start(splitType: String, method: String, options: JSONObject, taxId: String) = launchBusy {
        val tabId = _state.value.tabId ?: throw IllegalStateException("Comanda não selecionada.")
        if (_state.value.group?.status in setOf("created", "pending")) throw IllegalStateException("Finalize a cobrança em andamento antes de iniciar outra.")
        val group = repository.createGroup(tabId, splitType, method, options)
        _state.update { it.copy(group = group, pix = null, pixVisible = false) }
        when (method) {
            "cash" -> {
                refreshInternal()
                val version = _state.value.paidVersion + 1
                _state.update { it.copy(group = group, paidVersion = version, message = "✅ Pagamento em dinheiro distribuído entre os pedidos.") }
            }
            "pix" -> {
                try {
                    val charge = repository.pix(group.id, taxId)
                    _state.update { it.copy(group = repository.groupStatus(group.id), pix = charge, pixVisible = true) }
                } catch (error: Throwable) {
                    runCatching { repository.groupStatus(group.id) }.onSuccess { current -> _state.update { it.copy(group = current) } }
                    throw error
                }
            }
            "nfc" -> {
                try {
                    _state.update { it.copy(tapOnRequest = repository.nfcIntent(group.id)) }
                } catch (error: Throwable) {
                    runCatching { repository.cancel(group.id) }.onSuccess { cancelled -> _state.update { it.copy(group = cancelled) } }
                    throw error
                }
            }
        }
    }

    fun pollPix() {
        val groupId = _state.value.group?.id ?: return
        viewModelScope.launch {
            runCatching { repository.pixStatus(groupId) }.onSuccess { (group, paid) ->
                _state.update { it.copy(group = group) }
                if (paid || group.status in setOf("paid", "attention")) {
                    _state.update { it.copy(pixVisible = false) }
                    refreshInternalSafe()
                    if (group.status == "paid") {
                        val version = _state.value.paidVersion + 1
                        _state.update { it.copy(paidVersion = version, message = "✅ PIX confirmado e distribuído pela comanda.") }
                    } else {
                        _state.update { it.copy(error = "O PagBank confirmou a cobrança, mas o grupo exige conferência administrativa.") }
                    }
                }
            }.onFailure { error -> _state.update { it.copy(error = error.message ?: "Falha ao consultar PIX.") } }
        }
    }

    fun hidePix() = _state.update { it.copy(pixVisible = false) }
    fun showPix() { if (_state.value.pix != null) _state.update { it.copy(pixVisible = true) } }

    fun consumeTapOnLaunch() = _state.update { it.copy(tapOnRequest = null) }

    fun verifyNfc(request: TapOnRequest, transactionCode: String) = launchBusy {
        val group = repository.verifyNfc(request.intentToken, transactionCode)
        _state.update { it.copy(group = group, tapOnRequest = null) }
        refreshInternal()
        if (group.status == "paid") {
            val version = _state.value.paidVersion + 1
            _state.update { it.copy(paidVersion = version, message = "✅ Cartão confirmado e distribuído pela comanda.") }
        } else {
            _state.update { it.copy(error = "A transação foi confirmada, mas a divisão exige conferência administrativa.") }
        }
    }

    fun nfcCancelled() = _state.update { it.copy(tapOnRequest = null, message = "Pagamento NFC cancelado no Tap On.") }

    fun cancelGroup() = launchBusy {
        val group = _state.value.group ?: return@launchBusy
        val cancelled = repository.cancel(group.id)
        _state.update { it.copy(group = cancelled, pix = null, pixVisible = false, tapOnRequest = null, message = "Divisão cancelada. O saldo voltou a ficar disponível.") }
        refreshInternal()
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    private suspend fun refreshInternal() {
        val tabId = _state.value.tabId ?: return
        val account = repository.account(tabId)
        val group = _state.value.group?.let { runCatching { repository.groupStatus(it.id) }.getOrNull() }
        _state.update { it.copy(account = account, group = group) }
    }

    private suspend fun refreshInternalSafe() { runCatching { refreshInternal() } }

    private fun launchBusy(block: suspend () -> Unit) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { block() }.onFailure { error ->
            _state.update { it.copy(error = if (error is ApiException) error.message else error.message ?: "Falha na divisão da comanda.") }
        }
        _state.update { it.copy(loading = false) }
    }

    class Factory(private val repository: TabSplitPaymentRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = TabSplitPaymentViewModel(repository) as T
    }
}
