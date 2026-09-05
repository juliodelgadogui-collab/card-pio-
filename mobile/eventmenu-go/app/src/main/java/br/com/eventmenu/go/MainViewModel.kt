package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.PixCharge
import br.com.eventmenu.go.data.QrResult
import br.com.eventmenu.go.data.Session
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.data.WorkShift
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class AppScreen { HOME, ORDERS, CASH, DELIVERY, EVENTS, PROFILE }

data class GoState(
    val session: Session? = null,
    val modes: List<AppMode> = emptyList(),
    val mode: AppMode? = null,
    val workShift: WorkShift? = null,
    val screen: AppScreen = AppScreen.HOME,
    val orders: List<Order> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
    val qr: QrResult? = null,
    val cashOpen: Boolean = false,
    val message: String? = null,
    val hasStoredSession: Boolean = false,
    val pinConfigured: Boolean = false,
    val biometricEnabled: Boolean = false,
    val tapOnRequest: TapOnRequest? = null,
    val pixCharge: PixCharge? = null,
)

class MainViewModel(private val repo: EventMenuRepository) : ViewModel() {
    private val _state = MutableStateFlow(GoState(hasStoredSession=repo.sessionStore.token()!=null,pinConfigured=repo.sessionStore.hasPin(),biometricEnabled=repo.sessionStore.biometricEnabled))
    val state:StateFlow<GoState> = _state.asStateFlow()

    fun login(email:String,password:String,deviceLabel:String)=launchBusy{establish(repo.login(email,password,deviceLabel))}
    fun unlockWithPin(pin:String){if(!repo.sessionStore.verifyPin(pin)){_state.update{it.copy(error="PIN inválido.")};return};restoreSession()}
    fun restoreSession()=launchBusy{establish(repo.me())}

    private suspend fun establish(session:Session){
        val modes=repo.modes(session);val shiftMode=session.shift?.let{s->modes.firstOrNull{it.wire==s.mode}}
        _state.update{it.copy(session=session,modes=modes,mode=shiftMode?:if(modes.size==1)modes.first() else null,workShift=session.shift,screen=AppScreen.HOME,hasStoredSession=true,pinConfigured=repo.sessionStore.hasPin(),biometricEnabled=repo.sessionStore.biometricEnabled)}
        refreshOrdersInternal();refreshCashInternal()
    }

    fun chooseMode(mode:AppMode){if(mode!in _state.value.modes)return;val open=_state.value.workShift;if(open!=null&&open.mode!=mode.wire){_state.update{it.copy(error="Encerre o turno ${open.mode} antes de trocar de modo.")};return};_state.update{it.copy(mode=mode,screen=AppScreen.HOME,error=null)}}
    fun startShift(){val mode=_state.value.mode?:return;launchBusy{_state.update{it.copy(workShift=repo.openShift(mode),message="Turno iniciado.")}}}
    fun closeShift()=launchBusy{repo.closeShift();val refreshed=repo.context();_state.update{it.copy(session=refreshed,workShift=refreshed.shift,message="Turno encerrado.")}}

    fun navigate(screen:AppScreen)=_state.update{it.copy(screen=screen,error=null,message=null)}
    fun refreshOrders()=launchBusy{refreshOrdersInternal()}
    private suspend fun refreshOrdersInternal(){_state.update{it.copy(orders=repo.orders())}}
    fun changeOrderStatus(orderId:Int,status:String)=launchBusy{repo.changeOrderStatus(orderId,status);refreshOrdersInternal();_state.update{it.copy(message="Pedido #$orderId atualizado.")}}

    fun resolveQr(value:String)=launchBusy{_state.update{it.copy(qr=repo.resolveQr(value))}}
    fun clearQr()=_state.update{it.copy(qr=null)}
    fun checkInCurrentQr(){val qr=_state.value.qr?:return;launchBusy{when(qr.type){"ticket"->repo.ticketCheckIn(qr.raw);"guest"->repo.guestCheckIn(qr.raw);else->throw IllegalStateException("Este QR não possui ação de check-in.")};_state.update{it.copy(message="Entrada validada com sucesso.",qr=null)}}}

    fun openCash(openingCents:Int)=launchBusy{repo.openCash(openingCents);refreshCashInternal();_state.update{it.copy(message="Caixa iniciado.")}}
    fun closeCash(countedCents:Int)=launchBusy{repo.closeCash(countedCents);refreshCashInternal();_state.update{it.copy(message="Caixa encerrado.")}}
    private suspend fun refreshCashInternal(){if(_state.value.session?.permissions?.contains("cash")!=true)return;_state.update{it.copy(cashOpen=repo.currentCash().optJSONObject("session")!=null)}}

    fun requestPix(orderId:Int,taxId:String)=launchBusy{_state.update{it.copy(pixCharge=repo.nativePix(orderId,taxId))}}
    fun dismissPix()=_state.update{it.copy(pixCharge=null)}
    fun pollPixStatus(){
        val charge=_state.value.pixCharge?:return
        viewModelScope.launch{
            runCatching{repo.orders()}.onSuccess{orders->
                val order=orders.firstOrNull{it.id==charge.orderId};_state.update{it.copy(orders=orders)}
                if(order?.paymentStatus=="paid")_state.update{it.copy(pixCharge=null,message="✅ PIX RECEBIDO — ${money(charge.amountCents)}")}
            }
        }
    }

    fun requestNfc(orderId:Int)=launchBusy{_state.update{it.copy(tapOnRequest=repo.nfcIntent(orderId))}}
    fun tapOnLaunchConsumed()=_state.update{it.copy(tapOnRequest=null)}
    fun verifyTapOn(request:TapOnRequest,transactionCode:String)=launchBusy{repo.nfcVerify(request.intentToken,transactionCode);refreshOrdersInternal();_state.update{it.copy(tapOnRequest=null,message="Cartão aprovado e confirmado pelo servidor.")}}
    fun tapOnCancelled()=_state.update{it.copy(tapOnRequest=null,message="Pagamento NFC cancelado.")}

    fun savePin(pin:String){runCatching{repo.sessionStore.setPin(pin)}.onSuccess{_state.update{it.copy(pinConfigured=true,message="PIN salvo neste aparelho.")}}.onFailure{_state.update{it.copy(error="Use um PIN numérico de 4 a 8 dígitos.")}}}
    fun setBiometric(enabled:Boolean){repo.sessionStore.biometricEnabled=enabled;_state.update{it.copy(biometricEnabled=enabled,message=if(enabled)"Biometria ativada." else "Biometria desativada.")}}
    fun logout()=viewModelScope.launch{_state.update{it.copy(loading=true)};runCatching{repo.logout()};_state.value=GoState()}
    fun clearFeedback()=_state.update{it.copy(error=null,message=null)}

    private fun launchBusy(block:suspend()->Unit)=viewModelScope.launch{
        _state.update{it.copy(loading=true,error=null)}
        runCatching{block()}.onFailure{e->if(e is ApiException&&e.status==401){repo.sessionStore.clear();_state.value=GoState(error="Sessão expirada. Entre novamente.")}else _state.update{it.copy(error=e.message?:"Falha inesperada.")}}
        _state.update{it.copy(loading=false)}
    }
    private fun money(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
    class Factory(private val repo:EventMenuRepository):ViewModelProvider.Factory{@Suppress("UNCHECKED_CAST")override fun<T:ViewModel>create(modelClass:Class<T>):T=MainViewModel(repo) as T}
}
