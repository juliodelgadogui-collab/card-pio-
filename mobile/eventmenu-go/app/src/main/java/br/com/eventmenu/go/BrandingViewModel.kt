package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.AppBranding
import br.com.eventmenu.go.data.BrandingRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class BrandingState(
    val branding: AppBranding = AppBranding(),
    val loading: Boolean = false,
)

class BrandingViewModel(private val repository: BrandingRepository) : ViewModel() {
    private val _state = MutableStateFlow(BrandingState())
    val state: StateFlow<BrandingState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        _state.update { it.copy(loading = true) }
        runCatching { repository.current() }
            .onSuccess { branding -> _state.update { it.copy(branding = branding, loading = false) } }
            .onFailure { _state.update { it.copy(loading = false) } }
    }

    fun clear() = _state.update { BrandingState() }

    class Factory(private val repository: BrandingRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = BrandingViewModel(repository) as T
    }
}
