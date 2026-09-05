package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.DeliveryCashBalance
import br.com.eventmenu.go.data.EventMenuRepository
import br.com.eventmenu.go.data.ShiftMethodTotal
import br.com.eventmenu.go.data.ShiftOrderSummary
import br.com.eventmenu.go.data.ShiftSummary
import br.com.eventmenu.go.data.WorkShift
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import org.json.JSONObject

data class ProfileSummaryState(
    val summary: ShiftSummary? = null,
    val loading: Boolean = false,
    val error: String? = null,
)

class ProfileSummaryViewModel(private val repo: EventMenuRepository) : ViewModel() {
    private val _state = MutableStateFlow(ProfileSummaryState())
    val state: StateFlow<ProfileSummaryState> = _state.asStateFlow()
    private var boundShiftId: Int? = null

    fun bindShift(shiftId: Int?) {
        if (shiftId == null) {
            boundShiftId = null
            _state.value = ProfileSummaryState()
            return
        }
        if (boundShiftId == shiftId && _state.value.summary != null) return
        boundShiftId = shiftId
        refresh()
    }

    fun refresh() = viewModelScope.launch {
        if (boundShiftId == null) return@launch
        _state.update { it.copy(loading = true, error = null) }
        runCatching { parse(repo.shiftSummary()) }
            .onSuccess { summary -> _state.update { it.copy(summary = summary, loading = false) } }
            .onFailure { error -> _state.update { it.copy(loading = false, error = error.message ?: "Falha ao carregar resumo do turno.") } }
    }

    private fun parse(json: JSONObject): ShiftSummary {
        val shiftJson = json.optJSONObject("shift")
        val shift = shiftJson?.let {
            WorkShift(
                id = it.optInt("id"),
                mode = it.optString("mode"),
                status = it.optString("status"),
                startedAt = it.optString("started_at"),
                endedAt = it.optString("ended_at").takeIf(String::isNotBlank),
            )
        }
        val methods = buildList {
            val array = json.optJSONArray("by_method")
            if (array != null) for (i in 0 until array.length()) {
                val item = array.optJSONObject(i) ?: continue
                add(
                    ShiftMethodTotal(
                        method = item.optString("method"),
                        direction = item.optString("direction"),
                        qty = item.optInt("qty"),
                        totalCents = item.optInt("total_cents"),
                    )
                )
            }
        }
        val ordersJson = json.optJSONObject("orders") ?: JSONObject()
        val deliveryJson = json.optJSONObject("delivery_cash")
        val delivery = deliveryJson?.let {
            DeliveryCashBalance(
                shiftId = it.optInt("shift_id"),
                cashCollectedCents = it.optInt("cash_collected_cents"),
                confirmedHandoffCents = it.optInt("confirmed_handoff_cents"),
                outstandingCents = it.optInt("outstanding_cents"),
            )
        }
        return ShiftSummary(
            shift = shift,
            userName = shiftJson?.optString("user_name").orEmpty(),
            orders = ShiftOrderSummary(ordersJson.optInt("qty"), ordersJson.optInt("total_cents")),
            byMethod = methods,
            deliveryCash = delivery,
        )
    }

    class Factory(private val repo: EventMenuRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = ProfileSummaryViewModel(repo) as T
    }
}
