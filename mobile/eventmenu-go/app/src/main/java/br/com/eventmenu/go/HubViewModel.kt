package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.HubCommand
import br.com.eventmenu.go.data.HubLink
import br.com.eventmenu.go.data.HubRepository
import br.com.eventmenu.go.data.HubTerminal
import kotlinx.coroutines.cancelChildren
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class HubState(
    val links: List<HubLink> = emptyList(),
    val selectedLinkId: Int? = null,
    val terminals: List<HubTerminal> = emptyList(),
    val selectedTerminalId: Int? = null,
    val loading: Boolean = false,
    val commandBusy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val lastCommand: HubCommand? = null,
    val pairingVersion: Int = 0,
) {
    val selected: HubLink? get() = links.firstOrNull { it.id == selectedLinkId } ?: links.firstOrNull()
    val selectedTerminal: HubTerminal? get() = terminals.firstOrNull { it.id == selectedTerminalId } ?: terminals.firstOrNull()
}

class HubViewModel(private val repository: HubRepository) : ViewModel() {
    private val _state = MutableStateFlow(HubState())
    val state: StateFlow<HubState> = _state.asStateFlow()

    fun refresh() = loadLinks(showLoading = true)
    fun heartbeat() = loadLinks(showLoading = false)

    /**
     * Remove qualquer vínculo/comando da sessão anterior da memória da tela.
     * Também cancela requests e watchers ainda em andamento para impedir que uma
     * resposta antiga repovoe o Hub depois de logout, troca de usuário ou unidade.
     */
    fun reset() {
        viewModelScope.coroutineContext.cancelChildren()
        _state.value = HubState()
    }

    private fun loadLinks(showLoading: Boolean) = viewModelScope.launch {
        if (showLoading) _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.links() }
            .onSuccess { links ->
                val selected = _state.value.selectedLinkId?.takeIf { id -> links.any { it.id == id } }
                    ?: links.firstOrNull { it.online }?.id
                    ?: links.firstOrNull()?.id
                _state.update { it.copy(links = links, selectedLinkId = selected, loading = false) }
            }
            .onFailure { e ->
                if (showLoading) {
                    _state.update { it.copy(loading = false, error = e.message ?: "Falha ao carregar o Hub.") }
                }
            }
    }

    fun select(linkId: Int) {
        if (_state.value.commandBusy) return
        _state.update { it.copy(selectedLinkId = linkId, terminals = emptyList(), selectedTerminalId = null) }
    }

    fun loadTerminals() = viewModelScope.launch {
        val unitId = _state.value.selected?.unitId ?: return@launch
        runCatching { repository.terminals(unitId) }
            .onSuccess { terminals ->
                val selected = _state.value.selectedTerminalId?.takeIf { id -> terminals.any { it.id == id } }
                    ?: terminals.firstOrNull()?.id
                _state.update { it.copy(terminals = terminals, selectedTerminalId = selected) }
            }
            .onFailure { e -> _state.update { it.copy(error = e.message ?: "Não foi possível carregar os PINPads.") } }
    }

    fun selectTerminal(id: Int) {
        if (_state.value.commandBusy) return
        _state.update { it.copy(selectedTerminalId = id) }
    }

    fun claimPairing(qr: String, label: String) = viewModelScope.launch {
        if (_state.value.commandBusy) {
            _state.update { it.copy(error = "Aguarde a operação atual terminar antes de parear outro computador.") }
            return@launch
        }
        _state.update { it.copy(loading = true, error = null, message = null) }
        runCatching { repository.claimPairing(qr, label) }
            .onSuccess { link ->
                _state.update { it.copy(loading = false, selectedLinkId = link.id, pairingVersion = it.pairingVersion + 1, message = "Computador vinculado ao celular.") }
                refresh()
            }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Não foi possível vincular o computador.") } }
    }

    fun printOrder(orderId: Int) = withSelected { repository.printOrder(it, orderId) }
    fun printReceipt(orderId: Int) = withSelected { repository.printReceipt(it, orderId) }
    fun openDrawer(reason: String) = withSelected { repository.openDrawer(it, reason) }
    fun showCustomerDisplay(orderId: Int) = withSelected { repository.showCustomerDisplay(it, orderId) }
    fun playAlert(message: String) = withSelected { repository.playAlert(it, message) }

    fun chargeTef(orderId: Int, amountCents: Int, paymentType: String, installments: Int) {
        val terminal = _state.value.selectedTerminal
        if (terminal == null) {
            _state.update { it.copy(error = "Nenhum PINPad está disponível nesta unidade.") }
            return
        }
        withSelected { repository.chargeTef(it, orderId, terminal.id, amountCents, paymentType, installments) }
    }

    fun revokeSelected() = viewModelScope.launch {
        if (_state.value.commandBusy) {
            _state.update { it.copy(error = "Aguarde a operação atual terminar antes de remover o vínculo.") }
            return@launch
        }
        val link = _state.value.selected ?: return@launch
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.revokeLink(link.id) }
            .onSuccess {
                _state.update { it.copy(loading = false, message = "Vínculo removido.", selectedLinkId = null, terminals = emptyList(), selectedTerminalId = null) }
                refresh()
            }
            .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Não foi possível remover o vínculo.") } }
    }

    private fun withSelected(block: suspend (HubLink) -> HubCommand) = viewModelScope.launch {
        if (_state.value.commandBusy) {
            _state.update { it.copy(error = "Aguarde a solicitação atual terminar antes de enviar outra.") }
            return@launch
        }
        val link = _state.value.selected
        if (link == null) {
            _state.update { it.copy(error = "Vincule este celular a um computador EventMenu.") }
            return@launch
        }
        if (!link.online) {
            _state.update { it.copy(error = "O computador EventMenu está offline.") }
            return@launch
        }
        _state.update { it.copy(loading = true, commandBusy = true, error = null, message = null, lastCommand = null) }
        runCatching { block(link) }
            .onSuccess { command ->
                _state.update { it.copy(loading = false, lastCommand = command, message = "Solicitação enviada ao ${link.desktopLabel}.") }
                watchCommand(command.id)
            }
            .onFailure { e ->
                _state.update { it.copy(loading = false, commandBusy = false, error = e.message ?: "Falha ao enviar comando ao computador.") }
            }
    }

    private fun watchCommand(commandId: Int) = viewModelScope.launch {
        // O servidor mantém um comando do Hub válido por até 120 segundos.
        // Acompanhamos a mesma janela para não liberar um segundo comando enquanto
        // o primeiro ainda pode ser executado pelo computador.
        repeat(80) {
            delay(1_500)
            val result = runCatching { repository.commandStatus(commandId) }.getOrNull()
                ?: return@repeat

            _state.update { it.copy(lastCommand = result) }
            when (result.status) {
                "completed" -> {
                    when {
                        result.commandType == "tef_charge" && result.approvedLocal == false ->
                            _state.update { it.copy(commandBusy = false, error = result.resultMessage.ifBlank { "A cobrança não foi aprovada no PINPad." }) }
                        result.commandType == "tef_charge" && result.verified == true ->
                            _state.update { it.copy(commandBusy = false, message = "Pagamento confirmado pelo servidor/provedor.") }
                        result.commandType == "tef_charge" && result.approvedLocal == true ->
                            _state.update { it.copy(commandBusy = false, message = "PINPad aprovou. Aguardando confirmação do provedor; o pedido ainda não foi marcado como pago.") }
                        else -> _state.update { it.copy(commandBusy = false, message = "Comando concluído no computador.") }
                    }
                    return@launch
                }
                "failed" -> {
                    _state.update { it.copy(commandBusy = false, error = result.error.ifBlank { "O computador não conseguiu concluir a operação." }) }
                    return@launch
                }
                "expired", "cancelled" -> {
                    _state.update { it.copy(commandBusy = false, error = "A solicitação expirou ou foi cancelada.") }
                    return@launch
                }
            }
        }
        _state.update {
            it.copy(
                commandBusy = false,
                error = "A solicitação passou do tempo de resposta do Hub. Atualize a tela antes de tentar novamente.",
            )
        }
    }

    fun clearFeedback() { _state.update { it.copy(error = null, message = null) } }

    class Factory(private val repository: HubRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = HubViewModel(repository) as T
    }
}
