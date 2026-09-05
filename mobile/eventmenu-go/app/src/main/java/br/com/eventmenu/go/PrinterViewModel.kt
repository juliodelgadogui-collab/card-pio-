package br.com.eventmenu.go

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import br.com.eventmenu.go.data.ReceiptRepository
import br.com.eventmenu.go.printing.BluetoothEscPosPrinter
import br.com.eventmenu.go.printing.PrinterDevice
import br.com.eventmenu.go.printing.PrinterPreferences
import br.com.eventmenu.go.printing.PrinterSettings
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class PrinterState(
    val settings: PrinterSettings,
    val devices: List<PrinterDevice> = emptyList(),
    val hasPermission: Boolean = false,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
)

class PrinterViewModel(
    private val preferences: PrinterPreferences,
    private val printer: BluetoothEscPosPrinter,
    private val receipts: ReceiptRepository,
) : ViewModel() {
    private val _state = MutableStateFlow(PrinterState(preferences.load(), hasPermission = printer.hasConnectPermission()))
    val state: StateFlow<PrinterState> = _state.asStateFlow()

    fun refresh() = viewModelScope.launch {
        val permission = printer.hasConnectPermission()
        val devices = if (permission) withContext(Dispatchers.IO) { printer.bondedDevices() } else emptyList()
        _state.update { it.copy(settings = preferences.load(), devices = devices, hasPermission = permission) }
    }

    fun selectDevice(device: PrinterDevice) {
        preferences.setDevice(device.name, device.address)
        _state.update { it.copy(settings = preferences.load(), message = "Impressora ${device.name} selecionada.") }
    }

    fun clearDevice() {
        preferences.clearDevice()
        _state.update { it.copy(settings = preferences.load(), message = "Impressora removida deste aparelho.") }
    }

    fun setEnabled(enabled: Boolean) {
        preferences.setEnabled(enabled)
        _state.update { it.copy(settings = preferences.load()) }
    }

    fun setAutoPrint(enabled: Boolean) {
        preferences.setAutoPrint(enabled)
        _state.update { it.copy(settings = preferences.load()) }
    }

    fun setPaperWidth(widthMm: Int) {
        preferences.setPaperWidth(widthMm)
        _state.update { it.copy(settings = preferences.load(), message = "Papel configurado para ${if (widthMm == 58) 58 else 80} mm.") }
    }

    fun printText(text: String, successMessage: String = "Impressão enviada.") = viewModelScope.launch {
        _state.update { it.copy(loading = true, error = null) }
        runCatching { withContext(Dispatchers.IO) { printer.print(text) } }
            .onSuccess { _state.update { it.copy(message = successMessage) } }
            .onFailure { error -> _state.update { it.copy(error = error.message ?: "Falha ao imprimir.") } }
        _state.update { it.copy(loading = false) }
    }

    fun printReceipt(orderId: Int, automatic: Boolean = false) = viewModelScope.launch {
        if (orderId < 1) return@launch
        val settings = preferences.load()
        if (automatic) {
            if (!settings.enabled || !settings.autoPrint || !printer.hasConnectPermission() || preferences.wasAutoPrinted(orderId)) return@launch
        }
        _state.update { it.copy(loading = true, error = null) }
        runCatching {
            val text = receipts.shareText(receipts.order(orderId))
            withContext(Dispatchers.IO) { printer.print(text) }
        }.onSuccess {
            if (automatic) preferences.markAutoPrinted(orderId)
            _state.update { it.copy(message = if (automatic) "Comprovante #$orderId impresso automaticamente." else "Comprovante #$orderId impresso.") }
        }.onFailure { error ->
            _state.update { it.copy(error = error.message ?: "Falha ao imprimir comprovante.") }
        }
        _state.update { it.copy(loading = false) }
    }

    fun autoPrintReceipt(orderId: Int) = printReceipt(orderId, automatic = true)

    fun printTest() = printText(
        "EVENTMENU GO\nTESTE DE IMPRESSAO\n----------------\nImpressora configurada com sucesso.\n",
        "Teste enviado para a impressora.",
    )

    fun clearFeedback() = _state.update { it.copy(error = null, message = null) }

    class Factory(
        private val preferences: PrinterPreferences,
        private val printer: BluetoothEscPosPrinter,
        private val receipts: ReceiptRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = PrinterViewModel(preferences, printer, receipts) as T
    }
}
