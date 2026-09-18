package br.com.eventmenu.delivery

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class MarketplaceUiState(
    val stores: List<Store> = emptyList(),
    val catalog: Catalog? = null,
    val cart: List<CartLine> = emptyList(),
    val order: ConsumerOrder? = null,
    val tracking: TrackingStatus? = null,
    val screen: Screen = Screen.Stores,
    val query: String = "",
    val loading: Boolean = false,
    val message: String? = null,
)

class MarketplaceViewModel(application: Application) : AndroidViewModel(application) {
    private val api = MarketplaceApi()
    private val prefs = application.getSharedPreferences("eventmenu_delivery", 0)
    private val _state = MutableStateFlow(MarketplaceUiState())
    val state: StateFlow<MarketplaceUiState> = _state.asStateFlow()
    private var pollJob: Job? = null

    init {
        loadStores()
        restoreOrder()
    }

    fun search(value: String) {
        _state.value = _state.value.copy(query = value)
        viewModelScope.launch {
            delay(250)
            if (_state.value.query == value) loadStores(value)
        }
    }

    fun loadStores(query: String = _state.value.query) = launchBusy {
        val stores = api.stores(query = query)
        _state.value = _state.value.copy(stores = stores)
    }

    fun openStore(store: Store) = launchBusy {
        val catalog = api.catalog(store)
        _state.value = _state.value.copy(catalog = catalog, cart = emptyList(), screen = Screen.Menu(catalog), tracking = null)
    }

    fun openActiveOrder() {
        val order = _state.value.order ?: return
        _state.value = _state.value.copy(screen = Screen.Order(order), message = null)
    }

    fun addToCart(product: Product, quantity: Int, selectedOptionIds: Set<Int>): String? {
        if (!product.available) return "Este item está indisponível no momento."
        val qty = quantity.coerceIn(1, 99)
        product.modifierGroups.forEach { group ->
            val count = group.options.count { it.id in selectedOptionIds }
            if (count < group.minSelect) return if (group.minSelect == 1) "Escolha uma opção em ${group.name}." else "Escolha pelo menos ${group.minSelect} opções em ${group.name}."
            if (count > group.maxSelect) return if (group.maxSelect == 1) "Escolha apenas uma opção em ${group.name}." else "Escolha no máximo ${group.maxSelect} opções em ${group.name}."
        }
        val validIds = product.modifierGroups.flatMap { it.options }.map { it.id }.toSet()
        if (!selectedOptionIds.all { it in validIds }) return "Uma opção selecionada não está mais disponível."
        val normalized = selectedOptionIds.toSortedSet()
        val current = _state.value.cart.toMutableList()
        val index = current.indexOfFirst { it.product.id == product.id && it.selectedOptionIds == normalized }
        if (index >= 0) current[index] = current[index].copy(quantity = (current[index].quantity + qty).coerceAtMost(99))
        else current += CartLine(product, qty, normalized)
        _state.value = _state.value.copy(cart = current, message = "${product.name} adicionado ao carrinho.")
        return null
    }

    fun changeQuantity(index: Int, delta: Int) {
        val current = _state.value.cart.toMutableList()
        if (index !in current.indices) return
        val next = current[index].quantity + delta
        if (next <= 0) current.removeAt(index) else current[index] = current[index].copy(quantity = next.coerceAtMost(99))
        _state.value = _state.value.copy(cart = current)
    }

    fun checkout() {
        val catalog = _state.value.catalog ?: return
        if (_state.value.cart.isEmpty()) {
            _state.value = _state.value.copy(message = "Seu carrinho está vazio.")
            return
        }
        val productsTotal = _state.value.cart.sumOf { it.totalCents }
        if (productsTotal < catalog.store.minimumOrderCents) {
            _state.value = _state.value.copy(message = "O pedido mínimo é ${catalog.store.minimumOrderCents.money()}.")
            return
        }
        _state.value = _state.value.copy(screen = Screen.Checkout(catalog), message = null)
    }

    fun submitOrder(customer: CheckoutCustomer) = launchBusy {
        val current = _state.value
        val catalog = current.catalog ?: throw MarketplaceException("Escolha uma loja para continuar.")
        if (current.cart.isEmpty()) throw MarketplaceException("Seu carrinho está vazio.")
        if (customer.name.isBlank() || customer.phone.isBlank() || customer.address.isBlank()) throw MarketplaceException("Informe nome, telefone e endereço para a entrega.")
        // Renova a sessão curta antes de criar o pedido; catálogo/preço/estoque continuam validados pelo Server.
        val freshCatalog = api.catalog(catalog.store)
        val order = api.createOrder(freshCatalog, current.cart, customer)
        prefs.edit().putString("active_order_token", order.publicToken).apply()
        _state.value = _state.value.copy(catalog = freshCatalog, cart = emptyList(), order = order, screen = Screen.Order(order), message = "Pedido recebido com sucesso.")
        startPolling(order.publicToken)
    }

    fun refreshOrder() {
        val token = _state.value.order?.publicToken ?: return
        viewModelScope.launch { refreshOrderInternal(token, showErrors = true) }
    }

    fun clearMessage() {
        _state.value = _state.value.copy(message = null)
    }

    fun back() {
        when (_state.value.screen) {
            Screen.Stores -> Unit
            is Screen.Menu -> _state.value = _state.value.copy(catalog = null, cart = emptyList(), screen = Screen.Stores, message = null)
            is Screen.Checkout -> _state.value.catalog?.let { _state.value = _state.value.copy(screen = Screen.Menu(it), message = null) }
            is Screen.Order -> _state.value = _state.value.copy(screen = Screen.Stores, message = null)
        }
    }

    private fun restoreOrder() {
        val token = prefs.getString("active_order_token", null)?.trim().orEmpty()
        if (token.isBlank()) return
        viewModelScope.launch {
            runCatching { api.orderStatus(token) }
                .onSuccess { order ->
                    _state.value = _state.value.copy(order = order, screen = Screen.Order(order), loading = false)
                    if (order.status !in setOf("completed", "cancelled")) startPolling(token)
                }
                .onFailure { prefs.edit().remove("active_order_token").apply() }
        }
    }

    private fun startPolling(publicToken: String) {
        pollJob?.cancel()
        pollJob = viewModelScope.launch {
            while (true) {
                delay(8_000)
                val order = refreshOrderInternal(publicToken, showErrors = false) ?: continue
                if (order.status in setOf("completed", "cancelled")) {
                    prefs.edit().remove("active_order_token").apply()
                    break
                }
                val trackingToken = order.tracking?.token
                if (!trackingToken.isNullOrBlank()) {
                    runCatching { api.tracking(trackingToken) }
                        .onSuccess { tracking -> _state.value = _state.value.copy(tracking = tracking) }
                } else if (_state.value.tracking != null) {
                    _state.value = _state.value.copy(tracking = null)
                }
            }
        }
    }

    private suspend fun refreshOrderInternal(publicToken: String, showErrors: Boolean): ConsumerOrder? {
        return runCatching { api.orderStatus(publicToken) }
            .onSuccess { order ->
                val current = _state.value
                val nextScreen = if (current.screen is Screen.Order) Screen.Order(order) else current.screen
                _state.value = current.copy(
                    order = order,
                    screen = nextScreen,
                    message = if (showErrors) null else current.message,
                )
            }
            .onFailure { error -> if (showErrors) _state.value = _state.value.copy(message = friendly(error)) }
            .getOrNull()
    }

    private fun launchBusy(block: suspend () -> Unit) {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true, message = null)
            try { block() }
            catch (error: Throwable) { _state.value = _state.value.copy(message = friendly(error)) }
            finally { _state.value = _state.value.copy(loading = false) }
        }
    }

    private fun friendly(error: Throwable): String = when (error) {
        is MarketplaceException -> error.message ?: "Não foi possível concluir agora."
        else -> "Não foi possível concluir agora. Tente novamente."
    }
}
