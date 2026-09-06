package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.weight
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.data.DiscountRequest

@Composable
fun PosScreen(
    state: GoState,
    discountRequest: DiscountRequest?,
    canRequestDiscount: Boolean,
    onRequestDiscount: (Int, Int, String) -> Unit,
    onRefreshDiscount: (Int) -> Unit,
    onAdd: (Int) -> Unit,
    onRemove: (Int) -> Unit,
    onClear: () -> Unit,
    onCreate: (String,String,String,String,String) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int,String) -> Unit,
    onNfc: (Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onRefreshPayment: () -> Unit,
    onFinishFlow: () -> Unit,
    onClearTable: () -> Unit,
) {
    val order=state.posOrder
    if(order!=null){
        PosPaymentScreen(state,discountRequest,canRequestDiscount,onRequestDiscount,onRefreshDiscount,onCash,onPix,onNfc,onReceipt,onPrintReceipt,onRefreshPayment,onFinishFlow)
        return
    }

    val table=state.selectedTable
    var category by remember { mutableStateOf("Todos") }
    var channel by remember(table?.id) { mutableStateOf(if(table!=null)"table" else "counter") }
    var customer by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val categories=listOf("Todos")+state.products.map{it.categoryName}.distinct()
    val visible=state.products.filter{category=="Todos"||it.categoryName==category}
    val cartLines=state.cart.mapNotNull{(id,qty)->state.products.firstOrNull{it.id==id}?.let{it to qty}}
    val previewTotal=cartLines.sumOf{(p,qty)->p.priceCents*qty}

    LazyColumn(Modifier.fillMaxSize().padding(14.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{
            Text(if(table!=null)"PDV · ${table.name}" else "Caixa / PDV",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black)
            if(table!=null){
                Text("Comanda #${table.tabId} · ${table.tabLabel.ifBlank{"Sem identificação"}}")
                Text("O pedido será lançado na comanda e seguirá para a operação sem exigir pagamento imediato.")
                OutlinedButton(onClick=onClearTable,modifier=Modifier.fillMaxWidth().padding(top=8.dp)){Text("VOLTAR AO SALÃO")}
            }else Text("Valores exibidos são uma prévia; o servidor recalcula preço e estoque ao finalizar.")
        }
        item{
            LazyRow(horizontalArrangement=Arrangement.spacedBy(7.dp)){
                items(categories){name->FilterChip(selected=category==name,onClick={category=name},label={Text(name)})}
            }
        }
        items(visible,key={it.id}){product->
            Card(Modifier.fillMaxWidth()){
                Row(Modifier.fillMaxWidth().padding(16.dp),horizontalArrangement=Arrangement.SpaceBetween){
                    Column(Modifier.weight(1f)){
                        Text(product.name,style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)
                        Text(product.categoryName)
                        if(product.description.isNotBlank())Text(product.description,maxLines=2)
                        Text(posMoney(product.priceCents),style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
                        if(product.trackStock)Text("Disponível: ${product.stockQty}")
                    }
                    Button(onClick={onAdd(product.id)},enabled=!product.trackStock||product.stockQty>0){Text("+")}
                }
            }
        }
        item{
            Card(Modifier.fillMaxWidth()){
                Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){
                    Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){
                        Text("Carrinho",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
                        if(cartLines.isNotEmpty())TextButton(onClick=onClear){Text("LIMPAR")}
                    }
                    if(cartLines.isEmpty())Text("Nenhum item.")
                    cartLines.forEach{(product,qty)->
                        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){
                            Text("${qty}× ${product.name}")
                            Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){
                                Text(posMoney(product.priceCents*qty),fontWeight=FontWeight.Bold)
                                OutlinedButton(onClick={onRemove(product.id)}){Text("−")}
                                OutlinedButton(onClick={onAdd(product.id)}){Text("+")}
                            }
                        }
                    }
                    HorizontalDivider()
                    Text("TOTAL ${posMoney(previewTotal)}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)
                }
            }
        }
        item{
            Card(Modifier.fillMaxWidth()){
                Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){
                    if(table==null){
                        Text("Origem do pedido",fontWeight=FontWeight.Bold)
                        Row(horizontalArrangement=Arrangement.spacedBy(6.dp)){
                            FilterChip(selected=channel=="counter",onClick={channel="counter"},label={Text("Balcão")})
                            FilterChip(selected=channel=="pickup",onClick={channel="pickup"},label={Text("Retirada")})
                            FilterChip(selected=channel=="delivery",onClick={channel="delivery"},label={Text("Delivery")})
                        }
                    }else Text("Pedido para ${table.name}",fontWeight=FontWeight.Bold)
                    OutlinedTextField(customer,{customer=it},label={Text(if(channel=="delivery")"Cliente *" else "Cliente (opcional)")},modifier=Modifier.fillMaxWidth())
                    OutlinedTextField(phone,{phone=it},label={Text(if(channel=="delivery")"Telefone *" else "Telefone (opcional)")},modifier=Modifier.fillMaxWidth())
                    if(channel=="delivery")OutlinedTextField(address,{address=it},label={Text("Endereço *")},modifier=Modifier.fillMaxWidth())
                    OutlinedTextField(notes,{notes=it},label={Text("Observações")},modifier=Modifier.fillMaxWidth())
                    Button(onClick={onCreate(channel,customer,phone,address,notes)},enabled=cartLines.isNotEmpty()&&(channel!="delivery"||(customer.isNotBlank()&&phone.isNotBlank()&&address.isNotBlank())),modifier=Modifier.fillMaxWidth()){Text(if(table!=null)"LANÇAR NA MESA" else "FINALIZAR PEDIDO")}
                }
            }
        }
    }
}

@Composable
private fun PosPaymentScreen(
    state: GoState,
    discountRequest: DiscountRequest?,
    canRequestDiscount: Boolean,
    onRequestDiscount: (Int, Int, String) -> Unit,
    onRefreshDiscount: (Int) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int,String) -> Unit,
    onNfc: (Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onRefresh: () -> Unit,
    onFinishFlow: () -> Unit,
){
    val order=state.posOrder?:return
    val balance=state.paymentBalance
    val remaining=balance?.remainingCents?:order.totalCents
    var amountText by remember(remaining){mutableStateOf("%.2f".format(remaining/100.0).replace('.',','))}
    val amount=((amountText.replace(',','.').toDoubleOrNull()?:0.0)*100).toInt()
    var pixTaxDialog by remember{mutableStateOf(false)}
    var discountDialog by remember{mutableStateOf(false)}

    LazyColumn(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{
            Text("Pagamento · Pedido #${order.id}",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black)
            if(state.posReturnScreen==AppScreen.TABLE_ACCOUNT)Text("Recebimento vinculado à comanda da mesa.")
            Text("Total: ${posMoney(balance?.totalCents?:order.totalCents)}")
            Text("Pago: ${posMoney(balance?.paidCents?:0)}")
            Text("Restante: ${posMoney(remaining)}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)
        }

        if(canRequestDiscount && (balance?.paidCents?:0)==0 && remaining>0){
            item{
                Card(Modifier.fillMaxWidth()){
                    Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(7.dp)){
                        Text("Desconto",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)
                        when(discountRequest?.status){
                            "pending"->{Text("⏳ Aguardando aprovação do gerente · ${posMoney(discountRequest.requestedCents)}");Text(discountRequest.reason);OutlinedButton(onClick={onRefreshDiscount(order.id)},modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR APROVAÇÃO")}}
                            "approved"->{Text("✅ Desconto aprovado · ${posMoney(discountRequest.requestedCents)}",fontWeight=FontWeight.Bold);Text("O total exibido acima é recalculado pelo servidor.")}
                            "rejected"->Text("❌ Última solicitação não foi aprovada.")
                            else->Text("O caixa solicita; somente funcionário com permissão de aprovação altera o total.")
                        }
                        if(discountRequest?.status!="pending")OutlinedButton(onClick={discountDialog=true},modifier=Modifier.fillMaxWidth()){Text("SOLICITAR DESCONTO")}
                    }
                }
            }
        }

        if(balance?.payments?.isNotEmpty()==true){
            item{Text("Parcelas",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)}
            items(balance.payments,key={it.id}){part->Card(Modifier.fillMaxWidth()){Row(Modifier.fillMaxWidth().padding(14.dp),horizontalArrangement=Arrangement.SpaceBetween){Column{Text("${paymentLabel(part.provider)} · #${part.id}",fontWeight=FontWeight.Bold);Text(part.status)};Text(posMoney(part.amountCents),fontWeight=FontWeight.Black)}}}
        }
        if(remaining>0){
            item{
                Card(Modifier.fillMaxWidth()){
                    Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){
                        Text("Adicionar pagamento",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
                        Text("Para pagamento dividido, informe o valor desta parcela.")
                        OutlinedTextField(amountText,{amountText=it},label={Text("Valor da parcela (R$)")},singleLine=true,modifier=Modifier.fillMaxWidth())
                        Text("Saldo máximo: ${posMoney(remaining)}")
                        Button(onClick={onCash(amount)},enabled=state.cashOpen&&amount in 1..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("DINHEIRO")}
                        if(!state.cashOpen)Text("Abra o caixa financeiro para receber dinheiro.")
                        Button(onClick={pixTaxDialog=true},enabled=amount in 1..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("PIX")}
                        Button(onClick={onNfc(amount)},enabled=amount in 100..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("CRÉDITO / DÉBITO · NFC")}
                        if(discountRequest?.status=="pending")Text("Pagamento bloqueado na tela enquanto o desconto aguarda decisão. O servidor também rejeita alteração de total com cobrança ativa.")
                    }
                }
            }
        }else{
            item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){Text("✅ PAGAMENTO CONCLUÍDO",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text("O servidor confirmou que a soma das parcelas atingiu exatamente o total do pedido.");Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick={onReceipt(order.id)},modifier=Modifier.weight(1f)){Text("ENVIAR")};OutlinedButton(onClick={onPrintReceipt(order.id)},modifier=Modifier.weight(1f)){Text("IMPRIMIR")}};Button(onClick=onFinishFlow,modifier=Modifier.fillMaxWidth()){Text(if(state.posReturnScreen==AppScreen.TABLE_ACCOUNT)"VOLTAR À CONTA" else "NOVO PEDIDO")}}}}
        }
        item{OutlinedButton(onClick={onRefresh();onRefreshDiscount(order.id)},modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR PAGAMENTO")}}
    }

    if(discountDialog){
        var value by remember{mutableStateOf("")};var reason by remember{mutableStateOf("")};val cents=((value.replace(',','.').toDoubleOrNull()?:0.0)*100).toInt()
        AlertDialog(onDismissRequest={discountDialog=false},title={Text("Solicitar desconto")},text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){Text("Pedido #${order.id}");OutlinedTextField(value,{value=it},label={Text("Valor do desconto (R$)")},singleLine=true);OutlinedTextField(reason,{reason=it.take(500)},label={Text("Motivo obrigatório")},modifier=Modifier.fillMaxWidth());Text("O valor do pedido só muda depois que o servidor registrar a aprovação.")}},confirmButton={Button(onClick={discountDialog=false;onRequestDiscount(order.id,cents,reason)},enabled=cents>0&&reason.isNotBlank()){Text("ENVIAR AO GERENTE")}},dismissButton={TextButton(onClick={discountDialog=false}){Text("CANCELAR")}})
    }

    if(pixTaxDialog){
        var taxId by remember{mutableStateOf("")}
        AlertDialog(onDismissRequest={pixTaxDialog=false},title={Text("PIX · ${posMoney(amount)}")},text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){Text("CPF/CNPJ é exigido pelo PagBank para emitir o QR PIX e não é salvo pelo app.");OutlinedTextField(taxId,{taxId=it.filter(Char::isDigit).take(14)},label={Text("CPF ou CNPJ")},singleLine=true)}},confirmButton={Button(onClick={pixTaxDialog=false;onPix(amount,taxId)},enabled=taxId.length in setOf(11,14)){Text("GERAR PIX")}},dismissButton={TextButton(onClick={pixTaxDialog=false}){Text("CANCELAR")}})
    }
}

private fun paymentLabel(provider:String)=when(provider){"manual"->"Dinheiro";"pagbank"->"PagBank";"stripe"->"Stripe";"mercadopago"->"Mercado Pago";else->provider}
private fun posMoney(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
