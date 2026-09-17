package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.HubCommand
import br.com.eventmenu.go.data.HubLink
import br.com.eventmenu.go.data.HubRepository
import br.com.eventmenu.go.data.HubTerminal
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

data class HubState(
    val links:List<HubLink> = emptyList(), val selectedLinkId:Int? = null,
    val terminals:List<HubTerminal> = emptyList(), val selectedTerminalId:Int? = null,
    val loading:Boolean = false, val error:String? = null, val message:String? = null,
    val lastCommand:HubCommand? = null, val pairingVersion:Int = 0,
    val monitoring:Boolean = false, val consecutiveFailures:Int = 0,
) {
    val selected:HubLink? get()=links.firstOrNull{it.id==selectedLinkId}?:links.firstOrNull()
    val selectedTerminal:HubTerminal? get()=terminals.firstOrNull{it.id==selectedTerminalId}?:terminals.firstOrNull()
}

class HubViewModel(private val repository:HubRepository):ViewModel(){
    private val _state=MutableStateFlow(HubState());val state:StateFlow<HubState> = _state.asStateFlow()
    private var monitorJob:Job?=null

    fun refresh()=viewModelScope.launch{refreshInternal(showLoading=true)}

    private suspend fun refreshInternal(showLoading:Boolean){
        if(showLoading)_state.update{it.copy(loading=true,error=null)}
        runCatching{repository.links()}.onSuccess{links->
            val selected=_state.value.selectedLinkId?.takeIf{id->links.any{it.id==id}}?:links.firstOrNull{it.online}?.id?:links.firstOrNull()?.id
            _state.update{it.copy(links=links,selectedLinkId=selected,loading=false,consecutiveFailures=0)}
        }.onFailure{e->
            val failures=_state.value.consecutiveFailures+1
            _state.update{it.copy(loading=false,consecutiveFailures=failures,error=if(showLoading)e.message?:"Falha ao carregar o Hub." else it.error)}
        }
    }

    fun startMonitoring(){
        if(monitorJob?.isActive==true)return
        monitorJob=viewModelScope.launch{
            _state.update{it.copy(monitoring=true)}
            while(isActive){
                refreshInternal(showLoading=false)
                val failures=_state.value.consecutiveFailures
                val wait=when{failures<=0->10_000L;failures==1->15_000L;failures==2->30_000L;else->60_000L}
                delay(wait)
            }
        }
    }
    fun stopMonitoring(){monitorJob?.cancel();monitorJob=null;_state.update{it.copy(monitoring=false)}}

    fun select(linkId:Int){_state.update{it.copy(selectedLinkId=linkId,terminals=emptyList(),selectedTerminalId=null)}}
    fun loadTerminals()=viewModelScope.launch{val unitId=_state.value.selected?.unitId?:return@launch;runCatching{repository.terminals(unitId)}.onSuccess{list->val selected=_state.value.selectedTerminalId?.takeIf{id->list.any{it.id==id}}?:list.firstOrNull()?.id;_state.update{it.copy(terminals=list,selectedTerminalId=selected)}}.onFailure{e->_state.update{it.copy(error=e.message?:"Não foi possível carregar os PINPads.")}}}
    fun selectTerminal(id:Int){_state.update{it.copy(selectedTerminalId=id)}}

    fun claimPairing(qr:String,label:String)=viewModelScope.launch{
        val code=qr.trim();if(code.isBlank()){_state.update{it.copy(error="Informe ou leia o código exibido no computador.")};return@launch}
        _state.update{it.copy(loading=true,error=null,message=null)}
        runCatching{repository.claimPairing(code,label)}.onSuccess{link->_state.update{it.copy(loading=false,selectedLinkId=link.id,pairingVersion=it.pairingVersion+1,message="Computador vinculado ao celular.")};refreshInternal(false);startMonitoring()}.onFailure{e->_state.update{it.copy(loading=false,error=e.message?:"Não foi possível vincular o computador.")}}
    }

    fun printOrder(orderId:Int)=withSelected{repository.printOrder(it,orderId)}
    fun printReceipt(orderId:Int)=withSelected{repository.printReceipt(it,orderId)}
    fun openDrawer(reason:String)=withSelected{repository.openDrawer(it,reason)}
    fun showCustomerDisplay(orderId:Int)=withSelected{repository.showCustomerDisplay(it,orderId)}
    fun playAlert(message:String)=withSelected{repository.playAlert(it,message)}
    fun chargeTef(orderId:Int,amountCents:Int,paymentType:String,installments:Int){val terminal=_state.value.selectedTerminal;if(terminal==null){_state.update{it.copy(error="Nenhum PINPad está disponível nesta unidade.")};return};withSelected{repository.chargeTef(it,orderId,terminal.id,amountCents,paymentType,installments)}}

    fun revokeSelected()=viewModelScope.launch{val link=_state.value.selected?:return@launch;_state.update{it.copy(loading=true,error=null)};runCatching{repository.revokeLink(link.id)}.onSuccess{_state.update{it.copy(loading=false,message="Vínculo removido.",selectedLinkId=null,terminals=emptyList(),selectedTerminalId=null)};refreshInternal(false)}.onFailure{e->_state.update{it.copy(loading=false,error=e.message?:"Não foi possível remover o vínculo.")}}}

    private fun withSelected(block:suspend(HubLink)->HubCommand)=viewModelScope.launch{
        val link=_state.value.selected;if(link==null){_state.update{it.copy(error="Vincule este celular a um computador EventMenu.")};return@launch}
        if(!link.online){_state.update{it.copy(error="O computador EventMenu está offline. Você pode continuar usando as demais funções normalmente.")};return@launch}
        _state.update{it.copy(loading=true,error=null,message=null,lastCommand=null)}
        runCatching{block(link)}.onSuccess{command->_state.update{it.copy(loading=false,lastCommand=command,message="Solicitação enviada ao ${link.desktopLabel}.")};watchCommand(command.id)}.onFailure{e->_state.update{it.copy(loading=false,error=e.message?:"Falha ao enviar comando ao computador.")}}
    }

    private fun watchCommand(commandId:Int)=viewModelScope.launch{
        var delayMs=750L;val deadline=System.currentTimeMillis()+120_000L;var transientFailures=0
        while(isActive&&System.currentTimeMillis()<deadline){
            delay(delayMs)
            val result=runCatching{repository.commandStatus(commandId)}.getOrElse{transientFailures++;delayMs=(delayMs*2).coerceAtMost(8_000L);if(transientFailures>=5){_state.update{it.copy(error="A conexão com o computador está instável. O pedido continua disponível normalmente.")};return@launch};continue}
            transientFailures=0;delayMs=(delayMs+500L).coerceAtMost(3_000L);_state.update{it.copy(lastCommand=result)}
            when(result.status){
                "completed"->{when{result.commandType=="tef_charge"&&result.approvedLocal==false->_state.update{it.copy(error=result.resultMessage.ifBlank{"A cobrança não foi aprovada no PINPad."})};result.commandType=="tef_charge"&&result.verified==true->_state.update{it.copy(message="Pagamento confirmado pelo servidor/provedor.")};result.commandType=="tef_charge"&&result.approvedLocal==true->_state.update{it.copy(message="PINPad aprovou. Aguardando confirmação do provedor; o pedido ainda não foi marcado como pago.")};else->_state.update{it.copy(message="Comando concluído no computador.")}};return@launch}
                "failed"->{_state.update{it.copy(error=result.error.ifBlank{"O computador não conseguiu concluir a operação."})};return@launch}
                "expired","cancelled"->{_state.update{it.copy(error="A solicitação expirou ou foi cancelada. Tente novamente.")};return@launch}
            }
        }
        _state.update{it.copy(error="O computador não respondeu dentro de 2 minutos. A operação foi encerrada sem bloquear o EventMenu GO.")}
    }

    fun clearFeedback(){_state.update{it.copy(error=null,message=null)}}
    override fun onCleared(){stopMonitoring();super.onCleared()}
    class Factory(private val repository:HubRepository):ViewModelProvider.Factory{@Suppress("UNCHECKED_CAST") override fun<T:ViewModel>create(modelClass:Class<T>):T=HubViewModel(repository) as T}
}
