package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.CashHandoff
import br.com.eventmenu.go.data.CreatedOrder
import br.com.eventmenu.go.data.DeliveryCashBalance
import br.com.eventmenu.go.data.DeliveryUser
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.KitchenTicket
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.PaymentBalance
import br.com.eventmenu.go.data.PixCharge
import br.com.eventmenu.go.data.Product
import br.com.eventmenu.go.data.QrResult
import br.com.eventmenu.go.data.RestaurantTable
import br.com.eventmenu.go.data.Session
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.data.WorkShift
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class AppScreen { HOME, POS, TABLES, ORDERS, KITCHEN, DISPATCH, CASH, DELIVERY, EVENTS, PROFILE }

data class GoState(
    val session: Session? = null,
    val modes: List<AppMode> = emptyList(),
    val mode: AppMode? = null,
    val workShift: WorkShift? = null,
    val screen: AppScreen = AppScreen.HOME,
    val orders: List<Order> = emptyList(),
    val kitchenTickets: List<KitchenTicket> = emptyList(),
    val deliveryUsers: List<DeliveryUser> = emptyList(),
    val tables: List<RestaurantTable> = emptyList(),
    val selectedTable: RestaurantTable? = null,
    val products: List<Product> = emptyList(),
    val cart: Map<Int,Int> = emptyMap(),
    val posOrder: CreatedOrder? = null,
    val paymentBalance: PaymentBalance? = null,
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
    val deliveryCash: DeliveryCashBalance? = null,
    val cashHandoff: CashHandoff? = null,
)

class MainViewModel(private val repo: EventMenuRepository) : ViewModel() {
    private val _state=MutableStateFlow(GoState(hasStoredSession=repo.sessionStore.token()!=null,pinConfigured=repo.sessionStore.hasPin(),biometricEnabled=repo.sessionStore.biometricEnabled));val state:StateFlow<GoState> = _state.asStateFlow()
    fun login(email:String,password:String,deviceLabel:String)=launchBusy{establish(repo.login(email,password,deviceLabel))}
    fun unlockWithPin(pin:String){if(!repo.sessionStore.verifyPin(pin)){_state.update{it.copy(error="PIN inválido.")};return};restoreSession()}
    fun restoreSession()=launchBusy{establish(repo.me())}
    private suspend fun establish(session:Session){
        val modes=repo.modes(session);val shiftMode=session.shift?.let{s->modes.firstOrNull{it.wire==s.mode}}
        _state.update{it.copy(session=session,modes=modes,mode=shiftMode?:if(modes.size==1)modes.first()else null,workShift=session.shift,screen=AppScreen.HOME,hasStoredSession=true,pinConfigured=repo.sessionStore.hasPin(),biometricEnabled=repo.sessionStore.biometricEnabled)}
        refreshOrdersInternal();refreshCashInternal();refreshDeliveryCashInternal()
        if("orders_create" in session.permissions)refreshCatalogInternal()
        if("orders_kitchen" in session.permissions&&session.shift?.mode=="operation")refreshKitchenInternal()
        if("tables" in session.permissions&&session.shift?.mode=="operation")refreshTablesInternal()
        if("delivery_assign" in session.permissions&&session.shift?.mode=="operation")refreshDeliveryUsersInternal()
    }
    fun chooseMode(mode:AppMode){if(mode!in _state.value.modes)return;val open=_state.value.workShift;if(open!=null&&open.mode!=mode.wire){_state.update{it.copy(error="Encerre o turno ${open.mode} antes de trocar de modo.")};return};_state.update{it.copy(mode=mode,screen=AppScreen.HOME,error=null)}}
    fun startShift(){val mode=_state.value.mode?:return;launchBusy{
        val shift=repo.openShift(mode);_state.update{it.copy(workShift=shift,message="Turno iniciado.")};refreshOrdersInternal();refreshDeliveryCashInternal()
        if("orders_kitchen" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshKitchenInternal()
        if("tables" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshTablesInternal()
        if("delivery_assign" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshDeliveryUsersInternal()
    }}
    fun closeShift()=launchBusy{repo.closeShift();val refreshed=repo.context();_state.update{it.copy(session=refreshed,workShift=refreshed.shift,deliveryCash=null,cashHandoff=null,kitchenTickets=emptyList(),deliveryUsers=emptyList(),tables=emptyList(),selectedTable=null,message="Turno encerrado.")}}
    fun navigate(screen:AppScreen)=_state.update{state->state.copy(screen=screen,selectedTable=if(screen==AppScreen.POS)null else state.selectedTable,error=null,message=null)}

    fun refreshOrders()=launchBusy{refreshOrdersInternal();refreshDeliveryCashInternal()}
    private suspend fun refreshOrdersInternal(){if(_state.value.workShift?.status=="open")_state.update{it.copy(orders=repo.orders())}}
    fun changeOrderStatus(orderId:Int,status:String)=launchBusy{repo.changeOrderStatus(orderId,status);refreshOrdersInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshKitchenInternal()};if("tables" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshTablesInternal()};_state.update{it.copy(message="Pedido #$orderId atualizado.")}}
    fun refreshKitchen()=launchBusy{refreshKitchenInternal()}
    private suspend fun refreshKitchenInternal(){if(_state.value.workShift?.mode=="operation"&&"orders_kitchen" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(kitchenTickets=repo.kitchenBoard())}}
    fun kitchenStatus(orderId:Int,status:String)=launchBusy{repo.changeOrderStatus(orderId,status);refreshKitchenInternal();refreshOrdersInternal()}

    fun refreshDispatch()=launchBusy{refreshOrdersInternal();if("delivery_assign" in (_state.value.session?.permissions?:emptySet()))refreshDeliveryUsersInternal()}
    private suspend fun refreshDeliveryUsersInternal(){if(_state.value.workShift?.mode=="operation"&&"delivery_assign" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(deliveryUsers=repo.deliveryUsers())}}
    fun dispatchReady(order:Order)=launchBusy{
        val target=when(order.channel){"table"->"served";"counter","pickup"->"completed";else->throw IllegalStateException("Este pedido precisa ser atribuído ao Delivery.")}
        repo.changeOrderStatus(order.id,target);refreshOrdersInternal();if("tables" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshTablesInternal()};_state.update{it.copy(message=if(target=="served")"Pedido #${order.id} servido na mesa."else"Pedido #${order.id} entregue ao cliente.")}
    }
    fun assignDelivery(orderId:Int,deliveryUserId:Int)=launchBusy{repo.assignDelivery(orderId,deliveryUserId);refreshOrdersInternal();refreshDeliveryUsersInternal();val name=_state.value.deliveryUsers.firstOrNull{it.id==deliveryUserId}?.name?:"entregador";_state.update{it.copy(message="Pedido #$orderId atribuído a $name.")}}

    fun refreshTables()=launchBusy{refreshTablesInternal()}
    private suspend fun refreshTablesInternal(){if(_state.value.workShift?.mode=="operation"&&"tables" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(tables=repo.tables())}}
    fun openTable(tableId:Int,label:String)=launchBusy{repo.openTable(tableId,label);refreshTablesInternal();_state.update{it.copy(message="Comanda aberta.")}}
    fun closeTable(tabId:Int)=launchBusy{repo.closeTab(tabId);refreshTablesInternal();refreshOrdersInternal();_state.update{it.copy(selectedTable=null,message="Comanda encerrada e mesa liberada.")}}
    fun orderForTable(table:RestaurantTable){if(table.tabId==null){_state.update{it.copy(error="Abra a comanda desta mesa antes de lançar pedido.")};return};_state.update{it.copy(selectedTable=table,screen=AppScreen.POS,posOrder=null,paymentBalance=null,cart=emptyMap(),error=null)}}
    fun clearSelectedTable()=_state.update{it.copy(selectedTable=null,screen=AppScreen.TABLES,cart=emptyMap(),posOrder=null,paymentBalance=null)}

    fun refreshCatalog()=launchBusy{refreshCatalogInternal()}
    private suspend fun refreshCatalogInternal(){_state.update{it.copy(products=repo.products())}}
    fun addProduct(productId:Int){val p=_state.value.products.firstOrNull{it.id==productId}?:return;val current=_state.value.cart[productId]?:0;if(p.trackStock&&current+1>p.stockQty.toInt()){_state.update{it.copy(error="Estoque disponível insuficiente para ${p.name}.")};return};_state.update{it.copy(cart=it.cart+(productId to current+1))}}
    fun removeProduct(productId:Int){val current=_state.value.cart[productId]?:return;_state.update{s->if(current<=1)s.copy(cart=s.cart-productId)else s.copy(cart=s.cart+(productId to current-1))}}
    fun clearCart()=_state.update{it.copy(cart=emptyMap())}
    fun createPosOrder(channel:String,customerName:String,phone:String,address:String,notes:String)=launchBusy{
        val table=_state.value.selectedTable;val effectiveChannel=if(table!=null)"table"else channel
        val order=repo.createOrder(effectiveChannel,_state.value.cart,customerName,phone,address,notes,table?.id)
        if(effectiveChannel=="table"){
            _state.update{it.copy(cart=emptyMap(),posOrder=null,paymentBalance=null,selectedTable=null,screen=AppScreen.TABLES,message="Pedido #${order.id} lançado na ${table?.name?:"mesa"}.")}
            refreshOrdersInternal();refreshTablesInternal();refreshCatalogInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))refreshKitchenInternal()
        }else{
            val balance=repo.paymentBalance(order.id);_state.update{it.copy(cart=emptyMap(),posOrder=order,paymentBalance=balance,message="Pedido #${order.id} criado. Escolha o pagamento.")};refreshOrdersInternal();refreshCatalogInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))refreshKitchenInternal()
        }
    }
    fun newPosSale()=_state.update{it.copy(posOrder=null,paymentBalance=null,pixCharge=null,tapOnRequest=null,cart=emptyMap(),selectedTable=null)}
    fun refreshPosPayment()=launchBusy{val id=_state.value.posOrder?.id?:return@launchBusy;_state.update{it.copy(paymentBalance=repo.paymentBalance(id))}}
    fun payPosCash(amountCents:Int)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");val balance=repo.payCashPart(id,amountCents);_state.update{it.copy(paymentBalance=balance,message="Parcela em dinheiro recebida: ${money(amountCents)}")};refreshOrdersInternal()}
    fun requestPosPix(amountCents:Int,taxId:String)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");_state.update{it.copy(pixCharge=repo.nativePix(id,taxId,amountCents))}}
    fun requestPosNfc(amountCents:Int)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");_state.update{it.copy(tapOnRequest=repo.nfcIntent(id,amountCents))}}

    fun resolveQr(value:String)=launchBusy{_state.update{it.copy(qr=repo.resolveQr(value))}}
    fun clearQr()=_state.update{it.copy(qr=null)}
    fun processCurrentQr(){val qr=_state.value.qr?:return;launchBusy{when(qr.type){"ticket"->repo.ticketCheckIn(qr.raw);"guest"->repo.guestCheckIn(qr.raw);"delivery_handoff"->{repo.confirmDeliveryHandoff(qr.raw);refreshCashInternal()};else->throw IllegalStateException("Este QR não possui ação disponível para sua função.")};_state.update{it.copy(message=if(qr.type=="delivery_handoff")"✅ Repasse recebido e lançado no caixa."else"Entrada validada com sucesso.",qr=null)}}}

    fun openCash(openingCents:Int)=launchBusy{repo.openCash(openingCents);refreshCashInternal();_state.update{it.copy(message="Caixa iniciado.")}}
    fun closeCash(countedCents:Int)=launchBusy{repo.closeCash(countedCents);refreshCashInternal();_state.update{it.copy(message="Caixa encerrado.")}}
    private suspend fun refreshCashInternal(){if(_state.value.session?.permissions?.contains("cash")!=true)return;_state.update{it.copy(cashOpen=repo.currentCash().optJSONObject("session")!=null)}}

    fun requestPix(orderId:Int,taxId:String)=launchBusy{_state.update{it.copy(pixCharge=repo.nativePix(orderId,taxId))}}
    fun dismissPix()=_state.update{it.copy(pixCharge=null)}
    fun pollPixStatus(){val charge=_state.value.pixCharge?:return;viewModelScope.launch{if(_state.value.posOrder?.id==charge.orderId&&"payments" in (_state.value.session?.permissions?:emptySet())){runCatching{repo.paymentBalance(charge.orderId)}.onSuccess{balance->val part=balance.payments.firstOrNull{it.id==charge.paymentId};_state.update{it.copy(paymentBalance=balance)};if(part?.status=="paid")_state.update{it.copy(pixCharge=null,message="✅ PIX RECEBIDO — ${money(charge.amountCents)} · Restante ${money(balance.remainingCents)}")}}}else runCatching{repo.orders()}.onSuccess{orders->val order=orders.firstOrNull{it.id==charge.orderId};_state.update{it.copy(orders=orders)};if(order?.paymentStatus=="paid")_state.update{it.copy(pixCharge=null,message="✅ PIX RECEBIDO — ${money(charge.amountCents)}")}}}}
    fun requestNfc(orderId:Int)=launchBusy{_state.update{it.copy(tapOnRequest=repo.nfcIntent(orderId))}}
    fun tapOnLaunchConsumed()=_state.update{it.copy(tapOnRequest=null)}
    fun verifyTapOn(request:TapOnRequest,transactionCode:String)=launchBusy{repo.nfcVerify(request.intentToken,transactionCode);refreshOrdersInternal();if(_state.value.posOrder?.id==request.orderId&&"payments" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(paymentBalance=repo.paymentBalance(request.orderId))};_state.update{it.copy(tapOnRequest=null,message="Cartão aprovado e confirmado pelo servidor.")}}
    fun tapOnCancelled()=_state.update{it.copy(tapOnRequest=null,message="Pagamento NFC cancelado.")}

    fun collectDeliveryCash(orderId:Int,receivedCents:Int)=launchBusy{val receipt=repo.collectDeliveryCash(orderId,receivedCents);refreshOrdersInternal();refreshDeliveryCashInternal();_state.update{it.copy(message="✅ Dinheiro recebido. Troco: ${money(receipt.changeCents)}")}}
    fun refreshDeliveryCash()=launchBusy{refreshDeliveryCashInternal()}
    private suspend fun refreshDeliveryCashInternal(){if(_state.value.workShift?.mode!="delivery"){_state.update{it.copy(deliveryCash=null)};return};runCatching{repo.deliveryCashOutstanding()}.onSuccess{balance->_state.update{it.copy(deliveryCash=balance)}}}
    fun createCashHandoff()=launchBusy{_state.update{it.copy(cashHandoff=repo.createDeliveryHandoff())}}
    fun dismissCashHandoff()=_state.update{it.copy(cashHandoff=null)}
    fun savePin(pin:String){runCatching{repo.sessionStore.setPin(pin)}.onSuccess{_state.update{it.copy(pinConfigured=true,message="PIN salvo neste aparelho.")}}.onFailure{_state.update{it.copy(error="Use um PIN numérico de 4 a 8 dígitos.")}}}
    fun setBiometric(enabled:Boolean){repo.sessionStore.biometricEnabled=enabled;_state.update{it.copy(biometricEnabled=enabled,message=if(enabled)"Biometria ativada."else"Biometria desativada.")}}
    fun logout()=viewModelScope.launch{_state.update{it.copy(loading=true)};runCatching{repo.logout()};_state.value=GoState()}
    fun clearFeedback()=_state.update{it.copy(error=null,message=null)}
    private fun launchBusy(block:suspend()->Unit)=viewModelScope.launch{_state.update{it.copy(loading=true,error=null)};runCatching{block()}.onFailure{e->if(e is ApiException&&e.status==401){repo.sessionStore.clear();_state.value=GoState(error="Sessão expirada. Entre novamente.")}else _state.update{it.copy(error=e.message?:"Falha inesperada.")}};_state.update{it.copy(loading=false)}}
    private fun money(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
    class Factory(private val repo:EventMenuRepository):ViewModelProvider.Factory{@Suppress("UNCHECKED_CAST")override fun<T:ViewModel>create(modelClass:Class<T>):T=MainViewModel(repo) as T}
}
