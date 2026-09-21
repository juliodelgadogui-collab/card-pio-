package br.com.eventmenu.delivery

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel

private val Purple = Color(0xFF5B34D6)
private val PurpleDark = Color(0xFF3E219B)
private val Green = Color(0xFF168A4A)
private val Orange = Color(0xFFB66A00)
private val Red = Color(0xFFB42318)
private val SurfaceSoft = Color(0xFFF7F6FB)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(
                colorScheme = lightColorScheme(
                    primary = Purple,
                    secondary = PurpleDark,
                    background = SurfaceSoft,
                    surface = Color.White,
                    error = Red,
                )
            ) {
                EventMenuDeliveryApp()
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EventMenuDeliveryApp(vm: MarketplaceViewModel = viewModel()) {
    val state by vm.state.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val message = state.message
    LaunchedEffect(message) {
        if (!message.isNullOrBlank()) {
            snackbar.showSnackbar(message)
            vm.clearMessage()
        }
    }
    BackHandler(enabled = state.screen != Screen.Stores) { vm.back() }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        containerColor = MaterialTheme.colorScheme.background,
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            when (val screen = state.screen) {
                Screen.Stores -> StoresScreen(state, vm::search, vm::openStore, vm::openActiveOrder, vm::loadStores)
                is Screen.Menu -> MenuScreen(state, screen.catalog, vm::addToCart, vm::checkout, vm::changeQuantity, vm::back)
                is Screen.Checkout -> CheckoutScreen(state, screen.catalog, vm::submitOrder, vm::changeQuantity, vm::back)
                is Screen.Order -> OrderScreen(state.order ?: screen.order, state.tracking, vm::refreshOrder, vm::back)
            }
            if (state.loading) {
                Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = .12f)), contentAlignment = Alignment.Center) {
                    Surface(shape = RoundedCornerShape(24.dp), tonalElevation = 6.dp) {
                        Row(Modifier.padding(horizontal = 22.dp, vertical = 18.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 3.dp)
                            Spacer(Modifier.width(14.dp))
                            Text("Carregando…", fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun StoresScreen(
    state: MarketplaceUiState,
    onSearch: (String) -> Unit,
    onStore: (Store) -> Unit,
    onActiveOrder: () -> Unit,
    onRetry: () -> Unit,
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 32.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Column {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Surface(color = Purple, shape = RoundedCornerShape(14.dp)) {
                        Text("E", color = Color.White, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 13.dp, vertical = 8.dp))
                    }
                    Spacer(Modifier.width(10.dp))
                    Column {
                        Text("EventMenu Delivery", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Comida boa, perto de você", color = Color.Gray)
                    }
                }
                Spacer(Modifier.height(18.dp))
                OutlinedTextField(
                    value = state.query,
                    onValueChange = onSearch,
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    leadingIcon = { Icon(Icons.Default.Search, null) },
                    placeholder = { Text("Buscar restaurante ou comida") },
                    shape = RoundedCornerShape(18.dp),
                )
            }
        }
        state.order?.takeIf { it.status !in setOf("completed", "cancelled") }?.let { order ->
            item {
                Surface(
                    modifier = Modifier.fillMaxWidth().clickable(onClick = onActiveOrder),
                    color = Color(0xFFF0ECFF),
                    shape = RoundedCornerShape(20.dp),
                ) {
                    Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.DeliveryDining, null, tint = Purple)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("Seu pedido #${order.orderNumber}", fontWeight = FontWeight.Bold)
                            Text(order.statusLabel, color = PurpleDark)
                        }
                        Icon(Icons.Default.ChevronRight, null, tint = Purple)
                    }
                }
            }
        }
        if (!state.loading && state.stores.isEmpty()) {
            item {
                EmptyState(
                    icon = Icons.Default.Storefront,
                    title = if (state.query.isBlank()) "Nenhuma loja disponível agora" else "Nada encontrado",
                    text = if (state.query.isBlank()) "Assim que os estabelecimentos da sua região entrarem no EventMenu Delivery, eles aparecerão aqui." else "Tente buscar outro nome ou categoria.",
                    action = "Atualizar",
                    onAction = onRetry,
                )
            }
        } else {
            items(state.stores, key = { "${it.tenantId}:${it.unitId}" }) { store -> StoreCard(store, onStore) }
        }
    }
}

@Composable
private fun StoreCard(store: Store, onStore: (Store) -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth().clickable { onStore(store) },
        shape = RoundedCornerShape(22.dp),
        tonalElevation = 1.dp,
        shadowElevation = 2.dp,
    ) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Surface(color = Purple.copy(alpha = .1f), shape = RoundedCornerShape(16.dp), modifier = Modifier.size(58.dp)) {
                    Box(contentAlignment = Alignment.Center) { Text(store.name.take(1).uppercase(), color = Purple, fontWeight = FontWeight.Black, style = MaterialTheme.typography.headlineSmall) }
                }
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(store.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (store.description.isNotBlank()) Text(store.description, color = Color.Gray, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    val place = listOf(store.city, store.state).filter { it.isNotBlank() }.joinToString(" • ")
                    if (place.isNotBlank()) Text(place, style = MaterialTheme.typography.bodySmall, color = Color.Gray)
                }
                Icon(Icons.Default.ChevronRight, null, tint = Color.Gray)
            }
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                AssistChip(onClick = { onStore(store) }, label = { Text(if (store.deliveryFeeCents == 0) "Entrega grátis" else "Entrega ${store.deliveryFeeCents.money()}") })
                if (store.minimumOrderCents > 0) AssistChip(onClick = { onStore(store) }, label = { Text("Mín. ${store.minimumOrderCents.money()}") })
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MenuScreen(
    state: MarketplaceUiState,
    catalog: Catalog,
    onAdd: (Product, Int, Set<Int>) -> String?,
    onCheckout: () -> Unit,
    onQuantity: (Int, Int) -> Unit,
    onBack: () -> Unit,
) {
    var selectedProduct by remember { mutableStateOf<Product?>(null) }
    var selectedCategory by remember { mutableStateOf<Int?>(null) }
    val products = remember(catalog.products, selectedCategory) { catalog.products.filter { selectedCategory == null || it.categoryId == selectedCategory } }
    Column(Modifier.fillMaxSize()) {
        Surface(color = Color.White, shadowElevation = 2.dp) {
            Column(Modifier.padding(horizontal = 16.dp, vertical = 12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton(onClick = onBack) { Icon(Icons.Default.ArrowBack, "Voltar") }
                    Column(Modifier.weight(1f)) {
                        Text(catalog.store.name, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                        Text(catalog.store.unitName, color = Color.Gray)
                    }
                }
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), contentPadding = PaddingValues(vertical = 6.dp)) {
                    item { FilterChip(selected = selectedCategory == null, onClick = { selectedCategory = null }, label = { Text("Tudo") }) }
                    items(catalog.categories, key = { it.id }) { category ->
                        FilterChip(selected = selectedCategory == category.id, onClick = { selectedCategory = category.id }, label = { Text(category.name) })
                    }
                }
            }
        }
        Box(Modifier.weight(1f)) {
            LazyColumn(
                contentPadding = PaddingValues(16.dp, 14.dp, 16.dp, if (state.cart.isEmpty()) 28.dp else 112.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                if (products.isEmpty()) item { EmptyState(Icons.Default.Fastfood, "Nenhum produto nesta categoria", "Escolha outra categoria para continuar.") }
                items(products, key = { it.id }) { product -> ProductCard(product) { selectedProduct = product } }
            }
            AnimatedVisibility(visible = state.cart.isNotEmpty(), modifier = Modifier.align(Alignment.BottomCenter)) {
                CartBar(state, onCheckout, onQuantity)
            }
        }
    }
    selectedProduct?.let { product ->
        ModalBottomSheet(onDismissRequest = { selectedProduct = null }) {
            ProductSheet(product) { qty, ids ->
                val error = onAdd(product, qty, ids)
                if (error == null) selectedProduct = null
                error
            }
        }
    }
}

@Composable
private fun ProductCard(product: Product, onClick: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth().clickable(enabled = product.available, onClick = onClick),
        shape = RoundedCornerShape(20.dp),
        color = if (product.available) Color.White else Color(0xFFF1F1F3),
        tonalElevation = 1.dp,
    ) {
        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(product.name, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                if (product.description.isNotBlank()) Text(product.description, color = Color.Gray, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.height(8.dp))
                Text(product.priceCents.money(), color = if (product.available) PurpleDark else Color.Gray, fontWeight = FontWeight.Bold)
                if (!product.available) Text("Indisponível no momento", color = Red, style = MaterialTheme.typography.bodySmall)
            }
            Spacer(Modifier.width(12.dp))
            Surface(color = if (product.available) Purple.copy(alpha=.1f) else Color.LightGray.copy(alpha=.4f), shape = RoundedCornerShape(16.dp), modifier = Modifier.size(68.dp)) {
                Box(contentAlignment = Alignment.Center) { Icon(Icons.Default.Restaurant, null, tint = if (product.available) Purple else Color.Gray) }
            }
        }
    }
}

@Composable
private fun ProductSheet(product: Product, onAdd: (Int, Set<Int>) -> String?) {
    var qty by remember(product.id) { mutableIntStateOf(1) }
    var selected by remember(product.id) { mutableStateOf(emptySet<Int>()) }
    var localError by remember(product.id) { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxWidth().padding(start=20.dp,end=20.dp,bottom=28.dp)) {
        Text(product.name, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
        if (product.description.isNotBlank()) { Spacer(Modifier.height(6.dp)); Text(product.description, color = Color.Gray) }
        Spacer(Modifier.height(8.dp))
        Text(product.priceCents.money(), color = PurpleDark, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(18.dp))
        product.modifierGroups.forEach { group ->
            Text(group.name, fontWeight = FontWeight.Bold)
            Text(modifierRule(group), style = MaterialTheme.typography.bodySmall, color = if (group.required) Orange else Color.Gray)
            group.options.forEach { option ->
                val checked = option.id in selected
                Row(
                    modifier = Modifier.fillMaxWidth().clickable {
                        selected = if (group.maxSelect == 1) {
                            val groupIds = group.options.map { it.id }.toSet()
                            (selected - groupIds) + option.id
                        } else if (checked) selected - option.id else if (group.options.count { it.id in selected } < group.maxSelect) selected + option.id else selected
                        localError = null
                    }.padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Checkbox(checked = checked, onCheckedChange = null)
                    Text(option.name, Modifier.weight(1f))
                    if (option.priceDeltaCents != 0) Text("+ ${option.priceDeltaCents.money()}", color = Color.Gray)
                }
            }
            Spacer(Modifier.height(12.dp))
        }
        localError?.let { Text(it, color = Red, modifier = Modifier.padding(vertical = 6.dp)) }
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { if (qty > 1) qty-- }) { Icon(Icons.Default.Remove, "Diminuir") }
            Text(qty.toString(), fontWeight = FontWeight.Bold)
            IconButton(onClick = { if (qty < 99) qty++ }) { Icon(Icons.Default.Add, "Aumentar") }
            Spacer(Modifier.weight(1f))
            val extra = product.modifierGroups.flatMap { it.options }.filter { it.id in selected }.sumOf { it.priceDeltaCents }
            Button(
                onClick = { localError = onAdd(qty, selected) },
                shape = RoundedCornerShape(16.dp),
                contentPadding = PaddingValues(horizontal = 18.dp, vertical = 14.dp),
            ) { Text("Adicionar • ${((product.priceCents + extra) * qty).money()}") }
        }
    }
}

@Composable
private fun CartBar(state: MarketplaceUiState, onCheckout: () -> Unit, onQuantity: (Int, Int) -> Unit) {
    val subtotal = state.cart.sumOf { it.totalCents }
    Surface(color = Color.White, shadowElevation = 12.dp) {
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            if (state.cart.size <= 2) state.cart.forEachIndexed { index, line ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("${line.quantity}× ${line.product.name}", Modifier.weight(1f), maxLines=1, overflow=TextOverflow.Ellipsis)
                    IconButton(onClick = { onQuantity(index,-1) }, modifier=Modifier.size(32.dp)) { Icon(Icons.Default.Remove, null) }
                    IconButton(onClick = { onQuantity(index,1) }, modifier=Modifier.size(32.dp)) { Icon(Icons.Default.Add, null) }
                }
            }
            Button(onClick = onCheckout, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp)) {
                Text("Ver carrinho • ${subtotal.money()}", modifier = Modifier.padding(vertical = 5.dp))
            }
        }
    }
}

@Composable
private fun CheckoutScreen(
    state: MarketplaceUiState,
    catalog: Catalog,
    onSubmit: (CheckoutCustomer) -> Unit,
    onQuantity: (Int, Int) -> Unit,
    onBack: () -> Unit,
) {
    var name by rememberSaveable { mutableStateOf("") }
    var phone by rememberSaveable { mutableStateOf("") }
    var address by rememberSaveable { mutableStateOf("") }
    val subtotal = state.cart.sumOf { it.totalCents }
    val total = subtotal + catalog.store.deliveryFeeCents
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp,16.dp,16.dp,36.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        item {
            Row(verticalAlignment=Alignment.CenterVertically) {
                IconButton(onClick=onBack) { Icon(Icons.Default.ArrowBack,"Voltar") }
                Column { Text("Finalizar pedido",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text(catalog.store.name,color=Color.Gray) }
            }
        }
        item { StepHeader(1,"Seus dados","Para identificar e acompanhar seu pedido") }
        item { OutlinedTextField(name,{name=it},Modifier.fillMaxWidth(),label={Text("Nome")},singleLine=true,shape=RoundedCornerShape(16.dp)) }
        item { OutlinedTextField(phone,{phone=it},Modifier.fillMaxWidth(),label={Text("Telefone")},singleLine=true,shape=RoundedCornerShape(16.dp)) }
        item { StepHeader(2,"Entrega","Confira onde devemos entregar") }
        item { OutlinedTextField(address,{address=it},Modifier.fillMaxWidth(),label={Text("Endereço completo")},minLines=2,shape=RoundedCornerShape(16.dp)) }
        item { StepHeader(3,"Seu pedido","Revise os itens antes de confirmar") }
        items(state.cart.size) { index ->
            val line=state.cart[index]
            Surface(shape=RoundedCornerShape(16.dp),color=Color.White) {
                Row(Modifier.padding(14.dp),verticalAlignment=Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)){Text(line.product.name,fontWeight=FontWeight.Bold);Text(line.totalCents.money(),color=Color.Gray)}
                    IconButton(onClick={onQuantity(index,-1)}){Icon(Icons.Default.Remove,null)}
                    Text(line.quantity.toString(),fontWeight=FontWeight.Bold)
                    IconButton(onClick={onQuantity(index,1)}){Icon(Icons.Default.Add,null)}
                }
            }
        }
        item {
            Surface(shape=RoundedCornerShape(18.dp),color=Color.White) {
                Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(7.dp)) {
                    PriceRow("Produtos",subtotal)
                    PriceRow("Entrega",catalog.store.deliveryFeeCents)
                    HorizontalDivider()
                    PriceRow("Total",total,true)
                }
            }
        }
        item {
            Button(
                onClick={onSubmit(CheckoutCustomer(name,phone,address))},
                enabled=state.cart.isNotEmpty() && name.isNotBlank() && phone.isNotBlank() && address.isNotBlank(),
                modifier=Modifier.fillMaxWidth(),shape=RoundedCornerShape(16.dp)
            ){Text("Confirmar pedido • ${total.money()}",modifier=Modifier.padding(vertical=6.dp))}
        }
        item { Text("O pagamento será apresentado conforme as opções disponíveis para esta loja. Nenhuma cobrança é considerada paga antes da confirmação do EventMenu.",style=MaterialTheme.typography.bodySmall,color=Color.Gray) }
    }
}

@Composable
private fun OrderScreen(order: ConsumerOrder, tracking: TrackingStatus?, onRefresh: () -> Unit, onBack: () -> Unit) {
    val context= LocalContext.current
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp,16.dp,16.dp,36.dp),verticalArrangement=Arrangement.spacedBy(14.dp)) {
        item {
            Row(verticalAlignment=Alignment.CenterVertically){IconButton(onClick=onBack){Icon(Icons.Default.ArrowBack,"Voltar")};Column(Modifier.weight(1f)){Text("Pedido #${order.orderNumber}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text(order.storeName,color=Color.Gray)};IconButton(onClick=onRefresh){Icon(Icons.Default.Refresh,"Atualizar")}}
        }
        item {
            Surface(shape=RoundedCornerShape(20.dp),color=statusColor(order.status).copy(alpha=.1f)) {
                Row(Modifier.fillMaxWidth().padding(18.dp),verticalAlignment=Alignment.CenterVertically){Icon(statusIcon(order.status),null,tint=statusColor(order.status));Spacer(Modifier.width(12.dp));Column{Text(order.statusLabel,fontWeight=FontWeight.Bold,color=statusColor(order.status));Text(order.paymentStatusLabel,color=Color.DarkGray)}}
            }
        }
        item {
            Surface(shape=RoundedCornerShape(20.dp),color=Color.White) {
                Column(Modifier.padding(18.dp)) {
                    Text("Acompanhe seu pedido",fontWeight=FontWeight.Bold,style=MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(14.dp))
                    order.timeline.forEachIndexed { index, step ->
                        TimelineRow(step,last=index==order.timeline.lastIndex)
                    }
                    if(order.status=="cancelled") Text("Este pedido foi cancelado.",color=Red,fontWeight=FontWeight.Bold)
                }
            }
        }
        if(order.tracking?.active==true) {
            item {
                Surface(shape=RoundedCornerShape(20.dp),color=Color(0xFFEAF7EF)) {
                    Column(Modifier.padding(18.dp)) {
                        Row(verticalAlignment=Alignment.CenterVertically){Icon(Icons.Default.LocationOn,null,tint=Green);Spacer(Modifier.width(8.dp));Text("Localização ao vivo",fontWeight=FontWeight.Bold,color=Green)}
                        Spacer(Modifier.height(8.dp))
                        val location=tracking?.location
                        Text(if(location!=null) "O entregador está em rota. A posição foi atualizada pelo GPS do EventMenu." else "A rota começou. A localização aparecerá assim que houver uma posição recente.",color=Color.DarkGray)
                        if(location!=null){Spacer(Modifier.height(12.dp));OutlinedButton(onClick={val uri=Uri.parse("geo:${location.latitude},${location.longitude}?q=${location.latitude},${location.longitude}");runCatching{context.startActivity(Intent(Intent.ACTION_VIEW,uri))}}){Icon(Icons.Default.Map,null);Spacer(Modifier.width(7.dp));Text("Ver no mapa")}}
                    }
                }
            }
        }
        item {
            Surface(shape=RoundedCornerShape(20.dp),color=Color.White){Column(Modifier.padding(18.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text("Resumo",fontWeight=FontWeight.Bold);order.items.forEach{item->Column{Row{Text("${formatQty(item.quantity)}× ${item.name}",Modifier.weight(1f));Text(item.totalCents.money())};item.modifiers.forEach{Text(it,color=Color.Gray,style=MaterialTheme.typography.bodySmall)}}};HorizontalDivider();PriceRow("Produtos",order.subtotalCents);if(order.discountCents>0)PriceRow("Descontos",-order.discountCents);PriceRow("Entrega",order.deliveryFeeCents);PriceRow("Total",order.totalCents,true)}}
        }
    }
}

@Composable
private fun TimelineRow(step: TimelineStep,last:Boolean){
    Row(Modifier.fillMaxWidth()){
        Column(horizontalAlignment=Alignment.CenterHorizontally){
            Surface(shape=RoundedCornerShape(99.dp),color=when{step.done->Green;step.current->Purple;else->Color(0xFFE5E5EA)},modifier=Modifier.size(28.dp)){Box(contentAlignment=Alignment.Center){if(step.done)Icon(Icons.Default.Check,null,tint=Color.White,modifier=Modifier.size(17.dp)) else if(step.current)Box(Modifier.size(8.dp).background(Color.White,RoundedCornerShape(99.dp)))}}
            if(!last) Box(Modifier.width(2.dp).height(26.dp).background(if(step.done)Green else Color(0xFFE5E5EA)))
        }
        Spacer(Modifier.width(12.dp));Text(step.label,fontWeight=if(step.current||step.done)FontWeight.SemiBold else FontWeight.Normal,color=if(step.current)PurpleDark else if(step.done)Color.DarkGray else Color.Gray,modifier=Modifier.padding(top=4.dp))
    }
}

@Composable private fun StepHeader(number:Int,title:String,subtitle:String){Row(verticalAlignment=Alignment.CenterVertically){Surface(color=Purple,shape=RoundedCornerShape(99.dp)){Text(number.toString(),color=Color.White,fontWeight=FontWeight.Bold,modifier=Modifier.padding(horizontal=11.dp,vertical=6.dp))};Spacer(Modifier.width(10.dp));Column{Text(title,fontWeight=FontWeight.Bold);Text(subtitle,color=Color.Gray,style=MaterialTheme.typography.bodySmall)}}}
@Composable private fun PriceRow(label:String,value:Int,bold:Boolean=false){Row{Text(label,Modifier.weight(1f),fontWeight=if(bold)FontWeight.Bold else FontWeight.Normal);Text(value.money(),fontWeight=if(bold)FontWeight.Black else FontWeight.Medium)}}
@Composable private fun EmptyState(icon: androidx.compose.ui.graphics.vector.ImageVector,title:String,text:String,action:String?=null,onAction:(()->Unit)?=null){Surface(shape=RoundedCornerShape(22.dp),color=Color.White){Column(Modifier.fillMaxWidth().padding(26.dp),horizontalAlignment=Alignment.CenterHorizontally){Icon(icon,null,tint=Purple,modifier=Modifier.size(42.dp));Spacer(Modifier.height(12.dp));Text(title,fontWeight=FontWeight.Bold);Spacer(Modifier.height(6.dp));Text(text,color=Color.Gray);if(action!=null&&onAction!=null){Spacer(Modifier.height(14.dp));OutlinedButton(onClick=onAction){Text(action)}}}}}
private fun modifierRule(group:ModifierGroup):String=when{group.minSelect>0&&group.minSelect==group.maxSelect->if(group.minSelect==1)"Obrigatório • escolha 1 opção" else "Obrigatório • escolha ${group.minSelect} opções";group.minSelect>0->"Obrigatório • escolha de ${group.minSelect} a ${group.maxSelect}";group.maxSelect==1->"Opcional • escolha até 1 opção";else->"Opcional • escolha até ${group.maxSelect} opções"}
private fun statusColor(status:String):Color=when(status){"completed"->Green;"cancelled"->Red;"pending","confirmed","preparing"->Orange;else->Purple}
private fun statusIcon(status:String)=when(status){"completed"->Icons.Default.CheckCircle;"cancelled"->Icons.Default.Cancel;"out_for_delivery"->Icons.Default.DeliveryDining;"ready"->Icons.Default.ShoppingBag;else->Icons.Default.Schedule}
private fun formatQty(value:Double):String=if(value%1.0==0.0)value.toInt().toString() else "%.2f".format(value).trimEnd('0').trimEnd(',','.')
