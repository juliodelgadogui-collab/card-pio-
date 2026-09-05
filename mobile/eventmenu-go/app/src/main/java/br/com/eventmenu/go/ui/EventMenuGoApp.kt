package br.com.eventmenu.go.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Assessment
import androidx.compose.material.icons.filled.Badge
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
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.MainViewModel
import br.com.eventmenu.go.data.AppMode
import br.com.eventmenu.go.data.TapOnRequest
import br.com.eventmenu.go.ui.screens.CashOperationsScreen
import br.com.eventmenu.go.ui.screens.DeliveryOperationsScreen
import br.com.eventmenu.go.ui.screens.DispatchScreen
import br.com.eventmenu.go.ui.screens.EmployeeProfileScreen
import br.com.eventmenu.go.ui.screens.EventModeScreen
import br.com.eventmenu.go.ui.screens.HomeScreen
import br.com.eventmenu.go.ui.screens.KitchenScreen
import br.com.eventmenu.go.ui.screens.LoginScreen
import br.com.eventmenu.go.ui.screens.ManagerScreen
import br.com.eventmenu.go.ui.screens.ModePickerScreen
import br.com.eventmenu.go.ui.screens.NotificationsScreen
import br.com.eventmenu.go.ui.screens.OrdersScreen
import br.com.eventmenu.go.ui.screens.PosScreen
import br.com.eventmenu.go.ui.screens.QrResultDialog
import br.com.eventmenu.go.ui.screens.ShiftStartScreen
import br.com.eventmenu.go.ui.screens.TableAccountScreen
import br.com.eventmenu.go.ui.screens.TablesScreen

@Composable
fun EventMenuGoApp(viewModel: MainViewModel, onScan: () -> Unit, onBiometric: () -> Unit, onTapOn: (TapOnRequest) -> Unit) {
    val state by viewModel.state.collectAsState();val snackbar=remember{SnackbarHostState()}
    LaunchedEffect(state.error,state.message){(state.error?:state.message)?.let{snackbar.showSnackbar(it)};if(state.error!=null||state.message!=null)viewModel.clearFeedback()}
    LaunchedEffect(state.tapOnRequest){state.tapOnRequest?.let{request->onTapOn(request);viewModel.tapOnLaunchConsumed()}}
    if(state.session==null){LoginScreen(state,viewModel::login,viewModel::unlockWithPin,onBiometric);return}
    if(state.mode==null){ModePickerScreen(state.session!!.user.name,state.modes,viewModel::chooseMode);return}
    if(state.workShift==null||state.workShift?.status!="open"){ShiftStartScreen(state,viewModel::startShift,if(state.modes.size>1)({viewModel.chooseMode(state.modes.first{it!=state.mode})})else null,viewModel::logout);return}

    val permissions=state.session!!.permissions;val mode=state.mode!!
    val nav=buildList{
        add(AppScreen.HOME)
        add(AppScreen.NOTIFICATIONS)
        if("reports" in permissions&&mode in setOf(AppMode.OPERATION,AppMode.PAY))add(AppScreen.MANAGER)
        if("orders_create" in permissions)add(AppScreen.POS)
        if(mode==AppMode.OPERATION&&"tables" in permissions)add(AppScreen.TABLES)
        if(mode==AppMode.OPERATION&&"orders_kitchen" in permissions)add(AppScreen.KITCHEN)
        if(mode==AppMode.OPERATION&&("orders_dispatch" in permissions||"delivery_assign" in permissions))add(AppScreen.DISPATCH)
        if(mode==AppMode.DELIVERY||("orders_view" in permissions||"orders_create" in permissions||"orders_manage" in permissions))add(AppScreen.ORDERS)
        if("cash" in permissions)add(AppScreen.CASH)
        if(mode==AppMode.DELIVERY)add(AppScreen.DELIVERY)
        if(mode==AppMode.EVENTS)add(AppScreen.EVENTS)
        add(AppScreen.PROFILE)
    }.distinct()
    Scaffold(
        snackbarHost={SnackbarHost(snackbar)},
        floatingActionButton={FloatingActionButton(onClick=onScan){Icon(Icons.Default.QrCodeScanner,contentDescription="Escanear")}},
        bottomBar={NavigationBar{nav.forEach{screen->
            val icon=when(screen){
                AppScreen.HOME->Icons.Default.Home
                AppScreen.NOTIFICATIONS->Icons.Default.Notifications
                AppScreen.MANAGER->Icons.Default.Assessment
                AppScreen.POS->Icons.Default.ShoppingCart
                AppScreen.TABLES,AppScreen.TABLE_ACCOUNT->Icons.Default.Restaurant
                AppScreen.ORDERS->Icons.Default.ReceiptLong
                AppScreen.KITCHEN->Icons.Default.Restaurant
                AppScreen.DISPATCH->Icons.Default.DeliveryDining
                AppScreen.CASH->Icons.Default.PointOfSale
                AppScreen.DELIVERY->Icons.Default.DeliveryDining
                AppScreen.EVENTS->Icons.Default.ConfirmationNumber
                AppScreen.PROFILE->Icons.Default.Badge
            }
            NavigationBarItem(
                selected=state.screen==screen,
                onClick={viewModel.navigate(screen)},
                icon={
                    if(screen==AppScreen.NOTIFICATIONS&&state.unreadNotifications>0){
                        BadgedBox(badge={Badge{Text(if(state.unreadNotifications>99)"99+" else state.unreadNotifications.toString())}}){Icon(icon,contentDescription=screen.name)}
                    }else Icon(icon,contentDescription=screen.name)
                }
            )
        }}}
    ){padding->
        Box(Modifier.fillMaxSize().padding(padding)){
            when(state.screen){
                AppScreen.HOME->HomeScreen(state,viewModel::refreshOrders)
                AppScreen.NOTIFICATIONS->NotificationsScreen(state.notifications,state.unreadNotifications,viewModel::markNotificationRead,viewModel::markAllNotificationsRead,viewModel::refreshNotifications)
                AppScreen.MANAGER->ManagerScreen(state.managerOverview,viewModel::refreshManager)
                AppScreen.POS->PosScreen(state,viewModel::addProduct,viewModel::removeProduct,viewModel::clearCart,viewModel::createPosOrder,viewModel::payPosCash,viewModel::requestPosPix,viewModel::requestPosNfc,viewModel::refreshPosPayment,viewModel::finishPosFlow,viewModel::clearSelectedTable)
                AppScreen.TABLES->TablesScreen(state.tables,"orders_create" in permissions,viewModel::refreshTables,viewModel::openTable,viewModel::closeTable,viewModel::orderForTable,viewModel::openTableAccount)
                AppScreen.TABLE_ACCOUNT->TableAccountScreen(state.tableAccount,"payments" in permissions,viewModel::receiveTableOrder,viewModel::refreshTableAccount,viewModel::closeTableAccount)
                AppScreen.ORDERS->OrdersScreen(state.orders,viewModel::refreshOrders,viewModel::changeOrderStatus)
                AppScreen.KITCHEN->KitchenScreen(state.kitchenTickets,viewModel::refreshKitchen,viewModel::kitchenStatus)
                AppScreen.DISPATCH->DispatchScreen(state.orders,state.deliveryUsers,"delivery_assign" in permissions,state.dispatchFocusOrderId,viewModel::refreshDispatch,viewModel::dispatchReady,viewModel::assignDelivery)
                AppScreen.CASH->CashOperationsScreen(state.cashOpen,state.cashSummary,viewModel::openCash,viewModel::addCashSupply,viewModel::addCashWithdrawal,viewModel::closeCash,viewModel::refreshCash)
                AppScreen.DELIVERY->DeliveryOperationsScreen(state.orders,state.pixCharge,viewModel::changeOrderStatus,viewModel::requestPix,viewModel::requestNfc,viewModel::collectDeliveryCash,viewModel::pollPixStatus,viewModel::dismissPix)
                AppScreen.EVENTS->EventModeScreen(state.events,state.selectedEventId,state.eventEntries,"tickets" in permissions,"guests" in permissions,viewModel::selectEvent,viewModel::refreshEvents,onScan)
                AppScreen.PROFILE->EmployeeProfileScreen(state,viewModel::savePin,viewModel::setBiometric,viewModel::closeShift,viewModel::createCashHandoff,viewModel::dismissCashHandoff,viewModel::refreshDeliveryCash,viewModel::logout)
            }
            if(state.loading)CircularProgressIndicator(Modifier.align(Alignment.Center))
        }
    }
    state.qr?.let{QrResultDialog(it,viewModel::clearQr,viewModel::processCurrentQr)}
}
