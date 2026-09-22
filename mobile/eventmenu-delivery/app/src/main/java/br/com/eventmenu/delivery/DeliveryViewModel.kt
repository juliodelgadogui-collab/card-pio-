package br.com.eventmenu.delivery

import android.app.Application
import androidx.compose.runtime.*
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.delivery.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.net.ConnectException
import java.net.NoRouteToHostException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

sealed interface Screen {
    data object Login : Screen
    data object Register : Screen
    data object VerifyEmail : Screen
    data object Home : Screen
    data object Catalog : Screen
    data object Cart : Screen
    data object Checkout : Screen
    data object Orders : Screen
    data object OrderDetail : Screen
    data object Profile : Screen
    data object AddressEditor : Screen
}

enum class MainDestination { Home, Search, Orders, Profile }
enum class MarketplaceLoadIssue { Offline, ServerUnavailable, Temporary }

class DeliveryViewModel(app: Application) : AndroidViewModel(app) {
    private val deliveryApp=app as? DeliveryApplication
    private val session=deliveryApp?.sessionStore?:SecureSessionStore(app)
    private val api=DeliveryApi{session.accessToken}
    private val cartPersistence=CartPersistence(app)
    var screen by mutableStateOf<Screen>(Screen.Home);private set
    var mainDestination by mutableStateOf(MainDestination.Home);private set
    var customer by mutableStateOf<Customer?>(null);private set
    var stores by mutableStateOf<List<Store>>(emptyList());private set
    var marketplaceLoading by mutableStateOf(false);private set
    var marketplaceIssue by mutableStateOf<MarketplaceLoadIssue?>(null);private set
    var storeSearchQuery by mutableStateOf("");private set
    var storeSearchResults by mutableStateOf<List<Store>>(emptyList());private set
    var storeSearchLoading by mutableStateOf(false);private set
    var storeSearchIssue by mutableStateOf<MarketplaceLoadIssue?>(null);private set
    var homeOpenOnly by mutableStateOf(false);private set
    var homeFreeOnly by mutableStateOf(false);private set
    var homePickupOnly by mutableStateOf(false);private set
    var homeCategory by mutableStateOf<String?>(null);private set
    var homeScrollIndex by mutableIntStateOf(0);private set
    var homeScrollOffset by mutableIntStateOf(0);private set
    var catalog by mutableStateOf<Catalog?>(null);private set
    val cart=mutableStateListOf<CartItem>()
    var couponQuote by mutableStateOf<CouponQuote?>(null);private set
    var couponCode by mutableStateOf("");private set
    var orders by mutableStateOf<List<OrderSummary>>(emptyList());private set
    var selectedOrder by mutableStateOf<OrderSummary?>(null);private set
    var paymentMethods by mutableStateOf<PaymentMethods?>(null);private set
    var pixPayment by mutableStateOf<PixPayment?>(null);private set
    var tracking by mutableStateOf<TrackingStatus?>(null);private set
    var paymentWaiting by mutableStateOf(false);private set
    var paymentPhase by mutableStateOf(PaymentPhase.Idle);private set
    var paymentSubmitting by mutableStateOf(false);private set
    var payerCpf by mutableStateOf("");private set
    var busy by mutableStateOf(false);private set
    var message by mutableStateOf<String?>(null);private set
    var pendingEmail by mutableStateOf("");private set
    var editingAddress by mutableStateOf<Address?>(null);private set
    private var profileReturnScreen:Screen?=null
    private var trackingJob:Job?=null
    private var orderRefreshJob:Job?=null
    private var paymentPollingJob:Job?=null
    private var marketplaceJob:Job?=null
    private var storeSearchJob:Job?=null
    private var recentOrdersJob:Job?=null

    val isAuthenticated:Boolean get()=!session.accessToken.isNullOrBlank()
    val canReturnFromProfile:Boolean get()=profileReturnScreen!=null

    init{restorePersistedCart();if(isAuthenticated){deliveryApp?.pushCoordinator?.syncAfterLogin();refreshSession()}}
    fun clearMessage(){message=null}
    fun navigate(value:Screen){screen=value;when(value){Screen.Home->mainDestination=MainDestination.Home;Screen.Orders->mainDestination=MainDestination.Orders;Screen.Profile->mainDestination=MainDestination.Profile;else->Unit}}
    fun navigateMain(value:MainDestination){
        when(value){
            MainDestination.Home->{mainDestination=value;screen=Screen.Home;if(stores.isEmpty()&&!marketplaceLoading)loadStores()}
            MainDestination.Search->{mainDestination=value;screen=Screen.Home;if(storeSearchQuery.isBlank())storeSearchResults=stores}
            MainDestination.Orders->{mainDestination=value;loadOrders()}
            MainDestination.Profile->{mainDestination=value;openProfile()}
        }
    }
    fun returnFromProfile(){val target=profileReturnScreen;profileReturnScreen=null;if(target!=null)screen=target else navigateMain(MainDestination.Home)}
    fun emailConfirmed(){session.accessToken=null;PaymentUiContext.clear();customer=null;payerCpf="";mainDestination=MainDestination.Home;screen=Screen.Login;message="E-mail confirmado. Entre com sua conta."}

    fun login(email:String,password:String)=action{
        val result=api.login(email.trim(),password);session.accessToken=result.token;customer=result.customer;deliveryApp?.pushCoordinator?.syncAfterLogin()
        if(result.customer.cpfConfigured){mainDestination=MainDestination.Home;screen=if(cart.isNotEmpty()&&catalog!=null)Screen.Cart else Screen.Home;loadStoresInternal()}else{mainDestination=MainDestination.Profile;screen=Screen.Profile;message="Complete seu CPF uma única vez para usar todos os meios de pagamento."}
    }
    fun register(name:String,email:String,phone:String,password:String,legalAccepted:Boolean=false)=action{if(!legalAccepted)error("Aceite os Termos de Uso e a Política de Privacidade para continuar.");val result=api.register(name.trim(),email.trim(),phone.trim(),password,legalAccepted);pendingEmail=result.email;screen=Screen.VerifyEmail;message=if(result.emailSent)"Enviamos o link de confirmação para ${result.email}." else "Cadastro criado. O servidor de e-mail precisa ser configurado para enviar a confirmação."}
    fun registerWithCpf(name:String,cpf:String,email:String,phone:String,password:String,legalAccepted:Boolean=false)=action{if(!legalAccepted)error("Aceite os Termos de Uso e a Política de Privacidade para continuar.");val result=api.register(name.trim(),cpf.trim(),email.trim(),phone.trim(),password,legalAccepted);pendingEmail=result.email;screen=Screen.VerifyEmail;message=if(result.emailSent)"Enviamos o link de confirmação para ${result.email}." else "Cadastro criado. O servidor de e-mail precisa ser configurado para enviar a confirmação."}
    fun resendVerification()=action{api.resend(pendingEmail);message="Se o cadastro estiver pendente, um novo link foi enviado."}
    fun forgotPassword(email:String)=action{api.forgotPassword(email);message="Se a conta existir, você receberá um link para criar uma nova senha."}
    fun logout()=action{val push=session.pushToken.orEmpty();runCatching{api.logout(push)};session.clear();cartPersistence.clear();PaymentUiContext.clear();customer=null;cart.clear();clearCoupon(false);trackingJob?.cancel();orderRefreshJob?.cancel();paymentPollingJob?.cancel();storeSearchJob?.cancel();recentOrdersJob?.cancel();paymentWaiting=false;paymentSubmitting=false;paymentPhase=PaymentPhase.Idle;pixPayment=null;payerCpf="";profileReturnScreen=null;stores=api.stores();storeSearchQuery="";storeSearchResults=stores;mainDestination=MainDestination.Home;screen=Screen.Home}

    fun loadHome(){loadStores();loadRecentOrdersForHome()}
    fun loadStores(query:String=""){
        if(query.isNotBlank()){updateMarketplaceSearch(query);return}
        marketplaceJob?.cancel()
        marketplaceJob=viewModelScope.launch{
            marketplaceLoading=true;marketplaceIssue=null
            try{
                stores=api.stores()
                if(storeSearchQuery.isBlank())storeSearchResults=stores
                if(homeCategory!=null&&stores.none{store->store.categories.any{it.equals(homeCategory,true)}})homeCategory=null
            }catch(c:CancellationException){throw c}catch(t:Throwable){marketplaceIssue=classifyMarketplaceIssue(t)}finally{marketplaceLoading=false}
        }
    }
    fun retryMarketplace(){loadStores()}
    fun updateMarketplaceSearch(value:String){
        val query=value.take(120)
        storeSearchQuery=query
        storeSearchJob?.cancel()
        storeSearchIssue=null
        if(query.isBlank()){
            storeSearchLoading=false
            storeSearchResults=stores
            return
        }
        storeSearchLoading=true
        storeSearchJob=viewModelScope.launch{
            delay(350)
            try{storeSearchResults=api.stores(query.trim())}
            catch(c:CancellationException){throw c}
            catch(t:Throwable){storeSearchIssue=classifyMarketplaceIssue(t)}
            finally{if(storeSearchQuery==query)storeSearchLoading=false}
        }
    }
    fun clearMarketplaceSearch(){updateMarketplaceSearch("")}
    fun retryMarketplaceSearch(){updateMarketplaceSearch(storeSearchQuery)}
    fun toggleHomeOpenOnly(){homeOpenOnly=!homeOpenOnly}
    fun toggleHomeFreeOnly(){homeFreeOnly=!homeFreeOnly}
    fun toggleHomePickupOnly(){homePickupOnly=!homePickupOnly}
    fun selectHomeCategory(value:String?){homeCategory=if(value!=null&&homeCategory?.equals(value,true)==true)null else value}
    fun rememberHomeScroll(index:Int,offset:Int){homeScrollIndex=index.coerceAtLeast(0);homeScrollOffset=offset.coerceAtLeast(0)}
    private fun loadRecentOrdersForHome(){
        if(!isAuthenticated||orders.isNotEmpty()||recentOrdersJob?.isActive==true)return
        recentOrdersJob=viewModelScope.launch{runCatching{api.orders()}.onSuccess{orders=it}}
    }

    fun openStore(store:Store)=action{
        val loaded=api.catalog(store.tenantId,store.unitId)
        val sameStore=catalog?.store?.let{it.tenantId==store.tenantId&&it.unitId==store.unitId}==true
        if(!sameStore&&cart.isNotEmpty()){cart.clear();cartPersistence.clear();clearCoupon(false)}
        catalog=loaded
        revalidateCurrentCart()
        screen=Screen.Catalog;if(!store.acceptingOrders)message="Este restaurante está fechado para novos pedidos agora. Você ainda pode consultar o cardápio."}
    fun toggleFavorite(store:Store){if(!isAuthenticated){screen=Screen.Login;message="Entre para salvar restaurantes nos favoritos.";return};action(showBusy=false){api.favorite(store.tenantId,!store.favorite);stores=stores.map{if(it.tenantId==store.tenantId&&it.unitId==store.unitId)it.copy(favorite=!store.favorite)else it};storeSearchResults=storeSearchResults.map{if(it.tenantId==store.tenantId&&it.unitId==store.unitId)it.copy(favorite=!store.favorite)else it}}}
    fun addToCart(product:Product,quantity:Int=1,optionIds:Set<Int> = emptySet(),notes:String=""){if(!product.available)return;val index=cart.indexOfFirst{it.product.id==product.id&&it.optionIds==optionIds&&it.notes==notes};if(index>=0)cart[index]=cart[index].copy(quantity=(cart[index].quantity+quantity).coerceAtMost(99))else cart+=CartItem(product,quantity.coerceIn(1,99),optionIds,notes.take(500));invalidateCouponForCartChange();persistCart();message="${product.name} adicionado."}
    fun updateCart(index:Int,quantity:Int){if(index !in cart.indices)return;if(quantity<=0)cart.removeAt(index)else cart[index]=cart[index].copy(quantity=quantity.coerceAtMost(99));invalidateCouponForCartChange();persistCart()}
    fun removeCartItem(index:Int){if(index !in cart.indices)return;cart.removeAt(index);invalidateCouponForCartChange();persistCart()}
    fun clearCart(){cart.clear();cartPersistence.clear();clearCoupon(false);message="Carrinho limpo."}
    fun replaceCartItem(index:Int,quantity:Int,optionIds:Set<Int>,notes:String){if(index !in cart.indices)return;val product=cart[index].product;cart[index]=CartItem(product,quantity.coerceIn(1,99),optionIds,notes.take(500));invalidateCouponForCartChange();persistCart()}
    fun validateCoupon(code:String)=action{val cat=catalog?:error("Loja não carregada.");val normalized=code.trim().uppercase();if(normalized.isBlank())error("Digite o código do cupom.");val subtotal=cart.sumOf{it.totalCents()};if(subtotal<=0)error("Adicione itens ao carrinho antes de aplicar o cupom.");val quote=api.couponQuote(cat.store.tenantId,normalized,subtotal);couponCode=quote.code;couponQuote=quote;message="Cupom ${quote.code} aplicado: ${money(quote.discountCents)} de desconto."}
    fun clearCoupon(showMessage:Boolean=true){val had=couponQuote!=null||couponCode.isNotBlank();couponQuote=null;couponCode="";if(showMessage&&had)message="Cupom removido."}
    private fun invalidateCouponForCartChange(){if(couponQuote!=null||couponCode.isNotBlank()){couponQuote=null;couponCode="";message="O carrinho mudou. Aplique o cupom novamente."}}

    fun checkoutCart(addressId:Int){
        if(!isAuthenticated){screen=Screen.Login;message="Entre para escolher o endereço e finalizar seu pedido.";return}
        action{
            val current=catalog?:error("Loja não carregada.")
            catalog=api.catalog(current.store.tenantId,current.store.unitId)
            revalidateCurrentCart()
            val cat=catalog?:error("Loja não carregada.");if(!cat.store.acceptingOrders)error("Este restaurante pausou novos pedidos no momento.");if(cart.isEmpty())error("Seu carrinho mudou e não há itens válidos para finalizar.");val subtotal=cart.sumOf{it.totalCents()};if(subtotal<cat.store.minimumOrderCents)error("O pedido mínimo é ${money(cat.store.minimumOrderCents)}.")
            val appliedCoupon=couponCode;if(appliedCoupon.isNotBlank())couponQuote=api.couponQuote(cat.store.tenantId,appliedCoupon,subtotal)
            val order=api.createOrder(cat,addressId,cart.toList(),appliedCoupon);selectedOrder=order;PaymentUiContext.updateAmount(order.totalCents);cart.clear();cartPersistence.clear();clearCoupon(false);paymentMethods=api.paymentMethods(order.orderNumber);pixPayment=null;paymentWaiting=false;paymentSubmitting=false;paymentPhase=PaymentPhase.Idle;payerCpf="";if(paymentMethods?.cards?.isNotEmpty()==true&&customer?.cpfConfigured==true)runCatching{api.paymentIdentification()}.onSuccess{payerCpf=it};screen=Screen.Checkout
        }
    }
    fun reloadPaymentMethods()=action(showBusy=false){val id=selectedOrder?.orderNumber?:return@action;paymentMethods=api.paymentMethods(id)}
    fun prepareCardIdentification()=action(showBusy=false){if(customer?.cpfConfigured!=true)error("Complete seu CPF no perfil para pagar com cartão.");payerCpf=api.paymentIdentification()}
    fun payPix(provider:String,taxId:String){
        if(paymentSubmitting||paymentWaiting){message="Já existe uma cobrança em andamento. Aguarde a confirmação antes de tentar novamente.";return}
        paymentSubmitting=true
        action{
            try{
                if(customer?.cpfConfigured!=true)error("Complete seu CPF no perfil para gerar o PIX.")
                val id=selectedOrder?.orderNumber?:error("Pedido não encontrado.")
                pixPayment=api.pix(id,provider,"")
                paymentPhase=PaymentPhase.Waiting
                message="PIX gerado. Assim que o provedor confirmar, o pedido será atualizado automaticamente."
                startPaymentPolling(id)
            }finally{paymentSubmitting=false}
        }
    }
    fun payCash(changeForCents:Int?){
        if(paymentSubmitting||paymentWaiting){message="Há uma cobrança eletrônica em andamento. Aguarde o resultado antes de trocar a forma de pagamento.";return}
        paymentSubmitting=true
        action{
            try{
                val id=selectedOrder?.orderNumber?:error("Pedido não encontrado.")
                paymentPollingJob?.cancel();paymentWaiting=false;pixPayment=null
                api.cash(id,changeForCents)
                paymentPhase=PaymentPhase.Idle
                PaymentUiContext.clear();message="Pagamento em dinheiro registrado para a entrega.";openOrderSuspend(id)
            }finally{paymentSubmitting=false}
        }
    }
    suspend fun payCardToken(token:String,paymentMethodId:String,paymentTypeId:String,installments:Int,taxId:String){
        if(paymentSubmitting||paymentWaiting){message="Já existe uma cobrança em andamento. Aguarde a confirmação antes de tentar novamente.";return}
        paymentSubmitting=true
        try{
            if(customer?.cpfConfigured!=true)error("Complete seu CPF no perfil para pagar com cartão.")
            val id=selectedOrder?.orderNumber?:error("Pedido não encontrado.")
            val status=api.card(id,token,paymentMethodId,paymentTypeId,installments,"")
            if(status=="paid"){
                paymentPollingJob?.cancel();paymentWaiting=false;paymentPhase=PaymentPhase.Confirmed;pixPayment=null;payerCpf="";PaymentUiContext.clear();message=if(paymentTypeId=="debit_card")"Pagamento no débito aprovado." else "Pagamento no crédito aprovado.";openOrderSuspend(id)
            }else{
                pixPayment=null;paymentPhase=PaymentPhase.Waiting;message="Pagamento enviado. Aguardando confirmação do Mercado Pago.";startPaymentPolling(id)
            }
        }finally{paymentSubmitting=false}
    }
    fun loadOrders(){if(!isAuthenticated){screen=Screen.Login;message="Entre para ver seus pedidos.";return};mainDestination=MainDestination.Orders;screen=Screen.Orders;action{orders=api.orders()}}
    fun refreshOrders()=action(showBusy=false){if(isAuthenticated)orders=api.orders()}
    fun openOrder(id:Int)=action{openOrderSuspend(id)}
    private suspend fun openOrderSuspend(id:Int){selectedOrder=api.order(id);screen=Screen.OrderDetail;startTracking();startOrderRefresh(id)}
    fun repeatOrder(id:Int)=action{val r=api.reorder(id);val s=r.getJSONObject("store");catalog=api.catalog(s.getInt("tenant_id"),s.getInt("unit_id"));if(catalog?.store?.acceptingOrders==false)error("Este restaurante pausou novos pedidos no momento.");cart.clear();clearCoupon(false);val items=r.optJSONArray("items");if(items!=null)for(i in 0 until items.length()){val row=items.getJSONObject(i);val product=catalog?.products?.firstOrNull{it.id==row.optInt("product_id")}?:continue;cart+=CartItem(product,row.optDouble("quantity",1.0).toInt().coerceAtLeast(1))};persistCart();screen=Screen.Cart}
    fun submitReview(orderId:Int,rating:Int,comment:String)=action{api.review(orderId,rating,comment);message="Obrigado pela avaliação!"}
    fun openProfile(){if(!isAuthenticated){screen=Screen.Login;message="Entre para acessar seu perfil, endereços e benefícios.";return};profileReturnScreen=if(screen==Screen.Checkout)Screen.Checkout else null;mainDestination=MainDestination.Profile;screen=Screen.Profile;action{customer=api.me()}}
    fun editAddress(address:Address?=null){if(!isAuthenticated){screen=Screen.Login;message="Entre para salvar um endereço.";return};editingAddress=address;screen=Screen.AddressEditor}
    fun saveAddress(address:Address)=action{api.saveAddress(address);customer=api.me();editingAddress=null;mainDestination=MainDestination.Profile;screen=Screen.Profile;message="Endereço salvo."}
    fun deleteAddress(id:Int)=action{api.deleteAddress(id);customer=api.me();message="Endereço removido."}
    fun saveProfile(name:String,phone:String)=action{customer=api.saveProfile(name,phone);message="Perfil atualizado."}
    fun saveProfile(name:String,phone:String,cpf:String)=action{customer=api.saveProfile(name,phone,cpf);payerCpf="";message="Perfil atualizado.";if(cart.isNotEmpty()&&catalog!=null)screen=Screen.Cart}
    fun refreshCurrentOrder(){selectedOrder?.orderNumber?.let(::openOrder)}
    private fun refreshSession()=action(showBusy=false){try{customer=api.me();if(customer?.cpfConfigured==true){mainDestination=MainDestination.Home;loadStoresInternal();screen=Screen.Home}else{mainDestination=MainDestination.Profile;screen=Screen.Profile;message="Complete seu CPF uma única vez para continuar."}}catch(e:ApiException){if(e.code=="UNAUTHENTICATED"){session.accessToken=null;PaymentUiContext.clear();customer=null;stores=api.stores();storeSearchResults=stores;mainDestination=MainDestination.Home;screen=Screen.Home}else throw e}}
    private suspend fun loadStoresInternal(){stores=api.stores();if(storeSearchQuery.isBlank())storeSearchResults=stores}
    private fun startPaymentPolling(orderId:Int){
        paymentPollingJob?.cancel();paymentWaiting=true;paymentPhase=PaymentPhase.Waiting
        paymentPollingJob=viewModelScope.launch{
            var attempt=0
            while(isActive&&selectedOrder?.orderNumber==orderId){
                delay(paymentPollDelayMillis(attempt++))
                val result=runCatching{api.paymentStatus(orderId)}.getOrNull()?:continue
                when(val phase=paymentPhaseFor(result.optString("payment_status"))){
                    PaymentPhase.Confirmed->{paymentWaiting=false;paymentPhase=phase;pixPayment=null;payerCpf="";PaymentUiContext.clear();message="Pagamento confirmado.";runCatching{openOrderSuspend(orderId)}.onFailure{message=it.message?:"Pagamento confirmado. Atualize seus pedidos."};return@launch}
                    PaymentPhase.Expired->{paymentWaiting=false;paymentPhase=phase;pixPayment=null;message="A cobrança expirou. Você pode gerar uma nova forma de pagamento.";return@launch}
                    PaymentPhase.Error->{paymentWaiting=false;paymentPhase=phase;pixPayment=null;message="O pagamento não foi concluído. Você pode tentar novamente ou escolher outra forma.";return@launch}
                    else->{paymentWaiting=true;paymentPhase=PaymentPhase.Waiting}
                }
            }
        }
    }
    private fun startOrderRefresh(orderId:Int){
        orderRefreshJob?.cancel()
        orderRefreshJob=viewModelScope.launch{
            var attempt=0
            while(isActive&&screen==Screen.OrderDetail&&selectedOrder?.orderNumber==orderId){
                delay(orderPollDelayMillis(attempt++))
                val previousTrackingToken=selectedOrder?.trackingToken
                val fresh=runCatching{api.order(orderId)}.getOrNull()?:continue
                selectedOrder=fresh
                orders=orders.map{if(it.orderNumber==orderId)fresh else it}
                if(previousTrackingToken!=fresh.trackingToken&&!fresh.trackingToken.isNullOrBlank())startTracking()
                if(isTerminalOrderStatus(fresh.status))return@launch
            }
        }
    }
    private fun startTracking(){
        trackingJob?.cancel();tracking=null;val token=selectedOrder?.trackingToken?:return
        trackingJob=viewModelScope.launch{
            var attempt=0
            while(isActive&&screen==Screen.OrderDetail&&selectedOrder?.trackingToken==token){
                runCatching{api.tracking(token)}.onSuccess{tracking=it}
                if(isTerminalOrderStatus(tracking?.status.orEmpty()))return@launch
                delay(trackingPollDelayMillis(attempt++))
            }
        }
    }
    private fun persistCart(){cartPersistence.save(catalog,cart.toList())}
    private fun restorePersistedCart(){
        val saved=cartPersistence.load()?:return
        val products=saved.items.map{it.product}.distinctBy{it.id}
        catalog=Catalog(saved.store,emptyList(),products,"")
        cart.clear();cart.addAll(saved.items)
        viewModelScope.launch{
            runCatching{api.catalog(saved.store.tenantId,saved.store.unitId)}.onSuccess{fresh->
                catalog=fresh
                revalidateCurrentCart()
            }
        }
    }
    private fun revalidateCurrentCart(){
        val fresh=catalog?:return
        if(cart.isEmpty())return
        val validated=cart.mapNotNull{old->
            val product=fresh.products.firstOrNull{it.id==old.product.id&&it.available}?:return@mapNotNull null
            val validIds=product.modifierGroups.flatMap{it.options}.map{it.id}.toSet()
            val selected=old.optionIds.intersect(validIds)
            val valid=product.modifierGroups.all{group->
                val count=group.options.count{it.id in selected}
                count>=group.minSelect.coerceAtLeast(if(group.required)1 else 0)&&count<=group.maxSelect.coerceAtLeast(1)
            }
            if(valid)CartItem(product,old.quantity,selected,old.notes)else null
        }
        if(validated.size!=cart.size)message="Atualizamos seu carrinho porque alguns itens ou adicionais mudaram."
        cart.clear();cart.addAll(validated);persistCart()
    }

    private fun classifyMarketplaceIssue(t:Throwable):MarketplaceLoadIssue=when(t){is UnknownHostException,is ConnectException,is NoRouteToHostException->MarketplaceLoadIssue.Offline;is SocketTimeoutException->MarketplaceLoadIssue.Temporary;is ApiException->MarketplaceLoadIssue.ServerUnavailable;else->MarketplaceLoadIssue.Temporary}
    private fun action(showBusy:Boolean=true,block:suspend()->Unit){viewModelScope.launch{if(showBusy)busy=true;try{block()}catch(e:ApiException){if(e.code=="UNAUTHENTICATED"){session.accessToken=null;PaymentUiContext.clear();customer=null;payerCpf="";screen=Screen.Login};message=e.message}catch(t:Throwable){message=t.message?:"Não foi possível concluir agora."}finally{if(showBusy)busy=false}}}
    override fun onCleared(){trackingJob?.cancel();orderRefreshJob?.cancel();paymentPollingJob?.cancel();marketplaceJob?.cancel();storeSearchJob?.cancel();recentOrdersJob?.cancel();payerCpf="";PaymentUiContext.clear();super.onCleared()}
}

fun money(cents:Int):String=java.text.NumberFormat.getCurrencyInstance(java.util.Locale("pt","BR")).format(cents/100.0)
