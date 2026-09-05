package br.com.eventmenu.go.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Badge
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.DeliveryDining
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.PointOfSale
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.MainViewModel
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.ui.screens.CashScreen
import br.com.eventmenu.go.ui.screens.DeliveryScreen
import br.com.eventmenu.go.ui.screens.EmployeeProfileScreen
import br.com.eventmenu.go.ui.screens.EventsScreen
import br.com.eventmenu.go.ui.screens.HomeScreen
import br.com.eventmenu.go.ui.screens.LoginScreen
import br.com.eventmenu.go.ui.screens.ModePickerScreen
import br.com.eventmenu.go.ui.screens.OrdersScreen
import br.com.eventmenu.go.ui.screens.QrResultDialog
import br.com.eventmenu.go.ui.screens.ShiftStartScreen

@Composable
fun EventMenuGoApp(viewModel: MainViewModel, onScan: () -> Unit, onBiometric: () -> Unit, onTapOn: (TapOnRequest) -> Unit) {
    val state by viewModel.state.collectAsState()
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(state.error, state.message) {
        (state.error ?: state.message)?.let { snackbar.showSnackbar(it) }
        if (state.error != null || state.message != null) viewModel.clearFeedback()
    }
    LaunchedEffect(state.tapOnRequest) {
        state.tapOnRequest?.let { request -> onTapOn(request); viewModel.tapOnLaunchConsumed() }
    }

    if (state.session == null) {
        LoginScreen(state, viewModel::login, viewModel::unlockWithPin, onBiometric)
        return
    }
    if (state.mode == null) {
        ModePickerScreen(state.session!!.user.name, state.modes, viewModel::chooseMode)
        return
    }
    if (state.workShift == null || state.workShift?.status != "open") {
        ShiftStartScreen(
            state = state,
            onStart = viewModel::startShift,
            onChangeMode = if (state.modes.size > 1) ({ viewModel.chooseMode(state.modes.first { it != state.mode }); }) else null,
            onLogout = viewModel::logout,
        )
        return
    }

    val permissions = state.session!!.permissions
    val mode = state.mode!!
    val nav = buildList {
        add(AppScreen.HOME)
        if (mode == AppMode.OPERATION || mode == AppMode.DELIVERY) add(AppScreen.ORDERS)
        if ("cash" in permissions) add(AppScreen.CASH)
        if (mode == AppMode.DELIVERY || "delivery_assign" in permissions) add(AppScreen.DELIVERY)
        if (mode == AppMode.EVENTS) add(AppScreen.EVENTS)
        add(AppScreen.PROFILE)
    }.distinct()

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        floatingActionButton = { FloatingActionButton(onClick = onScan) { Icon(Icons.Default.QrCodeScanner, contentDescription = "Escanear") } },
        bottomBar = {
            NavigationBar {
                nav.forEach { screen ->
                    val icon = when (screen) {
                        AppScreen.HOME -> Icons.Default.Home
                        AppScreen.ORDERS -> Icons.Default.ReceiptLong
                        AppScreen.CASH -> Icons.Default.PointOfSale
                        AppScreen.DELIVERY -> Icons.Default.DeliveryDining
                        AppScreen.EVENTS -> Icons.Default.ConfirmationNumber
                        AppScreen.PROFILE -> Icons.Default.Badge
                    }
                    NavigationBarItem(selected = state.screen == screen, onClick = { viewModel.navigate(screen) }, icon = { Icon(icon, contentDescription = screen.name) })
                }
            }
        }
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            when (state.screen) {
                AppScreen.HOME -> HomeScreen(state, viewModel::refreshOrders)
                AppScreen.ORDERS -> OrdersScreen(state.orders, viewModel::refreshOrders, viewModel::changeOrderStatus)
                AppScreen.CASH -> CashScreen(state.cashOpen, viewModel::openCash, viewModel::closeCash)
                AppScreen.DELIVERY -> DeliveryScreen(state.orders, viewModel::changeOrderStatus, viewModel::requestPix, viewModel::requestNfc)
                AppScreen.EVENTS -> EventsScreen(onScan)
                AppScreen.PROFILE -> EmployeeProfileScreen(state, viewModel::savePin, viewModel::setBiometric, viewModel::closeShift, viewModel::logout)
            }
            if (state.loading) CircularProgressIndicator(Modifier.align(Alignment.Center))
        }
    }

    state.qr?.let { QrResultDialog(it, viewModel::clearQr, viewModel::checkInCurrentQr) }
}
