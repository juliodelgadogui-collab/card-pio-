package br.com.eventmenu.delivery

import android.app.Application
import androidx.compose.runtime.*
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.delivery.data.*
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

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

class DeliveryViewModel(app: Application) : AndroidViewModel(app) {
    private val deliveryApp = app as? DeliveryApplication
    private val session = deliveryApp?.sessionStore ?: SecureSessionStore(app)
    private val api = DeliveryApi { session.accessToken }

    var screen by mutableStateOf<Screen>(if (session.accessToken.isNullOrBlank()) Screen.Login else Screen.Home)
        private set
    var customer by mutableStateOf<Customer?>(null); private set
    var stores by mutableStateOf<List<Store>>(emptyList()); private set
    var catalog by mutableStateOf<Catalog?>(null); private set
    val cart = mutableStateListOf<CartItem>()
    var orders by mutableStateOf<List<OrderSummary>>(emptyList()); private set
    var selectedOrder by mutableStateOf<OrderSummary?>(null); private set
    var paymentMethods by mutableStateOf<PaymentMethods?>(null); private set
    var pixPayment by mutableStateOf<PixPayment?>(null); private set
    var tracking by mutableStateOf<TrackingStatus?>(null); private set
    var paymentWaiting by mutableStateOf(false); private set
    var busy by mutableStateOf(false); private set
    var message by mutableStateOf<String?>(null); private set
    var pendingEmail by mutableStateOf(""); private set
    var editingAddress by mutableStateOf<Address?>(null); private set
    private var trackingJob: Job? = null
    private var paymentPollingJob: Job? = null

    init {
        if (!session.accessToken.isNullOrBlank()) {
            deliveryApp?.pushCoordinator?.syncAfterLogin()
            refreshSession()
        }
    }

    fun clearMessage() { message = null }
    fun navigate(value: Screen) { screen = value }
    fun emailConfirmed() { session.clear(); customer = null; screen = Screen.Login; message = "E-mail confirmado. Entre com sua conta." }

    fun login(email: String, password: String) = action {
        val result = api.login(email.trim(), password)
        session.accessToken = result.token
        customer = result.customer
        deliveryApp?.pushCoordinator?.syncAfterLogin()
        screen = Screen.Home
        loadStoresInternal()
    }

    fun register(name: String, email: String, phone: String, password: String) = action {
        val result = api.register(name.trim(), email.trim(), phone.trim(), password)
        pendingEmail = result.email
        screen = Screen.VerifyEmail
        message = if (result.emailSent) "Enviamos o link de confirmação para ${result.email}." else "Cadastro criado. O servidor de e-mail precisa ser configurado para enviar a confirmação."
    }

    fun resendVerification() = action {
        api.resend(pendingEmail)
        message = "Se o cadastro estiver pendente, um novo link foi enviado."
    }

    fun forgotPassword(email: String) = action {
        api.forgotPassword(email)
        message = "Se a conta existir, você receberá um link para criar uma nova senha."
    }

    fun logout() = action {
        runCatching { api.logout() }
        session.clear(); customer = null; stores = emptyList(); cart.clear(); trackingJob?.cancel(); paymentPollingJob?.cancel(); paymentWaiting = false; screen = Screen.Login
    }

    fun loadStores(query: String = "") = action(showBusy = false) { stores = api.stores(query) }

    fun openStore(store: Store) = action {
        catalog = api.catalog(store.tenantId, store.unitId)
        cart.clear(); screen = Screen.Catalog
        if (!store.acceptingOrders) message = "Este restaurante está pausado no momento. Você pode ver o cardápio, mas novos pedidos estão temporariamente indisponíveis."
    }

    fun toggleFavorite(store: Store) = action(showBusy = false) {
        api.favorite(store.tenantId, !store.favorite)
        stores = stores.map { if (it.tenantId == store.tenantId && it.unitId == store.unitId) it.copy(favorite = !store.favorite) else it }
    }

    fun addToCart(product: Product, quantity: Int = 1, optionIds: Set<Int> = emptySet(), notes: String = "") {
        if (!product.available) return
        val index = cart.indexOfFirst { it.product.id == product.id && it.optionIds == optionIds && it.notes == notes }
        if (index >= 0) cart[index] = cart[index].copy(quantity = (cart[index].quantity + quantity).coerceAtMost(99))
        else cart += CartItem(product, quantity.coerceIn(1,99), optionIds, notes)
        message = "${product.name} adicionado."
    }

    fun updateCart(index: Int, quantity: Int) {
        if (index !in cart.indices) return
        if (quantity <= 0) cart.removeAt(index) else cart[index] = cart[index].copy(quantity = quantity.coerceAtMost(99))
    }

    fun checkoutCart(addressId: Int) = action {
        val cat = catalog ?: error("Loja não carregada.")
        if (!cat.store.acceptingOrders) error("Este restaurante pausou novos pedidos no momento.")
        if (cart.isEmpty()) error("Seu carrinho está vazio.")
        val subtotal = cart.sumOf { it.totalCents() }
        if (subtotal < cat.store.minimumOrderCents) error("O pedido mínimo é ${money(cat.store.minimumOrderCents)}.")
        val order = api.createOrder(cat, addressId, cart.toList())
        selectedOrder = order
        cart.clear()
        paymentMethods = api.paymentMethods(order.orderNumber)
        pixPayment = null
        paymentWaiting = false
        screen = Screen.Checkout
    }

    fun payPix(provider: String, taxId: String) = action {
        val id = selectedOrder?.orderNumber ?: error("Pedido não encontrado.")
        pixPayment = api.pix(id, provider, taxId)
        message = "PIX gerado. Assim que o provedor confirmar, o pedido será atualizado automaticamente."
        startPaymentPolling(id)
    }

    fun payCash(changeForCents: Int?) = action {
        val id = selectedOrder?.orderNumber ?: error("Pedido não encontrado.")
        paymentPollingJob?.cancel(); paymentWaiting = false
        api.cash(id, changeForCents)
        message = "Pagamento em dinheiro registrado para a entrega."
        openOrderSuspend(id)
    }

    suspend fun payCardToken(token: String, paymentMethodId: String, installments: Int, taxId: String) {
        val id = selectedOrder?.orderNumber ?: error("Pedido não encontrado.")
        val status = api.card(id, token, paymentMethodId, installments, taxId)
        if (status == "paid") {
            paymentPollingJob?.cancel(); paymentWaiting = false
            message = "Pagamento aprovado."
            openOrderSuspend(id)
        } else {
            message = "Pagamento enviado. Aguardando confirmação do Mercado Pago."
            startPaymentPolling(id)
        }
    }

    fun loadOrders() = action { orders = api.orders(); screen = Screen.Orders }

    fun openOrder(id: Int) = action { openOrderSuspend(id) }
    private suspend fun openOrderSuspend(id: Int) {
        selectedOrder = api.order(id); screen = Screen.OrderDetail; startTracking()
    }

    fun repeatOrder(id: Int) = action {
        val r = api.reorder(id)
        val s = r.getJSONObject("store")
        catalog = api.catalog(s.getInt("tenant_id"), s.getInt("unit_id"))
        if (catalog?.store?.acceptingOrders == false) error("Este restaurante pausou novos pedidos no momento.")
        cart.clear()
        val items = r.optJSONArray("items")
        if (items != null) for(i in 0 until items.length()) {
            val row = items.getJSONObject(i); val product = catalog?.products?.firstOrNull { it.id == row.optInt("product_id") } ?: continue
            cart += CartItem(product, row.optDouble("quantity",1.0).toInt().coerceAtLeast(1))
        }
        screen = Screen.Cart
    }

    fun submitReview(orderId: Int, rating: Int, comment: String) = action {
        api.review(orderId, rating, comment); message = "Obrigado pela avaliação!"
    }

    fun openProfile() = action { customer = api.me(); screen = Screen.Profile }

    fun editAddress(address: Address? = null) { editingAddress = address; screen = Screen.AddressEditor }

    fun saveAddress(address: Address) = action {
        api.saveAddress(address); customer = api.me(); editingAddress = null; screen = Screen.Profile; message = "Endereço salvo."
    }

    fun deleteAddress(id: Int) = action {
        api.deleteAddress(id); customer = api.me(); message = "Endereço removido."
    }

    fun saveProfile(name: String, phone: String) = action { customer = api.saveProfile(name, phone); message = "Perfil atualizado." }

    fun refreshCurrentOrder() { selectedOrder?.orderNumber?.let(::openOrder) }

    private fun refreshSession() = action(showBusy = false) {
        try { customer = api.me(); loadStoresInternal(); screen = Screen.Home }
        catch (e: ApiException) { if (e.code == "UNAUTHENTICATED") { session.clear(); screen = Screen.Login } else throw e }
    }

    private suspend fun loadStoresInternal() { stores = api.stores() }

    private fun startPaymentPolling(orderId: Int) {
        paymentPollingJob?.cancel(); paymentWaiting = true
        paymentPollingJob = viewModelScope.launch {
            repeat(90) {
                delay(if (it == 0) 2_500 else 5_000)
                val result = runCatching { api.paymentStatus(orderId) }.getOrNull() ?: return@repeat
                when (result.optString("payment_status").lowercase()) {
                    "paid" -> {
                        paymentWaiting = false
                        message = "Pagamento confirmado."
                        runCatching { openOrderSuspend(orderId) }.onFailure { message = it.message ?: "Pagamento confirmado. Atualize seus pedidos." }
                        return@launch
                    }
                    "failed", "cancelled" -> {
                        paymentWaiting = false
                        message = "O pagamento não foi concluído. Você pode tentar novamente ou escolher outra forma."
                        return@launch
                    }
                }
            }
            paymentWaiting = false
            message = "A confirmação ainda não chegou. O pedido continuará sendo atualizado pelo servidor."
        }
    }

    private fun startTracking() {
        trackingJob?.cancel(); tracking = null
        val token = selectedOrder?.trackingToken ?: return
        trackingJob = viewModelScope.launch {
            repeat(180) {
                runCatching { api.tracking(token) }.onSuccess { tracking = it }
                if (tracking?.status == "completed" || tracking?.status == "cancelled") return@launch
                delay(10_000)
            }
        }
    }

    private fun action(showBusy: Boolean = true, block: suspend () -> Unit) {
        viewModelScope.launch {
            if (showBusy) busy = true
            try { block() }
            catch (e: ApiException) {
                if (e.code == "UNAUTHENTICATED") { session.clear(); customer = null; screen = Screen.Login }
                message = e.message
            } catch (t: Throwable) { message = t.message ?: "Não foi possível concluir agora." }
            finally { if (showBusy) busy = false }
        }
    }

    override fun onCleared() { trackingJob?.cancel(); paymentPollingJob?.cancel(); super.onCleared() }
}

fun money(cents: Int): String = java.text.NumberFormat.getCurrencyInstance(java.util.Locale("pt","BR")).format(cents / 100.0)
