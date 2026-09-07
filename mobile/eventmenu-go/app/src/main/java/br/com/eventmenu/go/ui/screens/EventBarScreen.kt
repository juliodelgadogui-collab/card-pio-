package br.com.eventmenu.go.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.BuildConfig
import br.com.eventmenu.go.EventBarState
import kotlinx.coroutines.delay

@Composable
fun EventBarScreen(
    state: EventBarState,
    cashOpen: Boolean,
    canCash: Boolean,
    canPix: Boolean,
    canNfc: Boolean,
    onAdd: (Int) -> Unit,
    onRemove: (Int) -> Unit,
    onClearCart: () -> Unit,
    onCreate: (String) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int, String) -> Unit,
    onNfc: (Int,String,Int) -> Unit,
    onRefreshPayment: () -> Unit,
    onPollPix: () -> Unit,
    onDismissPix: () -> Unit,
    onReceipt: (Int) -> Unit,
    onPrint: (Int) -> Unit,
    onFinish: () -> Unit,
    onExit: () -> Unit,
) {
    val order = state.order
    val nfcAvailable = canNfc && BuildConfig.SUMUP_TAP_TO_PAY
    if (order != null) {
        EventBarPayment(
            state = state,
            cashOpen = cashOpen,
            canCash = canCash,
            canPix = canPix,
            canNfc = nfcAvailable,
            onCash = onCash,
            onPix = onPix,
            onNfc = onNfc,
            onRefresh = onRefreshPayment,
            onReceipt = onReceipt,
            onPrint = onPrint,
            onFinish = onFinish,
        )
        state.pixCharge?.let { charge -> EventBarPixDialog(charge.copyPaste, charge.amountCents, charge.expiresAt, onPollPix, onDismissPix) }
        return
    }

    var category by remember { mutableStateOf("Todos") }
    var notes by remember { mutableStateOf("") }
    val categories = listOf("Todos") + state.products.map { it.categoryName }.distinct()
    val visible = state.products.filter { category == "Todos" || it.categoryName == category }
    val lines = state.cart.mapNotNull { (id, qty) -> state.products.firstOrNull { it.id == id }?.let { it to qty } }
    val total = lines.sumOf { (product, qty) -> product.priceCents * qty }

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("🍹 Bar · ${state.eventName}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Venda vinculada ao evento. Estoque e preço são recalculados no servidor ao criar o pedido.")
            OutlinedButton(onClick = onExit, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("VOLTAR AO EVENTO") }
        }
        item { LazyRow(horizontalArrangement=Arrangement.spacedBy(7.dp)){items(categories){name->FilterChip(selected=category==name,onClick={category=name},label={Text(name)})}} }
        items(visible,key={it.id}){product->Card(Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(15.dp),horizontalArrangement=Arrangement.SpaceBetween){Column(Modifier.weight(1f)){Text(product.name,style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold);Text(product.categoryName);if(product.description.isNotBlank())Text(product.description,maxLines=2);Text(barMoney(product.priceCents),style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);if(product.trackStock)Text("Disponível: ${product.stockQty}")};Button(onClick={onAdd(product.id)},enabled=!product.trackStock||product.stockQty>0){Text("+")}}}}
        item { Card(Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("Carrinho",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);if(lines.isNotEmpty())TextButton(onClick=onClearCart){Text("LIMPAR")}};if(lines.isEmpty())Text("Nenhum item.");lines.forEach{(product,qty)->Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("${qty}× ${product.name}");Row(horizontalArrangement=Arrangement.spacedBy(6.dp)){Text(barMoney(product.priceCents*qty),fontWeight=FontWeight.Bold);OutlinedButton(onClick={onRemove(product.id)}){Text("−")};OutlinedButton(onClick={onAdd(product.id)}){Text("+")}}}};HorizontalDivider();Text("TOTAL ${barMoney(total)}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);OutlinedTextField(notes,{notes=it},label={Text("Observação da venda")},modifier=Modifier.fillMaxWidth());Button(onClick={onCreate(notes)},enabled=lines.isNotEmpty(),modifier=Modifier.fillMaxWidth()){Text("IR PARA PAGAMENTO")}}} }
    }
}

@Composable
private fun EventBarPayment(
    state: EventBarState,
    cashOpen: Boolean,
    canCash: Boolean,
    canPix: Boolean,
    canNfc: Boolean,
    onCash: (Int) -> Unit,
    onPix: (Int, String) -> Unit,
    onNfc: (Int,String,Int) -> Unit,
    onRefresh: () -> Unit,
    onReceipt: (Int) -> Unit,
    onPrint: (Int) -> Unit,
    onFinish: () -> Unit,
) {
    val order=state.order?:return;val balance=state.balance;val remaining=balance?.remainingCents?:order.totalCents
    var amountText by remember(remaining){mutableStateOf("%.2f".format(remaining/100.0).replace('.',','))};val amount=((amountText.replace(',','.').toDoubleOrNull()?:0.0)*100).toInt();var pixDialog by remember{mutableStateOf(false)};var cardDialog by remember{mutableStateOf(false)}
    LazyColumn(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{Text("🍹 Bar · ${state.eventName}",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black);Text("Venda #${order.id}");Text("Total: ${barMoney(balance?.totalCents?:order.totalCents)}");Text("Pago: ${barMoney(balance?.paidCents?:0)}");Text("Restante: ${barMoney(remaining)}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)}
        if(balance?.payments?.isNotEmpty()==true){item{Text("Pagamentos",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)};items(balance.payments,key={it.id}){part->Card(Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(13.dp),horizontalArrangement=Arrangement.SpaceBetween){Text("${barPaymentLabel(part.provider)} · ${part.status}");Text(barMoney(part.amountCents),fontWeight=FontWeight.Black)}}}}
        if(remaining>0){item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){Text("Receber",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("Pagamento dividido é permitido. Informe o valor desta parcela.");OutlinedTextField(amountText,{amountText=it},label={Text("Parcela (R$)")},singleLine=true,modifier=Modifier.fillMaxWidth());if(canCash){Button(onClick={onCash(amount)},enabled=cashOpen&&amount in 1..remaining,modifier=Modifier.fillMaxWidth()){Text("DINHEIRO")};if(!cashOpen)Text("Abra o caixa financeiro para receber dinheiro.")};if(canPix)Button(onClick={pixDialog=true},enabled=amount in 1..remaining,modifier=Modifier.fillMaxWidth()){Text("PIX")};if(canNfc)Button(onClick={cardDialog=true},enabled=amount in 100..remaining,modifier=Modifier.fillMaxWidth()){Text("CRÉDITO / DÉBITO · APROXIMAÇÃO")};if(!canCash&&!canPix&&!canNfc)Text("Sua conta pode lançar consumo no Bar, mas não possui permissão de recebimento. Solicite um Caixa/operador Pay.")}}}
        }else{item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(18.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){Text("✅ PAGAMENTO CONFIRMADO",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text("O servidor confirmou o valor integral da venda.");OutlinedButton(onClick={onReceipt(order.id)},modifier=Modifier.fillMaxWidth()){Text("ENVIAR COMPROVANTE")};OutlinedButton(onClick={onPrint(order.id)},modifier=Modifier.fillMaxWidth()){Text("IMPRIMIR")};Button(onClick=onFinish,modifier=Modifier.fillMaxWidth()){Text("ENTREGAR E VOLTAR AO EVENTO")}}}}
        item{OutlinedButton(onClick=onRefresh,modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR PAGAMENTO")}}
    }
    if(pixDialog){var taxId by remember{mutableStateOf("")};AlertDialog(onDismissRequest={pixDialog=false},title={Text("PIX · ${barMoney(amount)}")},text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){Text("CPF/CNPJ pode ser exigido pelo provedor para emitir o QR PIX e não é salvo pelo app.");OutlinedTextField(taxId,{taxId=it.filter(Char::isDigit).take(14)},label={Text("CPF ou CNPJ")},singleLine=true)}},confirmButton={Button(onClick={pixDialog=false;onPix(amount,taxId)},enabled=taxId.length in setOf(11,14)){Text("GERAR PIX")}},dismissButton={TextButton(onClick={pixDialog=false}){Text("CANCELAR")}})}
    if(canNfc&&cardDialog){CardMethodDialog(amountCents=amount,onDismiss={cardDialog=false},onConfirm={method,installments->cardDialog=false;onNfc(amount,method,installments)})}
}

@Composable
private fun EventBarPixDialog(copyPaste:String,amountCents:Int,expiresAt:String,onPoll:()->Unit,onDismiss:()->Unit){val context=LocalContext.current;val qr=remember(copyPaste){qrBitmap(copyPaste)};LaunchedEffect(copyPaste){while(true){delay(2500);onPoll()}};AlertDialog(onDismissRequest=onDismiss,title={Text("PIX · ${barMoney(amountCents)}")},text={Column(verticalArrangement=Arrangement.spacedBy(10.dp)){qr?.let{Image(it.asImageBitmap(),contentDescription="QR PIX do Bar",modifier=Modifier.fillMaxWidth())};Text("⏳ Aguardando confirmação do servidor…");if(expiresAt.isNotBlank())Text("Validade: $expiresAt");OutlinedButton(onClick={copyPix(context,copyPaste)},modifier=Modifier.fillMaxWidth()){Text("COPIAR CÓDIGO PIX")}}},confirmButton={TextButton(onClick=onDismiss){Text("FECHAR")}})}
private fun copyPix(context:Context,text:String){(context.getSystemService(Context.CLIPBOARD_SERVICE)as ClipboardManager).setPrimaryClip(ClipData.newPlainText("PIX EventMenu Bar",text))}
private fun barPaymentLabel(provider:String)=when(provider){"manual"->"Dinheiro";"terminal_manual"->"Maquininha";"sumup"->"SumUp";"pagbank"->"PagBank";"stripe"->"Stripe";"mercadopago"->"Mercado Pago";else->provider}
private fun barMoney(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
