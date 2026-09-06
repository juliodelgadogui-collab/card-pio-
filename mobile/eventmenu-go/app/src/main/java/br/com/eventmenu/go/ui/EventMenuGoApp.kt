package br.com.eventmenu.go.ui

import android.content.Intent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Assessment
import androidx.compose.material.icons.filled.Badge as BadgeIcon
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.DeliveryDining
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.PointOfSale
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material.icons.filled.Restaurant
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.viewmodel.compose.viewModel as composeViewModel
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.DeliveryUnitTriageViewModel
import br.com.eventmenu.go.DeviceStatusViewModel
import br.com.eventmenu.go.EventBarViewModel
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.MainViewModel
import br.com.eventmenu.go.ManagerActionsViewModel
import br.com.eventmenu.go.PrinterViewModel
import br.com.eventmenu.go.ProfileSummaryViewModel
import br.com.eventmenu.go.ReceiptViewModel
import br.com.eventmenu.go.ShiftUnitViewModel
import br.com.eventmenu.go.TabSplitPaymentViewModel
import br.com.eventmenu.go.UniversalQrViewModel
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.notifications.OperationNotificationScheduler
import br.com.eventmenu.go.printing.BluetoothEscPosPrinter
import br.com.eventmenu.go.printing.PrinterPreferences
import br.com.eventmenu.go.ui.screens.CashOperationsScreen
import br.com.eventmenu.go.ui.screens.DeliveryOperationsScreen
import br.com.eventmenu.go.ui.screens.DispatchScreen
import br.com.eventmenu.go.ui.screens.EmployeeProfileScreen
import br.com.eventmenu.go.ui.screens.EventBarScreen
import br.com.eventmenu.go.ui.screens.EventModeScreen
import br.com.eventmenu.go.ui.screens.KitchenScreen
import br.com.eventmenu.go.ui.screens.LoginScreen
import br.com.eventmenu.go.ui.screens.ManagerScreen
import br.com.eventmenu.go.ui.screens.ModePickerScreen
import br.com.eventmenu.go.ui.screens.MyEmployeeQrDialog
import br.com.eventmenu.go.ui.screens.NotificationsScreen
import br.com.eventmenu.go.ui.screens.OrdersScreen
import br.com.eventmenu.go.ui.screens.PosScreen
import br.com.eventmenu.go.ui.screens.QrResultDialog
import br.com.eventmenu.go.ui.screens.RoleDashboardScreen
import br.com.eventmenu.go.ui.screens.TabSplitPaymentScreen
import br.com.eventmenu.go.ui.screens.TableAccountScreen
import br.com.eventmenu.go.ui.screens.TablesScreen
import br.com.eventmenu.go.ui.screens.UnitShiftStartScreen
import br.com.eventmenu.go.ui.screens.UniversalQrResultDialog
import kotlinx.coroutines.delay

@Composable
fun EventMenuGoApp(
    viewModel: MainViewModel,
    onScan: ((String) -> Unit) -> Unit,
    onBiometric: () -> Unit,
    onTapOn: (TapOnRequest, (String?) -> Unit) -> Unit,
) {
    val state by viewModel.state.collectAsState()
    val snackbar = remember { SnackbarHostState() }
    val context = LocalContext.current
    val app = context.applicationContext as EventMenuGoApplication
    val profileViewModel: ProfileSummaryViewModel = composeViewModel(factory = ProfileSummaryViewModel.Factory(app.repository))
    val profileState by profileViewModel.state.collectAsState()
    val managerActionsViewModel: ManagerActionsViewModel = composeViewModel(factory = ManagerActionsViewModel.Factory(app.managerRepository))
    val managerActionState by managerActionsViewModel.state.collectAsState()
    val receiptViewModel: ReceiptViewModel = composeViewModel(factory = ReceiptViewModel.Factory(app.receiptRepository))
    val receiptState by receiptViewModel.state.collectAsState()
    val deviceViewModel: DeviceStatusViewModel = composeViewModel(factory = DeviceStatusViewModel.Factory(app.deviceStatusRepository))
    val deviceState by deviceViewModel.state.collectAsState()
    val eventBarViewModel: EventBarViewModel = composeViewModel(factory = EventBarViewModel.Factory(app.eventBarRepository))
    val eventBarState by eventBarViewModel.state.collectAsState()
    val universalQrViewModel: UniversalQrViewModel = composeViewModel(factory = UniversalQrViewModel.Factory(app.universalQrRepository))
    val universalQrState by universalQrViewModel.state.collectAsState()
    val tabSplitViewModel: TabSplitPaymentViewModel = composeViewModel(factory = TabSplitPaymentViewModel.Factory(app.tabSplitPaymentRepository))
    val tabSplitState by tabSplitViewModel.state.collectAsState()
    val shiftUnitViewModel: ShiftUnitViewModel = composeViewModel(factory = ShiftUnitViewModel.Factory(app.operatingUnitRepository))
    val shiftUnitState by shiftUnitViewModel.state.collectAsState()
    val triageViewModel: DeliveryUnitTriageViewModel = composeViewModel(factory = DeliveryUnitTriageViewModel.Factory(app.operatingUnitRepository))
    val triageState by triageViewModel.state.collectAsState()
    val printerPreferences = remember(app) { PrinterPreferences(app) }
    val bluetoothPrinter = remember(app) { BluetoothEscPosPrinter(app, printerPreferences) }
    val printerViewModel: PrinterViewModel = composeViewModel(factory = PrinterViewModel.Factory(printerPreferences, bluetoothPrinter, app.receiptRepository))
    val printerState by printerViewModel.state.collectAsState()
    val paymentBaseline = remember { mutableStateMapOf<Int, String>() }
    var baselineShiftId by remember { mutableStateOf<Int?>(null) }
    var pendingDeliveryQrOrderId by remember { mutableStateOf<Int?>(null) }

    fun startScan() {
        onScan { value ->
            val trimmed = value.trim()
            val universal = trimmed.contains("EVENTMENU:QR:", ignoreCase = true) ||
                Regex("^[A-Fa-f0-9]{64}$").matches(trimmed) ||
                Regex("[?&](?:qr|token|t)=[A-Fa-f0-9]{64}(?:&|$)", RegexOption.IGNORE_CASE).containsMatchIn(trimmed)
            if (universal) universalQrViewModel.resolve(trimmed) else viewModel.resolveQr(trimmed)
        }
    }

    LaunchedEffect(state.error, state.message) {
        (state.error ?: state.message)?.let { snackbar.showSnackbar(it) }
        if (state.error != null || state.message != null) viewModel.clearFeedback()
    }
    LaunchedEffect(shiftUnitState.error) {
        shiftUnitState.error?.let { snackbar.showSnackbar(it); shiftUnitViewModel.clearError() }
    }
    LaunchedEffect(triageState.error, triageState.message) {
        (triageState.error ?: triageState.message)?.let { snackbar.showSnackbar(it) }
        if (triageState.error != null || triageState.message != null) triageViewModel.clearFeedback()
    }
    LaunchedEffect(eventBarState.error, eventBarState.message) {
        (eventBarState.error ?: eventBarState.message)?.let { snackbar.showSnackbar(it) }
        if (eventBarState.error != null || eventBarState.message != null) eventBarViewModel.clearFeedback()
    }
    LaunchedEffect(tabSplitState.error, tabSplitState.message) {
        (tabSplitState.error ?: tabSplitState.message)?.let { snackbar.showSnackbar(it) }
        if (tabSplitState.error != null || tabSplitState.message != null) tabSplitViewModel.clearFeedback()
    }
    LaunchedEffect(universalQrState.error, universalQrState.message) {
        (universalQrState.error ?: universalQrState.message)?.let { snackbar.showSnackbar(it) }
        if (universalQrState.error != null || universalQrState.message != null) universalQrViewModel.clearFeedback()
    }
    LaunchedEffect(managerActionState.error, managerActionState.message) {
        (managerActionState.error ?: managerActionState.message)?.let { snackbar.showSnackbar(it) }
        if (managerActionState.error != null || managerActionState.message != null) managerActionsViewModel.clearFeedback()
    }
    LaunchedEffect(receiptState.error) {
        receiptState.error?.let { snackbar.showSnackbar(it); receiptViewModel.clearError() }
    }
    LaunchedEffect(deviceState.error) {
        deviceState.error?.let { snackbar.showSnackbar(it); deviceViewModel.clearError() }
    }
    LaunchedEffect(printerState.error, printerState.message) {
        (printerState.error ?: printerState.message)?.let { snackbar.showSnackbar(it) }
        if (printerState.error != null || printerState.message != null) printerViewModel.clearFeedback()
    }
    LaunchedEffect(receiptState.shareText) {
        receiptState.shareText?.let { text ->
            val share = Intent(Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(Intent.EXTRA_SUBJECT, "Comprovante EventMenu")
                putExtra(Intent.EXTRA_TEXT, text)
            }
            context.startActivity(Intent.createChooser(share, "Enviar comprovante"))
            receiptViewModel.consumed()
        }
    }
    LaunchedEffect(state.session?.user?.id, state.workShift?.id) {
        if (state.session != null && state.workShift == null) shiftUnitViewModel.load()
    }
    LaunchedEffect(shiftUnitState.completedVersion) {
        if (shiftUnitState.completedVersion > 0) viewModel.restoreSession()
    }
    LaunchedEffect(state.notifications) {
        OperationNotificationScheduler.showUnread(context, state.notifications)
    }
    LaunchedEffect(state.workShift?.id) {
        if (state.workShift?.status != "open") return@LaunchedEffect
        while (true) {
            delay(10_000)
            viewModel.refreshNotifications()
        }
    }
    LaunchedEffect(state.tapOnRequest) {
        state.tapOnRequest?.let { request ->
            onTapOn(request) { transactionCode ->
                if (transactionCode.isNullOrBlank()) viewModel.tapOnCancelled() else viewModel.verifyTapOn(request, transactionCode)
            }
            viewModel.tapOnLaunchConsumed()
        }
    }
    LaunchedEffect(eventBarState.tapOnRequest) {
        eventBarState.tapOnRequest?.let { request ->
            onTapOn(request) { transactionCode ->
                if (transactionCode.isNullOrBlank()) eventBarViewModel.nfcCancelled() else eventBarViewModel.verifyNfc(request, transactionCode)
            }
            eventBarViewModel.consumeTapOnLaunch()
        }
    }
    LaunchedEffect(tabSplitState.tapOnRequest) {
        tabSplitState.tapOnRequest?.let { request ->
            onTapOn(request) { transactionCode ->
                if (transactionCode.isNullOrBlank()) tabSplitViewModel.nfcCancelled() else tabSplitViewModel.verifyNfc(request, transactionCode)
            }
            tabSplitViewModel.consumeTapOnLaunch()
        }
    }
    LaunchedEffect(eventBarState.completedVersion) {
        if (eventBarState.completedVersion > 0) viewModel.refreshEvents()
    }
    LaunchedEffect(tabSplitState.paidVersion) {
        if (tabSplitState.paidVersion > 0) {
            viewModel.refreshTableAccount()
            viewModel.refreshTables()
            viewModel.refreshCash()
            viewModel.refreshOrders()
        }
    }
    LaunchedEffect(eventBarState.order?.id, eventBarState.balance?.remainingCents) {
        val orderId = eventBarState.order?.id ?: return@LaunchedEffect
        if (eventBarState.balance?.remainingCents == 0) printerViewModel.autoPrintReceipt(orderId)
    }
    LaunchedEffect(state.workShift?.id) {
        val shiftId = state.workShift?.id
        baselineShiftId = shiftId
        paymentBaseline.clear()
        pendingDeliveryQrOrderId = null
        state.orders.forEach { paymentBaseline[it.id] = it.paymentStatus }
        profileViewModel.bindShift(shiftId)
    }
    LaunchedEffect(state.orders, state.workShift?.id) {
        val shiftId = state.workShift?.id ?: return@LaunchedEffect
        if (baselineShiftId != shiftId) return@LaunchedEffect
        state.orders.forEach { order ->
            val previous = paymentBaseline[order.id]
            if (previous != null && previous != "paid" && order.paymentStatus == "paid") printerViewModel.autoPrintReceipt(order.id)
            paymentBaseline[order.id] = order.paymentStatus
        }
        val visibleIds = state.orders.mapTo(mutableSetOf()) { it.id }
        paymentBaseline.keys.toList().filter { it !in visibleIds }.forEach(paymentBaseline::remove)
    }
    LaunchedEffect(state.posOrder?.id, state.paymentBalance?.remainingCents) {
        val orderId = state.posOrder?.id ?: return@LaunchedEffect
        if (state.paymentBalance?.remainingCents == 0) printerViewModel.autoPrintReceipt(orderId)
    }
    LaunchedEffect(state.screen, state.workShift?.id) {
        if (state.screen == AppScreen.PROFILE && state.workShift?.id != null) {
            profileViewModel.refresh(); deviceViewModel.refresh(); printerViewModel.refresh()
        }
        if (state.screen == AppScreen.MANAGER && state.workShift?.id != null) managerActionsViewModel.refresh()
        if (state.screen == AppScreen.DISPATCH && state.workShift?.id != null && ("delivery_assign" in state.session?.permissions.orEmpty() || "orders_manage" in state.session?.permissions.orEmpty())) triageViewModel.refresh()
    }
    LaunchedEffect(managerActionState.changeVersion) {
        if (managerActionState.changeVersion > 0) {
            viewModel.refreshManager(); viewModel.refreshOrders(); viewModel.refreshNotifications()
        }
    }
    LaunchedEffect(triageState.changedVersion) {
        if (triageState.changedVersion > 0) {
            viewModel.refreshOrders()
            viewModel.refreshDispatch()
            viewModel.refreshManager()
            viewModel.refreshNotifications()
        }
    }

    if (state.session == null) { LoginScreen(state, viewModel::login, viewModel::unlockWithPin, onBiometric); return }
    if (state.mode == null) { ModePickerScreen(state.session!!.user.name, state.modes, viewModel::chooseMode); return }
    if (state.workShift == null || state.workShift?.status != "open") {
        UnitShiftStartScreen(
            state = state,
            unitState = shiftUnitState,
            onSelectUnit = shiftUnitViewModel::select,
            onStart = { state.mode?.let(shiftUnitViewModel::open) },
            onChangeMode = if (state.modes.size > 1) ({ viewModel.chooseMode(state.modes.first { it != state.mode }) }) else null,
            onLogout = viewModel::logout,
        )
        return
    }

    val session = state.session!!
    val permissions = session.permissions
    val mode = state.mode!!
    val barActive = eventBarState.eventId != null
    val splitActive = tabSplitState.tabId != null
    val focusedFlow = barActive || splitActive
    val nav = buildList {
        add(AppScreen.HOME); add(AppScreen.NOTIFICATIONS)
        if ("reports" in permissions && mode in setOf(AppMode.OPERATION, AppMode.PAY)) add(AppScreen.MANAGER)
        if ("orders_create" in permissions && mode != AppMode.EVENTS) add(AppScreen.POS)
        if (mode == AppMode.OPERATION && "tables" in permissions) add(AppScreen.TABLES)
        if (mode == AppMode.OPERATION && "orders_kitchen" in permissions) add(AppScreen.KITCHEN)
        if (mode == AppMode.OPERATION && ("orders_dispatch" in permissions || "delivery_assign" in permissions)) add(AppScreen.DISPATCH)
        if (mode == AppMode.DELIVERY || (mode != AppMode.EVENTS && ("orders_view" in permissions || "orders_create" in permissions || "orders_manage" in permissions))) add(AppScreen.ORDERS)
        if ("cash" in permissions) add(AppScreen.CASH)
        if (mode == AppMode.DELIVERY) add(AppScreen.DELIVERY)
        if (mode == AppMode.EVENTS) add(AppScreen.EVENTS)
        add(AppScreen.PROFILE)
    }.distinct()

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        floatingActionButton = { if (!focusedFlow) FloatingActionButton(onClick = ::startScan) { Icon(Icons.Default.QrCodeScanner, contentDescription = "Escanear") } },
        bottomBar = {
            if (!focusedFlow) NavigationBar {
                nav.forEach { screen ->
                    val icon = when (screen) {
                        AppScreen.HOME -> Icons.Default.Home
                        AppScreen.NOTIFICATIONS -> Icons.Default.Notifications
                        AppScreen.MANAGER -> Icons.Default.Assessment
                        AppScreen.POS -> Icons.Default.ShoppingCart
                        AppScreen.TABLES, AppScreen.TABLE_ACCOUNT -> Icons.Default.Restaurant
                        AppScreen.ORDERS -> Icons.Default.ReceiptLong
                        AppScreen.KITCHEN -> Icons.Default.Restaurant
                        AppScreen.DISPATCH -> Icons.Default.DeliveryDining
                        AppScreen.CASH -> Icons.Default.PointOfSale
                        AppScreen.DELIVERY -> Icons.Default.DeliveryDining
                        AppScreen.EVENTS -> Icons.Default.ConfirmationNumber
                        AppScreen.PROFILE -> Icons.Default.BadgeIcon
                    }
                    NavigationBarItem(
                        selected = state.screen == screen,
                        onClick = { viewModel.navigate(screen) },
                        icon = {
                            if (screen == AppScreen.NOTIFICATIONS && state.unreadNotifications > 0) {
                                BadgedBox(badge = { Badge { Text(if (state.unreadNotifications > 99) "99+" else state.unreadNotifications.toString()) } }) { Icon(icon, contentDescription = screen.name) }
                            } else Icon(icon, contentDescription = screen.name)
                        },
                    )
                }
            }
        },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            when {
                barActive -> EventBarScreen(
                    state = eventBarState,
                    cashOpen = state.cashOpen,
                    canCash = "payments" in permissions && "cash" in permissions,
                    canPix = "payments" in permissions,
                    canNfc = "nfc_collect" in permissions,
                    onAdd = eventBarViewModel::add,
                    onRemove = eventBarViewModel::remove,
                    onClearCart = eventBarViewModel::clearCart,
                    onCreate = eventBarViewModel::create,
                    onCash = eventBarViewModel::payCash,
                    onPix = eventBarViewModel::requestPix,
                    onNfc = eventBarViewModel::requestNfc,
                    onRefreshPayment = eventBarViewModel::refreshPayment,
                    onPollPix = eventBarViewModel::pollPix,
                    onDismissPix = eventBarViewModel::dismissPix,
                    onReceipt = receiptViewModel::prepare,
                    onPrint = printerViewModel::printReceipt,
                    onFinish = eventBarViewModel::finishSale,
                    onExit = eventBarViewModel::close,
                )
                splitActive -> TabSplitPaymentScreen(
                    state = tabSplitState,
                    cashOpen = state.cashOpen,
                    canCash = "payments" in permissions && "cash" in permissions,
                    canPix = "payments" in permissions,
                    canNfc = "nfc_collect" in permissions,
                    onValue = tabSplitViewModel::startValue,
                    onPercentage = tabSplitViewModel::startPercentage,
                    onPerson = tabSplitViewModel::startPerson,
                    onProducts = tabSplitViewModel::startProducts,
                    onPollPix = tabSplitViewModel::pollPix,
                    onHidePix = tabSplitViewModel::hidePix,
                    onShowPix = tabSplitViewModel::showPix,
                    onCancelGroup = tabSplitViewModel::cancelGroup,
                    onReceiptGroup = receiptViewModel::prepareGroup,
                    onPrintGroup = printerViewModel::printGroupReceipt,
                    onRefresh = tabSplitViewModel::refresh,
                    onBack = {
                        tabSplitViewModel.close()
                        viewModel.refreshTableAccount()
                    },
                )
                else -> when (state.screen) {
                    AppScreen.HOME -> RoleDashboardScreen(
                        state = state,
                        onNavigate = viewModel::navigate,
                        onScan = ::startScan,
                        onRefresh = {
                            viewModel.refreshOrders()
                            viewModel.refreshCash()
                            viewModel.refreshNotifications()
                            if (mode == AppMode.EVENTS) viewModel.refreshEvents()
                            if (mode == AppMode.DELIVERY) viewModel.refreshDeliveryCash()
                            if ("orders_kitchen" in permissions) viewModel.refreshKitchen()
                            if ("reports" in permissions) viewModel.refreshManager()
                        },
                    )
                    AppScreen.NOTIFICATIONS -> NotificationsScreen(state.notifications, state.unreadNotifications, viewModel::markNotificationRead, viewModel::markAllNotificationsRead, viewModel::refreshNotifications)
                    AppScreen.MANAGER -> ManagerScreen(
                        overview = state.managerOverview,
                        details = managerActionState.details,
                        loading = managerActionState.loading,
                        canTransferDelivery = "delivery_assign" in permissions,
                        canCancelOrder = "orders_manage" in permissions,
                        onTransferDelivery = managerActionsViewModel::transferDelivery,
                        onCancelOrder = managerActionsViewModel::cancelOrder,
                        onRefresh = { viewModel.refreshManager(); managerActionsViewModel.refresh() },
                    )
                    AppScreen.POS -> PosScreen(state, viewModel::addProduct, viewModel::removeProduct, viewModel::clearCart, viewModel::createPosOrder, viewModel::payPosCash, viewModel::requestPosPix, viewModel::requestPosNfc, receiptViewModel::prepare, { printerViewModel.printReceipt(it) }, viewModel::refreshPosPayment, viewModel::finishPosFlow, viewModel::clearSelectedTable)
                    AppScreen.TABLES -> TablesScreen(state.tables, "orders_create" in permissions, viewModel::refreshTables, viewModel::openTable, viewModel::closeTable, viewModel::orderForTable, viewModel::openTableAccount)
                    AppScreen.TABLE_ACCOUNT -> TableAccountScreen(
                        account = state.tableAccount,
                        canReceive = "payments" in permissions,
                        onReceive = viewModel::receiveTableOrder,
                        onSplit = tabSplitViewModel::open,
                        onRefresh = viewModel::refreshTableAccount,
                        onBack = viewModel::closeTableAccount,
                    )
                    AppScreen.ORDERS -> OrdersScreen(state.orders, viewModel::refreshOrders, viewModel::changeOrderStatus)
                    AppScreen.KITCHEN -> KitchenScreen(state.kitchenTickets, viewModel::refreshKitchen, viewModel::kitchenStatus)
                    AppScreen.DISPATCH -> DispatchScreen(
                        orders = state.orders,
                        deliveryUsers = state.deliveryUsers,
                        canAssignDelivery = "delivery_assign" in permissions,
                        focusOrderId = state.dispatchFocusOrderId,
                        unassignedUnitOrders = triageState.orders,
                        units = triageState.units,
                        canRouteUnit = "delivery_assign" in permissions || "orders_manage" in permissions,
                        onRefresh = { viewModel.refreshDispatch(); triageViewModel.refresh() },
                        onDispatch = viewModel::dispatchReady,
                        onAssignDelivery = viewModel::assignDelivery,
                        onScanDelivery = { orderId -> pendingDeliveryQrOrderId = orderId; startScan() },
                        onAssignUnit = triageViewModel::assign,
                    )
                    AppScreen.CASH -> CashOperationsScreen(state.cashOpen, state.cashSummary, viewModel::openCash, viewModel::addCashSupply, viewModel::addCashWithdrawal, viewModel::closeCash, viewModel::refreshCash)
                    AppScreen.DELIVERY -> DeliveryOperationsScreen(state.orders, state.pixCharge, viewModel::changeOrderStatus, viewModel::requestPix, viewModel::requestNfc, viewModel::collectDeliveryCash, receiptViewModel::prepare, { printerViewModel.printReceipt(it) }, viewModel::pollPixStatus, viewModel::dismissPix)
                    AppScreen.EVENTS -> EventModeScreen(
                        events = state.events,
                        selectedEventId = state.selectedEventId,
                        entries = state.eventEntries,
                        canScanTicket = "tickets" in permissions,
                        canScanGuest = "guests" in permissions,
                        canUseBar = "event_bar" in permissions,
                        onSelect = viewModel::selectEvent,
                        onOpenBar = { eventId -> state.events.firstOrNull { it.id == eventId }?.let { eventBarViewModel.open(it.id, it.name) } },
                        onRefresh = viewModel::refreshEvents,
                        onScan = ::startScan,
                    )
                    AppScreen.PROFILE -> EmployeeProfileScreen(
                        state = state,
                        shiftSummary = profileState.summary,
                        summaryLoading = profileState.loading,
                        onRefreshSummary = profileViewModel::refresh,
                        onGenerateMyQr = { universalQrViewModel.issueSelf(session.user.id, session.user.name, state.workShift?.mode == "delivery") },
                        deviceState = deviceState,
                        onRefreshDevice = deviceViewModel::refresh,
                        printerState = printerState,
                        onPrinterRefresh = printerViewModel::refresh,
                        onSelectPrinter = printerViewModel::selectDevice,
                        onClearPrinter = printerViewModel::clearDevice,
                        onPrinterEnabled = printerViewModel::setEnabled,
                        onPrinterAutoPrint = printerViewModel::setAutoPrint,
                        onPrinterPaperWidth = printerViewModel::setPaperWidth,
                        onPrinterTest = printerViewModel::printTest,
                        onSavePin = viewModel::savePin,
                        onBiometric = viewModel::setBiometric,
                        onCloseShift = viewModel::closeShift,
                        onCreateHandoff = viewModel::createCashHandoff,
                        onDismissHandoff = viewModel::dismissCashHandoff,
                        onRefreshDeliveryCash = viewModel::refreshDeliveryCash,
                        onLogout = viewModel::logout,
                    )
                }
            }
            if (state.loading || shiftUnitState.loading || triageState.loading || eventBarState.loading || tabSplitState.loading || universalQrState.loading || receiptState.loading || printerState.loading || deviceState.loading) {
                CircularProgressIndicator(Modifier.align(Alignment.Center))
            }
        }
    }

    if (!focusedFlow) state.qr?.let { QrResultDialog(it, viewModel::clearQr, viewModel::processCurrentQr) }

    universalQrState.issued?.let { issued ->
        MyEmployeeQrDialog(
            qr = issued,
            onRevoke = { universalQrViewModel.revokeSelf(session.user.id, issued.type == "delivery_user") },
            onDismiss = universalQrViewModel::clearIssued,
        )
    }

    universalQrState.resolved?.let { result ->
        val pendingOrder = pendingDeliveryQrOrderId
        val table = if (result.type == "tab") state.tables.firstOrNull { it.tabId == result.entityId } else null
        val actionLabel = when {
            result.type == "delivery_user" && pendingOrder != null -> "ATRIBUIR AO PEDIDO #$pendingOrder"
            result.type == "event" && mode == AppMode.EVENTS -> "ABRIR EVENTO"
            result.type == "tab" && table != null -> "ABRIR CONTA"
            else -> null
        }
        val actionEnabled = if (result.type == "delivery_user" && pendingOrder != null) result.deliveryShiftOpen else true
        val hint = when {
            result.type == "delivery_user" && pendingOrder != null && !result.deliveryShiftOpen -> "Este funcionário não está com turno Delivery aberto e não pode receber o pedido."
            result.type == "tab" && table == null -> "A comanda foi identificada, mas não está disponível no salão carregado deste turno."
            else -> ""
        }
        UniversalQrResultDialog(
            result = result,
            actionLabel = actionLabel,
            actionEnabled = actionEnabled,
            actionHint = hint,
            onAction = if (actionLabel == null) null else {
                {
                    when {
                        result.type == "delivery_user" && pendingOrder != null -> viewModel.assignDelivery(pendingOrder, result.entityId)
                        result.type == "event" && mode == AppMode.EVENTS -> viewModel.selectEvent(result.entityId)
                        result.type == "tab" && table != null -> viewModel.openTableAccount(table)
                    }
                    pendingDeliveryQrOrderId = null
                    universalQrViewModel.clearResolved()
                }
            },
            onDismiss = { pendingDeliveryQrOrderId = null; universalQrViewModel.clearResolved() },
        )
    }
}
