package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryProgress
import br.com.eventmenu.go.data.DeliveryProgressRepository
import br.com.eventmenu.go.location.DeliveryLocationService
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeliveryProgressState(val items:Map<Int,DeliveryProgress> = emptyMap(),val loading:Boolean=false,val error:String?=null,val message:String?=null,val changeVersion:Int=0)
class DeliveryProgressViewModel(private val repository:DeliveryProgressRepository):ViewModel(){
 private val _state=MutableStateFlow(DeliveryProgressState());val state:StateFlow<DeliveryProgressState> = _state.asStateFlow()
 fun refresh()=viewModelScope.launch{_state.update{it.copy(loading=true,error=null)};runCatching{repository.listMine()}.onSuccess{rows->_state.update{it.copy(items=rows.associateBy{r->r.orderId},loading=false)};syncGps(rows)}.onFailure{_state.update{it.copy(loading=false,error="Não foi possível atualizar as entregas.")}}}
 fun pickup(orderId:Int)=runAction(orderId,"Pedido retirado."){repository.pickup(orderId)}
 fun startRoute(orderId:Int)=viewModelScope.launch{_state.update{it.copy(loading=true,error=null,message=null)};runCatching{repository.startRoute(orderId)}.onSuccess{p->_state.update{it.copy(items=it.items+(orderId to p),loading=false,message="Rota iniciada. GPS ativado para acompanhamento.",changeVersion=it.changeVersion+1)};DeliveryLocationService.start(EventMenuGoApplication.instance)}.onFailure{_state.update{it.copy(loading=false,error="Não foi possível iniciar a rota. Verifique a permissão de localização.")}}}
 fun arrive(orderId:Int)=runAction(orderId,"Chegada confirmada. Você já pode receber o pagamento, se necessário."){repository.arrive(orderId)}
 fun complete(orderId:Int)=viewModelScope.launch{_state.update{it.copy(loading=true,error=null,message=null)};runCatching{repository.complete(orderId)}.onSuccess{p->_state.update{it.copy(items=it.items+(orderId to p),loading=false,message="Entrega concluída.",changeVersion=it.changeVersion+1)};refresh()}.onFailure{_state.update{it.copy(loading=false,error="Não foi possível concluir a entrega.")}}}
 private fun runAction(orderId:Int,message:String,block:suspend()->DeliveryProgress)=viewModelScope.launch{_state.update{it.copy(loading=true,error=null,message=null)};runCatching{block()}.onSuccess{p->_state.update{it.copy(items=it.items+(orderId to p),loading=false,message=message,changeVersion=it.changeVersion+1)}}.onFailure{_state.update{it.copy(loading=false,error="Não foi possível atualizar a entrega. Tente novamente.")}}}
 private fun syncGps(rows:List<DeliveryProgress>){if(rows.any{it.routeStarted&&!it.completed&&it.orderStatus=="out_for_delivery"})DeliveryLocationService.start(EventMenuGoApplication.instance) else DeliveryLocationService.stop(EventMenuGoApplication.instance)}
 fun clearFeedback()=_state.update{it.copy(error=null,message=null)}
 class Factory(private val repository:DeliveryProgressRepository):ViewModelProvider.Factory{@Suppress("UNCHECKED_CAST") override fun<T:ViewModel>create(modelClass:Class<T>):T=DeliveryProgressViewModel(repository) as T}
}