package br.com.eventmenu.delivery.ui

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.LocationManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.*
import br.com.eventmenu.delivery.money
import coil3.compose.AsyncImage

private val DeliveryRed = Color(0xFFD62828)
private val DeliveryCream = Color(0xFFFFF8F1)

@Composable
fun EventMenuDeliveryApp(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) {
        vm.message?.let { snackbar.showSnackbar(it); vm.clearMessage() }
    }
    MaterialTheme(
        colorScheme = lightColorScheme(primary = DeliveryRed, secondary = Color(0xFFF77F00), background = DeliveryCream, surface = Color.White),
    ) {
        Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            when (vm.screen) {
                Screen.Login -> LoginScreen(vm)
                Screen.Register -> RegisterScreen(vm)
                Screen.VerifyEmail -> VerifyEmailScreen(vm)
                Screen.Home -> HomeScreen(vm)
                Screen.Catalog -> CatalogScreen(vm)
                Screen.Cart -> CartScreen(vm)
                Screen.Checkout -> CheckoutScreen(vm)
                Screen.Orders -> OrdersScreen(vm)
                Screen.OrderDetail -> OrderDetailScreen(vm)
                Screen.Profile -> ProfileScreen(vm)
                Screen.AddressEditor -> AddressEditorScreen(vm)
            }
            if (vm.busy) Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha=.18f)), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter).padding(16.dp))
        }
    }
}

@Composable
private fun LoginScreen(vm: DeliveryViewModel) {
    var email by remember { mutableStateOf("") }; var password by remember { mutableStateOf("") }
    AuthShell("Peça comida sem complicação", "Entre para acompanhar seus pedidos, pagamentos e entregas.") {
        OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(password, { password = it }, label = { Text("Senha") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        Button({ vm.login(email,password) }, modifier = Modifier.fillMaxWidth(), enabled = email.isNotBlank() && password.isNotBlank()) { Text("Entrar") }
        TextButton({ vm.forgotPassword(email) }, enabled = email.isNotBlank()) { Text("Esqueci minha senha") }
        HorizontalDivider()
        OutlinedButton({ vm.navigate(Screen.Register) }, modifier = Modifier.fillMaxWidth()) { Text("Criar minha conta") }
    }
}

@Composable
private fun RegisterScreen(vm: DeliveryViewModel) {
    var name by remember { mutableStateOf("") }; var email by remember { mutableStateOf("") }; var phone by remember { mutableStateOf("") }; var password by remember { mutableStateOf("") }
    AuthShell("Criar conta", "Você precisará confirmar o e-mail antes de entrar e fazer pedidos.") {
        OutlinedTextField(name,{name=it},label={Text("Nome")},singleLine=true,modifier=Modifier.fillMaxWidth())
        OutlinedTextField(email,{email=it},label={Text("E-mail")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Email),singleLine=true,modifier=Modifier.fillMaxWidth())
        OutlinedTextField(phone,{phone=it},label={Text("Celular")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Phone),singleLine=true,modifier=Modifier.fillMaxWidth())
        OutlinedTextField(password,{password=it},label={Text("Senha · mínimo 8 caracteres")},singleLine=true,modifier=Modifier.fillMaxWidth())
        Button({vm.register(name,email,phone,password)},modifier=Modifier.fillMaxWidth(),enabled=name.isNotBlank()&&email.isNotBlank()&&password.length>=8){Text("Cadastrar e enviar confirmação")}
        TextButton({vm.navigate(Screen.Login)}){Text("Já tenho conta")}
    }
}

@Composable
private fun VerifyEmailScreen(vm: DeliveryViewModel) {
    AuthShell("Confirme seu e-mail", "Enviamos um link para ${vm.pendingEmail}. Só depois da confirmação o acesso será liberado.") {
        Icon(Icons.Default.MarkEmailRead,null,Modifier.size(72.dp),tint=DeliveryRed)
        Text("Abra sua caixa de entrada e toque em “Confirmar meu e-mail”. O link abre novamente o EventMenu Delivery.", style=MaterialTheme.typography.bodyLarge)
        Button({vm.resendVerification()},modifier=Modifier.fillMaxWidth()){Text("Reenviar confirmação")}
        OutlinedButton({vm.navigate(Screen.Login)},modifier=Modifier.fillMaxWidth()){Text("Já confirmei · entrar")}
    }
}

@Composable
private fun HomeScreen(vm: DeliveryViewModel) {
    var search by remember { mutableStateOf("") }
    LaunchedEffect(Unit){ vm.loadStores() }
    Scaffold(
        topBar={ DeliveryTopBar("EventMenu Delivery") },
        bottomBar={ BottomNav(vm, Screen.Home) }
    ){ pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(14.dp)){
            item { Text("O que vai pedir hoje?",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Bold) }
            item { OutlinedTextField(search,{search=it;vm.loadStores(it)},leadingIcon={Icon(Icons.Default.Search,null)},label={Text("Buscar restaurante")},singleLine=true,modifier=Modifier.fillMaxWidth()) }
            if(vm.stores.isEmpty()) item { EmptyCard("Nenhum restaurante disponível agora.") }
            items(vm.stores,key={"${it.tenantId}-${it.unitId}"}){ store -> StoreCard(store,{vm.openStore(store)},{vm.toggleFavorite(store)}) }
        }
    }
}

@Composable
private fun StoreCard(store: Store,onOpen:()->Unit,onFavorite:()->Unit){
    Card(Modifier.fillMaxWidth().clickable(onClick=onOpen),shape=RoundedCornerShape(20.dp)){
        Column{
            if(store.coverUrl.isNotBlank()) AsyncImage(store.coverUrl,null,Modifier.fillMaxWidth().height(150.dp))
            Row(Modifier.padding(16.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalAlignment=Alignment.CenterVertically){
                if(store.logoUrl.isNotBlank()) AsyncImage(store.logoUrl,null,Modifier.size(56.dp))
                Column(Modifier.weight(1f)){Text(store.name,fontWeight=FontWeight.Bold,style=MaterialTheme.typography.titleMedium);if(store.description.isNotBlank())Text(store.description,maxLines=2);Text(listOfNotNull(store.city.takeIf{it.isNotBlank()},store.state.takeIf{it.isNotBlank()}).joinToString(" · "),style=MaterialTheme.typography.bodySmall);Text("Entrega ${money(store.deliveryFeeCents)} · mínimo ${money(store.minimumOrderCents)}",style=MaterialTheme.typography.bodySmall)}
                IconButton(onFavorite){Icon(if(store.favorite)Icons.Default.Favorite else Icons.Default.FavoriteBorder,null,tint=if(store.favorite)DeliveryRed else LocalContentColor.current)}
            }
        }
    }
}

@Composable
private fun CatalogScreen(vm: DeliveryViewModel){
    val cat=vm.catalog?:return
    Scaffold(topBar={DeliveryTopBar(cat.store.name,onBack={vm.navigate(Screen.Home)},actions={IconButton({vm.navigate(Screen.Cart)}){BadgedBox(badge={if(vm.cart.isNotEmpty())Badge{Text(vm.cart.sumOf{it.quantity}.toString())}}){Icon(Icons.Default.ShoppingCart,null)}}})}){pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
            item { if(cat.store.coverUrl.isNotBlank())AsyncImage(cat.store.coverUrl,null,Modifier.fillMaxWidth().height(170.dp));Text(cat.store.description);Text("Entrega ${money(cat.store.deliveryFeeCents)} · pedido mínimo ${money(cat.store.minimumOrderCents)}",style=MaterialTheme.typography.bodySmall) }
            cat.categories.forEach { category ->
                item(key="cat-${category.id}"){Text(category.name,style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold,modifier=Modifier.padding(top=8.dp))}
                items(cat.products.filter{it.categoryId==category.id},key={"p-${it.id}"}){ product -> ProductCard(product){vm.addToCart(product)} }
            }
        }
    }
}

@Composable
private fun ProductCard(product: Product,onAdd:()->Unit){
    Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(16.dp)){
        Row(Modifier.padding(12.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalAlignment=Alignment.CenterVertically){
            if(product.imageUrl.isNotBlank())AsyncImage(product.imageUrl,product.name,Modifier.size(92.dp))
            Column(Modifier.weight(1f)){Text(product.name,fontWeight=FontWeight.Bold);if(product.description.isNotBlank())Text(product.description,maxLines=3,style=MaterialTheme.typography.bodySmall);Text(money(product.priceCents),fontWeight=FontWeight.Bold,color=DeliveryRed)}
            FilledTonalIconButton(onAdd,enabled=product.available){Icon(Icons.Default.Add,null)}
        }
    }
}

@Composable
private fun CartScreen(vm: DeliveryViewModel){
    val customer=vm.customer
    val subtotal=vm.cart.sumOf{it.totalCents()}
    var selectedAddress by remember(customer){mutableIntStateOf(customer?.addresses?.firstOrNull{it.isDefault}?.id?:customer?.addresses?.firstOrNull()?.id?:0)}
    Scaffold(topBar={DeliveryTopBar("Seu carrinho",onBack={vm.navigate(Screen.Catalog)})}){pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
            items(vm.cart.size){i->val item=vm.cart[i];Card{Row(Modifier.padding(14.dp),verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(item.product.name,fontWeight=FontWeight.Bold);Text(money(item.totalCents()))};IconButton({vm.updateCart(i,item.quantity-1)}){Icon(Icons.Default.Remove,null)};Text(item.quantity.toString());IconButton({vm.updateCart(i,item.quantity+1)}){Icon(Icons.Default.Add,null)}}}}
            item { HorizontalDivider();Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("Subtotal",fontWeight=FontWeight.Bold);Text(money(subtotal),fontWeight=FontWeight.Bold)} }
            item { Text("Endereço de entrega",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold) }
            if(customer?.addresses.isNullOrEmpty()) item { EmptyCard("Cadastre um endereço antes de finalizar.");Button({vm.editAddress()},Modifier.fillMaxWidth()){Text("Cadastrar endereço")}}
            else items(customer!!.addresses){a->Card(Modifier.fillMaxWidth().clickable{selectedAddress=a.id},colors=CardDefaults.cardColors(containerColor=if(selectedAddress==a.id)MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface)){Row(Modifier.padding(14.dp)){RadioButton(selectedAddress==a.id,{selectedAddress=a.id});Column{Text(a.label,fontWeight=FontWeight.Bold);Text("${a.street}, ${a.number} · ${a.city}/${a.state}")}}}}
            item { Button({vm.checkoutCart(selectedAddress)},enabled=vm.cart.isNotEmpty()&&selectedAddress>0,modifier=Modifier.fillMaxWidth()){Text("Confirmar pedido")}}
        }
    }
}

@Composable
private fun CheckoutScreen(vm: DeliveryViewModel){
    val order=vm.selectedOrder?:return;val methods=vm.paymentMethods
    var taxId by remember{mutableStateOf("")};var showCard by remember{mutableStateOf(false)};var showCash by remember{mutableStateOf(false)};var change by remember{mutableStateOf("")}
    val clipboard=LocalClipboardManager.current
    Scaffold(topBar={DeliveryTopBar("Pagamento",onBack={vm.navigate(Screen.Orders)})}){pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(14.dp)){
            item { Text("Pedido #${order.orderNumber}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Bold);Text("Total ${money(order.totalCents)}",style=MaterialTheme.typography.titleLarge,color=DeliveryRed) }
            if(methods==null)item{CircularProgressIndicator()}
            else{
                if(methods.pixProviders.isNotEmpty()) item { Card{Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){Text("PIX",fontWeight=FontWeight.Bold);OutlinedTextField(taxId,{taxId=it.filter(Char::isDigit).take(14)},label={Text("CPF/CNPJ")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),singleLine=true,modifier=Modifier.fillMaxWidth());methods.pixProviders.forEach{provider->Button({vm.payPix(provider,taxId)},Modifier.fillMaxWidth(),enabled=taxId.length in listOf(11,14)){Text("Gerar PIX · ${providerLabel(provider)}")}};vm.pixPayment?.let{pix->Text("PIX copia e cola",fontWeight=FontWeight.Bold);Text(pix.copyPaste,maxLines=4);OutlinedButton({clipboard.setText(AnnotatedString(pix.copyPaste))},Modifier.fillMaxWidth()){Text("Copiar código PIX")}}}} }
                if(methods.cards.isNotEmpty()) item { val card=methods.cards.first();Card{Column(Modifier.padding(16.dp)){Row(Modifier.fillMaxWidth().clickable{showCard=!showCard},verticalAlignment=Alignment.CenterVertically){Icon(Icons.Default.CreditCard,null);Spacer(Modifier.width(10.dp));Text("Cartão de crédito",Modifier.weight(1f),fontWeight=FontWeight.Bold);Icon(if(showCard)Icons.Default.ExpandLess else Icons.Default.ExpandMore,null)};if(showCard){Spacer(Modifier.height(14.dp));CardPaymentForm(card.publicKey,card.maxInstallments,vm.customer?.name.orEmpty()){token,methodId,installments,doc->vm.payCardToken(token,methodId,installments,doc)}}}} }
                if(methods.cash) item { Card{Column(Modifier.padding(16.dp)){Row(Modifier.fillMaxWidth().clickable{showCash=!showCash},verticalAlignment=Alignment.CenterVertically){Icon(Icons.Default.Payments,null);Spacer(Modifier.width(10.dp));Text("Dinheiro na entrega",Modifier.weight(1f),fontWeight=FontWeight.Bold);Icon(if(showCash)Icons.Default.ExpandLess else Icons.Default.ExpandMore,null)};if(showCash){OutlinedTextField(change,{change=it.filter{c->c.isDigit()||c==','||c=='.'}},label={Text("Troco para quanto? · opcional")},modifier=Modifier.fillMaxWidth());Button({val cents=change.replace(',','.').toDoubleOrNull()?.let{(it*100).toInt()};vm.payCash(cents)},Modifier.fillMaxWidth()){Text("Pagar na entrega")}}}} }
            }
            item { OutlinedButton({vm.openOrder(order.orderNumber)},Modifier.fillMaxWidth()){Text("Acompanhar pedido")}}
        }
    }
}

@Composable
private fun OrdersScreen(vm: DeliveryViewModel){
    LaunchedEffect(Unit){if(vm.orders.isEmpty())vm.loadOrders()}
    Scaffold(topBar={DeliveryTopBar("Meus pedidos")},bottomBar={BottomNav(vm,Screen.Orders)}){pad->LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){if(vm.orders.isEmpty())item{EmptyCard("Você ainda não fez pedidos.")};items(vm.orders,key={it.orderNumber}){o->Card(Modifier.fillMaxWidth().clickable{vm.openOrder(o.orderNumber)}){Column(Modifier.padding(16.dp)){Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("Pedido #${o.orderNumber}",fontWeight=FontWeight.Bold);Text(money(o.totalCents),fontWeight=FontWeight.Bold)};Text(o.storeName);Text(o.statusLabel,color=DeliveryRed);Text(paymentLabel(o.paymentStatus),style=MaterialTheme.typography.bodySmall)}}}}}
}

@Composable
private fun OrderDetailScreen(vm: DeliveryViewModel){
    val o=vm.selectedOrder?:return;var rating by remember{mutableIntStateOf(5)};var comment by remember{mutableStateOf("")}
    Scaffold(topBar={DeliveryTopBar("Pedido #${o.orderNumber}",onBack={vm::loadOrders})}){pad->LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{Card{Column(Modifier.padding(16.dp)){Text(o.storeName,style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold);Text(o.statusLabel,color=DeliveryRed,fontWeight=FontWeight.Bold);Text(paymentLabel(o.paymentStatus));Text("Total ${money(o.totalCents)}")}}}
        vm.tracking?.let{t->item{Card{Column(Modifier.padding(16.dp)){Text("Entrega em tempo real",fontWeight=FontWeight.Bold);Text(t.statusLabel);if(t.latitude!=null&&t.longitude!=null){Text("Entregador: %.5f, %.5f".format(t.latitude,t.longitude));Text("Posição atualizada automaticamente a cada 10 segundos.",style=MaterialTheme.typography.bodySmall)}else Text("A localização aparecerá quando o entregador iniciar a rota.")}}}}
        if(o.status=="completed")item{Card{Column(Modifier.padding(16.dp)){Text("Como foi seu pedido?",fontWeight=FontWeight.Bold);Row{(1..5).forEach{n->IconButton({rating=n}){Icon(if(n<=rating)Icons.Default.Star else Icons.Default.StarBorder,null,tint=Color(0xFFFFA000))}}};OutlinedTextField(comment,{comment=it},label={Text("Comentário · opcional")},modifier=Modifier.fillMaxWidth());Button({vm.submitReview(o.orderNumber,rating,comment)},Modifier.fillMaxWidth()){Text("Enviar avaliação")}}}}
        item{OutlinedButton({vm.repeatOrder(o.orderNumber)},Modifier.fillMaxWidth()){Icon(Icons.Default.Refresh,null);Spacer(Modifier.width(8.dp));Text("Pedir novamente")}}
        item{OutlinedButton(vm::refreshCurrentOrder,Modifier.fillMaxWidth()){Text("Atualizar status")}}
    }}
}

@Composable
private fun ProfileScreen(vm: DeliveryViewModel){
    val c=vm.customer;var name by remember(c){mutableStateOf(c?.name.orEmpty())};var phone by remember(c){mutableStateOf(c?.phone.orEmpty())}
    Scaffold(topBar={DeliveryTopBar("Minha conta")},bottomBar={BottomNav(vm,Screen.Profile)}){pad->LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{OutlinedTextField(name,{name=it},label={Text("Nome")},modifier=Modifier.fillMaxWidth());OutlinedTextField(phone,{phone=it},label={Text("Celular")},modifier=Modifier.fillMaxWidth());Text(c?.email.orEmpty(),style=MaterialTheme.typography.bodySmall);Button({vm.saveProfile(name,phone)},Modifier.fillMaxWidth()){Text("Salvar perfil")}}
        item{Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Text("Meus endereços",Modifier.weight(1f),style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold);IconButton({vm.editAddress()}){Icon(Icons.Default.Add,null)}}}
        if(c?.addresses.isNullOrEmpty())item{EmptyCard("Cadastre seu endereço de entrega.")}
        else items(c!!.addresses,key={it.id}){a->Card{Column(Modifier.padding(16.dp)){Row(Modifier.fillMaxWidth()){Column(Modifier.weight(1f)){Text(a.label,fontWeight=FontWeight.Bold);Text("${a.street}, ${a.number}");Text("${a.city}/${a.state}")};IconButton({vm.editAddress(a)}){Icon(Icons.Default.Edit,null)};IconButton({vm.deleteAddress(a.id)}){Icon(Icons.Default.Delete,null)}}}}}
        item{OutlinedButton(vm::logout,Modifier.fillMaxWidth()){Text("Sair da conta")}}
    }}
}

@Composable
private fun AddressEditorScreen(vm: DeliveryViewModel){
    val original=vm.editingAddress
    var label by remember{mutableStateOf(original?.label?:"Casa")};var street by remember{mutableStateOf(original?.street.orEmpty())};var number by remember{mutableStateOf(original?.number.orEmpty())};var complement by remember{mutableStateOf(original?.complement.orEmpty())};var neighborhood by remember{mutableStateOf(original?.neighborhood.orEmpty())};var city by remember{mutableStateOf(original?.city.orEmpty())};var state by remember{mutableStateOf(original?.state.orEmpty())};var cep by remember{mutableStateOf(original?.postalCode.orEmpty())};var reference by remember{mutableStateOf(original?.reference.orEmpty())};var lat by remember{mutableStateOf(original?.latitude)};var lng by remember{mutableStateOf(original?.longitude)};var isDefault by remember{mutableStateOf(original?.isDefault?:vm.customer?.addresses.isNullOrEmpty())}
    Scaffold(topBar={DeliveryTopBar(if(original==null)"Novo endereço" else "Editar endereço",onBack={vm.navigate(Screen.Profile)})}){pad->LazyColumn(Modifier.fillMaxSize().padding(pad),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){
        item{LocationCaptureButton{a,b->lat=a;lng=b}}
        item{OutlinedTextField(label,{label=it},label={Text("Nome do endereço")},modifier=Modifier.fillMaxWidth());OutlinedTextField(cep,{cep=it},label={Text("CEP")},modifier=Modifier.fillMaxWidth());OutlinedTextField(street,{street=it},label={Text("Rua")},modifier=Modifier.fillMaxWidth());OutlinedTextField(number,{number=it},label={Text("Número")},modifier=Modifier.fillMaxWidth());OutlinedTextField(complement,{complement=it},label={Text("Complemento")},modifier=Modifier.fillMaxWidth());OutlinedTextField(neighborhood,{neighborhood=it},label={Text("Bairro")},modifier=Modifier.fillMaxWidth());OutlinedTextField(city,{city=it},label={Text("Cidade")},modifier=Modifier.fillMaxWidth());OutlinedTextField(state,{state=it.uppercase().take(2)},label={Text("UF")},modifier=Modifier.fillMaxWidth());OutlinedTextField(reference,{reference=it},label={Text("Referência")},modifier=Modifier.fillMaxWidth());Row(verticalAlignment=Alignment.CenterVertically){Checkbox(isDefault,{isDefault=it});Text("Endereço principal")};if(lat!=null&&lng!=null)Text("GPS salvo: %.5f, %.5f".format(lat,lng),style=MaterialTheme.typography.bodySmall);Button({vm.saveAddress(Address(original?.id?:0,label,street,number,complement,neighborhood,city,state,cep,reference,vm.customer?.phone.orEmpty(),lat,lng,isDefault))},Modifier.fillMaxWidth(),enabled=street.isNotBlank()&&number.isNotBlank()&&city.isNotBlank()&&state.length==2){Text("Salvar endereço")}}
    }}
}

@Composable
private fun LocationCaptureButton(onLocation:(Double,Double)->Unit){
    val context=LocalContext.current
    var status by remember{mutableStateOf("Usar minha localização")}
    fun capture(){val lm=context.getSystemService(Context.LOCATION_SERVICE) as LocationManager;val providers=listOf(LocationManager.GPS_PROVIDER,LocationManager.NETWORK_PROVIDER);val loc=providers.mapNotNull{p->runCatching{lm.getLastKnownLocation(p)}.getOrNull()}.maxByOrNull{it.time};if(loc!=null){onLocation(loc.latitude,loc.longitude);status="Localização adicionada ✓"}else status="Ative o GPS e tente novamente"}
    val launcher=rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()){grants->if(grants.values.any{it})capture()else status="Permissão de localização necessária"}
    OutlinedButton({if(ContextCompat.checkSelfPermission(context,Manifest.permission.ACCESS_FINE_LOCATION)==PackageManager.PERMISSION_GRANTED||ContextCompat.checkSelfPermission(context,Manifest.permission.ACCESS_COARSE_LOCATION)==PackageManager.PERMISSION_GRANTED)capture()else launcher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION))},Modifier.fillMaxWidth()){Icon(Icons.Default.MyLocation,null);Spacer(Modifier.width(8.dp));Text(status)}
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DeliveryTopBar(title:String,onBack:(()->Unit)?=null,actions:@Composable RowScope.()->Unit={}){TopAppBar(title={Text(title,fontWeight=FontWeight.Bold)},navigationIcon={if(onBack!=null)IconButton(onBack){Icon(Icons.Default.ArrowBack,null)}},actions=actions,colors=TopAppBarDefaults.topAppBarColors(containerColor=DeliveryCream))}

@Composable
private fun BottomNav(vm:DeliveryViewModel,current:Screen){NavigationBar{NavigationBarItem(current==Screen.Home,{vm.navigate(Screen.Home);vm.loadStores()},icon={Icon(Icons.Default.Home,null)},label={Text("Início")});NavigationBarItem(current==Screen.Orders,{vm.loadOrders()},icon={Icon(Icons.Default.ReceiptLong,null)},label={Text("Pedidos")});NavigationBarItem(current==Screen.Profile,{vm.openProfile()},icon={Icon(Icons.Default.Person,null)},label={Text("Conta")})}}

@Composable
private fun AuthShell(title:String,subtitle:String,content:@Composable ColumnScope.()->Unit){Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),verticalArrangement=Arrangement.spacedBy(14.dp)){Spacer(Modifier.height(36.dp));Text("EventMenu",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black,color=DeliveryRed);Text("Delivery",style=MaterialTheme.typography.titleMedium);Spacer(Modifier.height(20.dp));Text(title,style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Bold);Text(subtitle,color=MaterialTheme.colorScheme.onSurfaceVariant);content()}}
@Composable private fun EmptyCard(text:String){Card(Modifier.fillMaxWidth()){Text(text,Modifier.padding(20.dp),color=MaterialTheme.colorScheme.onSurfaceVariant)}}
private fun providerLabel(p:String)=when(p){"mercadopago"->"Mercado Pago";"pagbank"->"PagBank";else->p}
private fun paymentLabel(s:String)=when(s){"paid"->"Pagamento confirmado";"pending","created","processing"->"Aguardando pagamento";"failed"->"Pagamento não concluído";else->s}
