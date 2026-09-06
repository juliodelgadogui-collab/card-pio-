package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.OperatingUnit
import br.com.eventmenu.go.data.OperatingUnitRepository
import br.com.eventmenu.go.data.UnassignedUnitDelivery
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeliveryUnitTriageState(
    val units: List<OperatingUnit> = emptyList(),
    val orders: List<UnassignedUnitDelivery> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val changedVersion: Int = 0,
)

class DeliveryUnitTriageViewModel(private val repository: OperatingUnitRepository) : ViewModel() {
    private val _state = MutableStateFlow(DeliveryUnitTriageState())
    val state: StateFlow<DeliveryUnitTriageState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching {
            val context = repository.list()
            val orders = repository.unassignedDeliveries()
            context.units to orders
        }.onSuccess { (units, orders) ->
            _state.update { it.copy(units = units, orders = orders, loading = false) }
        }.onFailure { error ->
            _state.update { it.copy(loading = false, error = error.message ?: "Falha ao carregar triagem de Delivery.") }
        }
    }

    fun assign(orderId: Int, unitId: Int) = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.assignDeliveryUnit(orderId, unitId) }
            .onSuccess {
                val remaining = _state.value.orders.filterNot { it.id == orderId }
                _state.update {
                    it.copy(
                        orders = remaining,
                        loading = false,
                        message = "Pedido #$orderId direcionado para a unidade.",
                        changedVersion = it.changedVersion + 1,
                    )
                }
            }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao direcionar pedido.") } }
    }

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(private val repository: OperatingUnitRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = DeliveryUnitTriageViewModel(repository) as T
    }
}
