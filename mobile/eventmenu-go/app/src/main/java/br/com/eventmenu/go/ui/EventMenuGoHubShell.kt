package br.com.eventmenu.go.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PointOfSale
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel as composeViewModel
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.HubViewModel
import br.com.eventmenu.go.MainViewModel
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.ui.screens.HubDialog

@Composable
fun EventMenuGoHubShell(
    viewModel: MainViewModel,
    onScan: ((String) -> Unit) -> Unit,
    onBiometric: () -> Unit,
    onTapOn: (TapOnRequest, (String?) -> Unit) -> Unit,
) {
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val state by viewModel.state.collectAsState()
    val hubViewModel: HubViewModel = composeViewModel(factory = HubViewModel.Factory(app.hubRepository))
    val hubState by hubViewModel.state.collectAsState()
    var showHub by remember { mutableStateOf(false) }

    val hubAwareScan: (((String) -> Unit) -> Unit) = { fallback ->
        onScan { raw ->
            val value = raw.trim()
            if (value.startsWith("EVENTMENU:HUB:", ignoreCase = true)) {
                hubViewModel.claimPairing(value, "Celular EventMenu GO")
                showHub = true
            } else {
                fallback(value)
            }
        }
    }

    Box(Modifier.fillMaxSize()) {
        EventMenuGoApp(
            viewModel = viewModel,
            onScan = hubAwareScan,
            onBiometric = onBiometric,
            onTapOn = onTapOn,
        )

        val canShowHub = state.session != null &&
            state.workShift?.status == "open" &&
            state.screen == AppScreen.PROFILE

        if (canShowHub && !showHub) {
            ExtendedFloatingActionButton(
                onClick = { showHub = true },
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .padding(end = 16.dp, bottom = 96.dp),
                icon = { Icon(Icons.Default.PointOfSale, contentDescription = null) },
                text = { Text("Hub") },
            )
        }
    }

    if (showHub) {
        val permissions = state.session?.permissions.orEmpty()
        HubDialog(
            state = hubState,
            canPrintOrder = permissions.any { it in setOf("orders_view", "orders_create", "orders_manage") },
            canPrintReceipt = permissions.any { it in setOf("payments", "orders_view", "orders_manage") },
            canShowCustomer = permissions.any { it in setOf("orders_view", "orders_create", "orders_manage") },
            canSendAlert = permissions.any { it in setOf("orders_view", "orders_create", "orders_manage", "orders_kitchen", "orders_dispatch") },
            canTerminalRequest = "terminal_request" in permissions || "terminal_collect" in permissions,
            canOpenDrawer = "cash" in permissions,
            onDismiss = { showHub = false; hubViewModel.clearFeedback() },
            onRefresh = hubViewModel::refresh,
            onSelectLink = hubViewModel::select,
            onSelectTerminal = hubViewModel::selectTerminal,
            onLoadTerminals = hubViewModel::loadTerminals,
            onPairCode = hubViewModel::claimPairing,
            onScanPairing = {
                onScan { value ->
                    hubViewModel.claimPairing(value.trim(), "Celular EventMenu GO")
                    showHub = true
                }
            },
            onPrintOrder = hubViewModel::printOrder,
            onPrintReceipt = hubViewModel::printReceipt,
            onOpenDrawer = hubViewModel::openDrawer,
            onShowCustomer = hubViewModel::showCustomerDisplay,
            onChargeTef = hubViewModel::chargeTef,
            onAlert = hubViewModel::playAlert,
            onRevoke = hubViewModel::revokeSelected,
        )
    }
}
