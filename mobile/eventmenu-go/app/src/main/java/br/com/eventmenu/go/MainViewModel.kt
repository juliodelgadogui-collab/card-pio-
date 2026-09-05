package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.AppNotification
import br.com.eventmenu.go.data.ApiException
import br.com.eventmenu.go.data.CashHandoff
import br.com.eventmenu.go.data.CashSummary
import br.com.eventmenu.go.data.CreatedOrder
import br.com.eventmenu.go.data.DeliveryCashBalance
import br.com.eventmenu.go.data.DeliveryUser
import br.com.eventmenu.go.data.EventEntry
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.EventOperationsRepository
import br.com.eventmenu.go.data.EventOverview
import br.com.eventmenu.go.data.KitchenTicket
import br.com.eventmenu.go.data.ManagerOperationsRepository
import br.com.eventmenu.go.data.ManagerOverview
import br.com.eventmenu.go.data.NotificationRepository
import br.com.eventmenu.go.data.Order
import br.com.eventmenu.go.data.PaymentBalance
import br.com.eventmenu.go.data.PixCharge
import br.com.eventmenu.go.data.Product
import br.com.eventmenu.go.data.QrResult
import br.com.eventmenu.go.data.RestaurantTable
import br.com.eventmenu.go.data.Session
import br.com.eventmenu.go.data.TableAccount
import br.com.eventmenu.go.data.TableAccountOrder
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.data.WorkShift
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class AppScreen { HOME, NOTIFICATIONS, MANAGER, POS, TABLES, TABLE_ACCOUNT, ORDERS, KITCHEN, DISPATCH, CASH, DELIVERY, EVENTS, PROFILE }

data class GoState(
    val session: Session? = null,
    val modes: List<AppMode> = emptyList(),
    val mode: AppMode? = null,
    val workShift: WorkShift? = null,
    val screen: AppScreen = AppScreen.HOME,
    val notifications: List<AppNotification> = emptyList(),
    val unreadNotifications: Int = 0,
    val managerOverview: ManagerOverview? = null,
    val orders: List<Order> = emptyList(),
    val kitchenTickets: List<KitchenTicket> = emptyList(),
    val deliveryUsers: List<DeliveryUser> = emptyList(),
    val dispatchFocusOrderId: Int? = null,
    val tables: List<RestaurantTable> = emptyList(),
    val selectedTable: RestaurantTable? = null,
    val tableAccount: TableAccount? = null,
    val events: List<EventOverview> = emptyList(),
    val selectedEventId: Int? = null,
    val eventEntries: List<EventEntry> = emptyList(),
    val products: List<Product> = emptyList(),
    val cart: Map<Int,Int> = emptyMap(),
    val posOrder: CreatedOrder? = null,
    val paymentBalance: PaymentBalance? = null,
    val posReturnScreen: AppScreen? = null,
    val cashOpen: Boolean = false,
    val cashSummary: CashSummary? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val qr: QrResult? = null,
    val message: String? = null,
    val hasStoredSession: Boolean = false,
    val pinConfigured: Boolean = false,
    val biometricEnabled: Boolean = false,
    val tapOnRequest: TapOnRequest? = null,
    val pixCharge: PixCharge? = null,
    val deliveryCash: DeliveryCashBalance? = null,
    val cashHandoff: CashHandoff? = null,
)

class MainViewModel(
    private val repo: EventMenuRepository,
    private val eventRepo: EventOperationsRepository,
    private val managerRepo: ManagerOperationsRepository,
    private val notificationRepo: NotificationRepository,
) : ViewModel() {
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
        if(session.shift?.mode=="events")refreshEventsInternal()
        if("reports" in session.permissions&&session.shift?.mode?.let{it in setOf("operation","pay")}==true)refreshManagerInternal()
        if(session.shift?.status=="open")refreshNotificationsInternal()
    }
    fun chooseMode(mode:AppMode){if(mode!in _state.value.modes)return;val open=_state.value.workShift;if(open!=null&&open.mode!=mode.wire){_state.update{it.copy(error="Encerre o turno ${open.mode} antes de trocar de modo.")};return};_state.update{it.copy(mode=mode,screen=AppScreen.HOME,error=null)}}
    fun startShift(){val mode=_state.value.mode?:return;launchBusy{
        val shift=repo.openShift(mode);_state.update{it.copy(workShift=shift,message="Turno iniciado.")};refreshOrdersInternal();refreshDeliveryCashInternal();refreshCashInternal()
        if("orders_kitchen" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshKitchenInternal()
        if("tables" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshTablesInternal()
        if("delivery_assign" in (_state.value.session?.permissions?:emptySet())&&mode==AppMode.OPERATION)refreshDeliveryUsersInternal()
        if(mode==AppMode.EVENTS)refreshEventsInternal()
        if("reports" in (_state.value.session?.permissions?:emptySet())&&mode in setOf(AppMode.OPERATION,AppMode.PAY))refreshManagerInternal()
        refreshNotificationsInternal()
    }}
    fun closeShift()=launchBusy{repo.closeShift();val refreshed=repo.context();_state.update{it.copy(session=refreshed,workShift=refreshed.shift,notifications=emptyList(),unreadNotifications=0,managerOverview=null,deliveryCash=null,cashHandoff=null,kitchenTickets=emptyList(),deliveryUsers=emptyList(),dispatchFocusOrderId=null,tables=emptyList(),selectedTable=null,tableAccount=null,events=emptyList(),selectedEventId=null,eventEntries=emptyList(),posOrder=null,paymentBalance=null,posReturnScreen=null,message="Turno encerrado.")}}
    fun navigate(screen:AppScreen){_state.update{state->state.copy(screen=screen,selectedTable=if(screen==AppScreen.POS)null else state.selectedTable,dispatchFocusOrderId=if(screen==AppScreen.DISPATCH)state.dispatchFocusOrderId else null,error=null,message=null)};if(screen==AppScreen.NOTIFICATIONS)refreshNotifications()}

    fun refreshNotifications()=launchBusy{refreshNotificationsInternal()}
    private suspend fun refreshNotificationsInternal(){if(_state.value.workShift?.status!="open"){_state.update{it.copy(notifications=emptyList(),unreadNotifications=0)};return};runCatching{notificationRepo.inbox()}.onSuccess{inbox->_state.update{it.copy(notifications=inbox.items,unreadNotifications=inbox.unreadCount)}}}
    fun markNotificationRead(id:Int)=launchBusy{notificationRepo.markRead(id);refreshNotificationsInternal()}
    fun markAllNotificationsRead()=launchBusy{notificationRepo.markAllRead();refreshNotificationsInternal()}

    fun refreshManager()=launchBusy{refreshManagerInternal()}
    private suspend fun refreshManagerInternal(){
        val p=_state.value.session?.permissions?:emptySet();val mode=_state.value.workShift?.mode
        if("reports" !in p||mode !in setOf("operation","pay")){_state.update{it.copy(managerOverview=null)};return}
        _state.update{it.copy(managerOverview=managerRepo.overview())}
    }

    fun refreshOrders()=launchBusy{refreshOrdersInternal();refreshDeliveryCashInternal();refreshNotificationsInternal()}
    private suspend fun refreshOrdersInternal(){if(_state.value.workShift?.status=="open"&&_state.value.workShift?.mode!="events")runCatching{repo.orders()}.onSuccess{orders->_state.update{it.copy(orders=orders)}}}
    fun changeOrderStatus(orderId:Int,status:String)=launchBusy{repo.changeOrderStatus(orderId,status);refreshOrdersInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshKitchenInternal()};if("tables" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshTablesInternal()};if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();_state.update{it.copy(message="Pedido #$orderId atualizado.")}}
    fun refreshKitchen()=launchBusy{refreshKitchenInternal();refreshNotificationsInternal()}
    private suspend fun refreshKitchenInternal(){if(_state.value.workShift?.mode=="operation"&&"orders_kitchen" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(kitchenTickets=repo.kitchenBoard())}}
    fun kitchenStatus(orderId:Int,status:String)=launchBusy{repo.changeOrderStatus(orderId,status);refreshKitchenInternal();refreshOrdersInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal()}

    fun refreshDispatch()=launchBusy{refreshOrdersInternal();if("delivery_assign" in (_state.value.session?.permissions?:emptySet()))refreshDeliveryUsersInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal()}
    private suspend fun refreshDeliveryUsersInternal(){if(_state.value.workShift?.mode=="operation"&&"delivery_assign" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(deliveryUsers=repo.deliveryUsers())}}
    fun dispatchReady(order:Order)=launchBusy{val target=when(order.channel){"table"->"served";"counter","pickup"->"completed";else->throw IllegalStateException("Este pedido precisa ser atribuído ao Delivery.")};repo.changeOrderStatus(order.id,target);refreshOrdersInternal();if("tables" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshTablesInternal()};if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();_state.update{it.copy(dispatchFocusOrderId=null,message=if(target=="served")"Pedido #${order.id} servido na mesa."else"Pedido #${order.id} entregue ao cliente.")}}
    fun assignDelivery(orderId:Int,deliveryUserId:Int)=launchBusy{repo.assignDelivery(orderId,deliveryUserId);refreshOrdersInternal();refreshDeliveryUsersInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();val name=_state.value.deliveryUsers.firstOrNull{it.id==deliveryUserId}?.name?:"entregador";_state.update{it.copy(dispatchFocusOrderId=null,message="Pedido #$orderId atribuído a $name.")}}

    fun refreshTables()=launchBusy{refreshTablesInternal();refreshNotificationsInternal()}
    private suspend fun refreshTablesInternal(){if(_state.value.workShift?.mode=="operation"&&"tables" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(tables=repo.tables())}}
    fun openTable(tableId:Int,label:String)=launchBusy{repo.openTable(tableId,label);refreshTablesInternal();_state.update{it.copy(message="Comanda aberta.")}}
    fun closeTable(tabId:Int)=launchBusy{repo.closeTab(tabId);refreshTablesInternal();refreshOrdersInternal();_state.update{it.copy(selectedTable=null,tableAccount=null,screen=AppScreen.TABLES,message="Comanda encerrada e mesa liberada.")}}
    fun orderForTable(table:RestaurantTable){if(table.tabId==null){_state.update{it.copy(error="Abra a comanda desta mesa antes de lançar pedido.")};return};_state.update{it.copy(selectedTable=table,tableAccount=null,screen=AppScreen.POS,posOrder=null,paymentBalance=null,posReturnScreen=null,cart=emptyMap(),error=null)}}
    fun clearSelectedTable()=_state.update{it.copy(selectedTable=null,screen=AppScreen.TABLES,cart=emptyMap(),posOrder=null,paymentBalance=null,posReturnScreen=null)}
    fun openTableAccount(table:RestaurantTable)=launchBusy{if(table.tabId==null)throw IllegalStateException("Abra a comanda antes de consultar a conta.");refreshOrdersInternal();val account=repo.tableAccount(table,_state.value.orders);_state.update{it.copy(tableAccount=account,selectedTable=table,screen=AppScreen.TABLE_ACCOUNT,error=null)}}
    fun refreshTableAccount()=launchBusy{refreshTableAccountInternal()}
    private suspend fun refreshTableAccountInternal(){val table=_state.value.tableAccount?.table?:_state.value.selectedTable?:return;refreshOrdersInternal();_state.update{it.copy(tableAccount=repo.tableAccount(table,_state.value.orders))}}
    fun closeTableAccount()=_state.update{it.copy(tableAccount=null,selectedTable=null,screen=AppScreen.TABLES,posReturnScreen=null)}
    fun receiveTableOrder(order:TableAccountOrder)=launchBusy{val balance=repo.paymentBalance(order.orderId);_state.update{it.copy(posOrder=CreatedOrder(order.orderId,"","table",balance.totalCents),paymentBalance=balance,posReturnScreen=AppScreen.TABLE_ACCOUNT,screen=AppScreen.POS,message="Recebimento do pedido #${order.orderId}.")}}

    fun refreshEvents()=launchBusy{refreshEventsInternal();refreshNotificationsInternal()}
    private suspend fun refreshEventsInternal(){
        if(_state.value.workShift?.mode!="events")return
        val events=eventRepo.overview();val currentId=_state.value.selectedEventId;val selected=events.firstOrNull{it.id==currentId}?:events.firstOrNull{it.status=="published"}?:events.firstOrNull()
        _state.update{it.copy(events=events,selectedEventId=selected?.id)}
        if(selected!=null)refreshEventEntriesInternal(selected.id)else _state.update{it.copy(eventEntries=emptyList())}
    }
    fun selectEvent(eventId:Int)=launchBusy{_state.update{it.copy(selectedEventId=eventId)};refreshEventEntriesInternal(eventId)}
    private suspend fun refreshEventEntriesInternal(eventId:Int){
        val p=_state.value.session?.permissions?:emptySet()
        if("tickets" !in p&&"guests" !in p&&"events" !in p){_state.update{it.copy(eventEntries=emptyList())};return}
        runCatching{eventRepo.recent(eventId)}.onSuccess{entries->_state.update{it.copy(eventEntries=entries)}}
    }

    fun refreshCatalog()=launchBusy{refreshCatalogInternal()}
    private suspend fun refreshCatalogInternal(){_state.update{it.copy(products=repo.products())}}
    fun addProduct(productId:Int){val p=_state.value.products.firstOrNull{it.id==productId}?:return;val current=_state.value.cart[productId]?:0;if(p.trackStock&&current+1>p.stockQty.toInt()){_state.update{it.copy(error="Estoque disponível insuficiente para ${p.name}.")};return};_state.update{it.copy(cart=it.cart+(productId to current+1))}}
    fun removeProduct(productId:Int){val current=_state.value.cart[productId]?:return;_state.update{s->if(current<=1)s.copy(cart=s.cart-productId)else s.copy(cart=s.cart+(productId to current-1))}}
    fun clearCart()=_state.update{it.copy(cart=emptyMap())}
    fun createPosOrder(channel:String,customerName:String,phone:String,address:String,notes:String)=launchBusy{val table=_state.value.selectedTable;val effectiveChannel=if(table!=null)"table"else channel;val order=repo.createOrder(effectiveChannel,_state.value.cart,customerName,phone,address,notes,table?.id);if(effectiveChannel=="table"){_state.update{it.copy(cart=emptyMap(),posOrder=null,paymentBalance=null,selectedTable=null,screen=AppScreen.TABLES,message="Pedido #${order.id} lançado na ${table?.name?:"mesa"}.")};refreshOrdersInternal();refreshTablesInternal();refreshCatalogInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))refreshKitchenInternal()}else{val balance=repo.paymentBalance(order.id);_state.update{it.copy(cart=emptyMap(),posOrder=order,paymentBalance=balance,posReturnScreen=null,message="Pedido #${order.id} criado. Escolha o pagamento.")};refreshOrdersInternal();refreshCatalogInternal();if("orders_kitchen" in (_state.value.session?.permissions?:emptySet()))refreshKitchenInternal()};if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal()}
    fun finishPosFlow()=launchBusy{val back=_state.value.posReturnScreen;_state.update{it.copy(posOrder=null,paymentBalance=null,pixCharge=null,tapOnRequest=null,cart=emptyMap(),selectedTable=if(back==AppScreen.TABLE_ACCOUNT)it.selectedTable else null,posReturnScreen=null,screen=back?:AppScreen.POS)};if(back==AppScreen.TABLE_ACCOUNT){refreshTablesInternal();refreshTableAccountInternal()}else refreshCatalogInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal()}
    fun refreshPosPayment()=launchBusy{val id=_state.value.posOrder?.id?:return@launchBusy;_state.update{it.copy(paymentBalance=repo.paymentBalance(id))};refreshNotificationsInternal()}
    fun payPosCash(amountCents:Int)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");val balance=repo.payCashPart(id,amountCents);_state.update{it.copy(paymentBalance=balance,message="Parcela em dinheiro recebida: ${money(amountCents)}")};refreshOrdersInternal();refreshCashInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal()}
    fun requestPosPix(amountCents:Int,taxId:String)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");_state.update{it.copy(pixCharge=repo.nativePix(id,taxId,amountCents))}}
    fun requestPosNfc(amountCents:Int)=launchBusy{val id=_state.value.posOrder?.id?:throw IllegalStateException("Pedido do PDV não encontrado.");_state.update{it.copy(tapOnRequest=repo.nfcIntent(id,amountCents))}}

    fun resolveQr(value:String)=launchBusy{_state.update{it.copy(qr=repo.resolveQr(value))}}
    fun clearQr()=_state.update{it.copy(qr=null)}
    fun processCurrentQr(){val qr=_state.value.qr?:return;launchBusy{when(qr.type){
        "ticket"->{repo.ticketCheckIn(qr.raw);if(_state.value.workShift?.mode=="events")refreshEventsInternal()}
        "guest"->{repo.guestCheckIn(qr.raw);if(_state.value.workShift?.mode=="events")refreshEventsInternal()}
        "delivery_handoff"->{repo.confirmDeliveryHandoff(qr.raw);refreshCashInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()}}
        "order"->{val id=qr.orderId?:throw IllegalStateException("Pedido lido sem identificador.");if(qr.status!="ready"){_state.update{it.copy(qr=null,message="Pedido #$id está em ${qr.status}. Nenhuma ação de despacho foi executada.")};return@launchBusy};refreshOrdersInternal();if("delivery_assign" in (_state.value.session?.permissions?:emptySet()))refreshDeliveryUsersInternal();_state.update{it.copy(qr=null,screen=AppScreen.DISPATCH,dispatchFocusOrderId=id,message="Pedido #$id localizado pelo QR.")};return@launchBusy}
        else->throw IllegalStateException("Este QR não possui ação disponível para sua função.")
    };refreshNotificationsInternal();_state.update{it.copy(message=if(qr.type=="delivery_handoff")"✅ Repasse recebido e lançado no caixa."else"Entrada validada com sucesso.",qr=null)}}}

    fun refreshCash()=launchBusy{refreshCashInternal();refreshNotificationsInternal()}
    fun openCash(openingCents:Int,notes:String="")=launchBusy{repo.openCash(openingCents,notes);refreshCashInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();_state.update{it.copy(message="Caixa financeiro aberto.")}}
    fun addCashSupply(amountCents:Int,notes:String="")=launchBusy{repo.cashMovement("supply",amountCents,notes);refreshCashInternal();_state.update{it.copy(message="Suprimento lançado: ${money(amountCents)}")}}
    fun addCashWithdrawal(amountCents:Int,notes:String)=launchBusy{repo.cashMovement("withdrawal",amountCents,notes,"out");refreshCashInternal();_state.update{it.copy(message="Sangria lançada: ${money(amountCents)}")}}
    fun closeCash(countedCents:Int,notes:String="")=launchBusy{val result=repo.closeCash(countedCents,notes).getJSONObject("session");val expected=result.optInt("expected_cash_cents");val diff=result.optInt("difference_cents");refreshCashInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();_state.update{it.copy(message="Caixa fechado. Esperado ${money(expected)} · Diferença ${money(diff)}")}}
    private suspend fun refreshCashInternal(){if(_state.value.session?.permissions?.contains("cash")!=true)return;val current=repo.currentCash().optJSONObject("session");val summary=runCatching{repo.cashSummary()}.getOrNull();_state.update{it.copy(cashOpen=current!=null,cashSummary=summary)}}

    fun requestPix(orderId:Int,taxId:String)=launchBusy{_state.update{it.copy(pixCharge=repo.nativePix(orderId,taxId))}}
    fun dismissPix()=_state.update{it.copy(pixCharge=null)}
    fun pollPixStatus(){val charge=_state.value.pixCharge?:return;viewModelScope.launch{if(_state.value.posOrder?.id==charge.orderId&&"payments" in (_state.value.session?.permissions?:emptySet())){runCatching{repo.paymentBalance(charge.orderId)}.onSuccess{balance->val part=balance.payments.firstOrNull{it.id==charge.paymentId};_state.update{it.copy(paymentBalance=balance)};if(part?.status=="paid")_state.update{it.copy(pixCharge=null,message="✅ PIX RECEBIDO — ${money(charge.amountCents)} · Restante ${money(balance.remainingCents)}")}}}else runCatching{repo.orders()}.onSuccess{orders->val order=orders.firstOrNull{it.id==charge.orderId};_state.update{it.copy(orders=orders)};if(order?.paymentStatus=="paid")_state.update{it.copy(pixCharge=null,message="✅ PIX RECEBIDO — ${money(charge.amountCents)}")}};runCatching{refreshNotificationsInternal()}}}
    fun requestNfc(orderId:Int)=launchBusy{_state.update{it.copy(tapOnRequest=repo.nfcIntent(orderId))}}
    fun tapOnLaunchConsumed()=_state.update{it.copy(tapOnRequest=null)}
    fun verifyTapOn(request:TapOnRequest,transactionCode:String)=launchBusy{repo.nfcVerify(request.intentToken,transactionCode);refreshOrdersInternal();if(_state.value.posOrder?.id==request.orderId&&"payments" in (_state.value.session?.permissions?:emptySet()))_state.update{it.copy(paymentBalance=repo.paymentBalance(request.orderId))};refreshCashInternal();if("reports" in (_state.value.session?.permissions?:emptySet()))runCatching{refreshManagerInternal()};refreshNotificationsInternal();_state.update{it.copy(tapOnRequest=null,message="Cartão aprovado e confirmado pelo servidor.")}}
    fun tapOnCancelled()=_state.update{it.copy(tapOnRequest=null,message="Pagamento NFC cancelado.")}

    fun collectDeliveryCash(orderId:Int,receivedCents:Int)=launchBusy{val receipt=repo.collectDeliveryCash(orderId,receivedCents);refreshOrdersInternal();refreshDeliveryCashInternal();refreshNotificationsInternal();_state.update{it.copy(message="✅ Dinheiro recebido. Troco: ${money(receipt.changeCents)}")}}
    fun refreshDeliveryCash()=launchBusy{refreshDeliveryCashInternal();refreshNotificationsInternal()}
    private suspend fun refreshDeliveryCashInternal(){if(_state.value.workShift?.mode!="delivery"){_state.update{it.copy(deliveryCash=null)};return};runCatching{repo.deliveryCashOutstanding()}.onSuccess{balance->_state.update{it.copy(deliveryCash=balance)}}}
    fun createCashHandoff()=launchBusy{_state.update{it.copy(cashHandoff=repo.createDeliveryHandoff())}}
    fun dismissCashHandoff()=_state.update{it.copy(cashHandoff=null)}
    fun savePin(pin:String){runCatching{repo.sessionStore.setPin(pin)}.onSuccess{_state.update{it.copy(pinConfigured=true,message="PIN salvo neste aparelho.")}}.onFailure{_state.update{it.copy(error="Use um PIN numérico de 4 a 8 dígitos.")}}}
    fun setBiometric(enabled:Boolean){repo.sessionStore.biometricEnabled=enabled;_state.update{it.copy(biometricEnabled=enabled,message=if(enabled)"Biometria ativada."else"Biometria desativada.")}}
    fun logout()=viewModelScope.launch{_state.update{it.copy(loading=true)};runCatching{repo.logout()};_state.value=GoState()}
    fun clearFeedback()=_state.update{it.copy(error=null,message=null)}
    private fun launchBusy(block:suspend()->Unit)=viewModelScope.launch{_state.update{it.copy(loading=true,error=null)};runCatching{block()}.onFailure{e->if(e is ApiException&&e.status==401){repo.sessionStore.clear();_state.value=GoState(error="Sessão expirada. Entre novamente.")}else _state.update{it.copy(error=e.message?:"Falha inesperada.")}};_state.update{it.copy(loading=false)}}
    private fun money(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
    class Factory(
        private val repo:EventMenuRepository,
        private val eventRepo:EventOperationsRepository,
        private val managerRepo:ManagerOperationsRepository,
        private val notificationRepo:NotificationRepository,
    ):ViewModelProvider.Factory{
        @Suppress("UNCHECKED_CAST")override fun<T:ViewModel>create(modelClass:Class<T>):T=MainViewModel(repo,eventRepo,managerRepo,notificationRepo) as T
    }
}
