package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.OperatingUnit
import br.com.eventmenu.go.data.OperatingUnitRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ShiftUnitState(
    val tenantName: String = "",
    val units: List<OperatingUnit> = emptyList(),
    val selectedUnitId: Int? = null,
    val loading: Boolean = false,
    val error: String? = null,
    val completedVersion: Int = 0,
)

class ShiftUnitViewModel(private val repository: OperatingUnitRepository) : ViewModel() {
    private val _state = MutableStateFlow(ShiftUnitState())
    val state: StateFlow<ShiftUnitState> = _state.asStateFlow()

    fun load() = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.list() }
            .onSuccess { context ->
                val selected = context.units.firstOrNull { it.isDefault }?.id
                    ?: context.units.singleOrNull()?.id
                    ?: _state.value.selectedUnitId?.takeIf { id -> context.units.any { it.id == id } }
                _state.update { it.copy(tenantName = context.tenantName, units = context.units, selectedUnitId = selected, loading = false) }
            }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao carregar unidades.") } }
    }

    fun select(unitId: Int?) {
        if (unitId != null && _state.value.units.none { it.id == unitId }) return
        _state.update { it.copy(selectedUnitId = unitId, error = null) }
    }

    fun open(mode: AppMode) = viewModelScope.launch {
        val units = _state.value.units
        val unitId = _state.value.selectedUnitId
        if (units.size > 1 && unitId == null) {
            _state.update { it.copy(error = "Escolha a unidade antes de iniciar o turno.") }
            return@launch
        }
        _state.update { it.copy(loading = true, error = null) }
        runCatching { repository.openShift(mode, unitId) }
            .onSuccess { _state.update { it.copy(loading = false, completedVersion = it.completedVersion + 1) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao iniciar turno.") } }
    }

    fun clearError() = _state.update { it.copy(error = null) }

    class Factory(private val repository: OperatingUnitRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = ShiftUnitViewModel(repository) as T
    }
}
