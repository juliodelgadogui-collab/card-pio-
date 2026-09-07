package br.com.eventmenu.go.ui.screens

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
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.eventmenu.go.AppScreen
import br.com.eventmenu.go.EventMenuGoApplication
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.PaymentOptionsViewModel
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
    onNfc: (Int,String,Int) -> Unit,
    onReceipt: (Int) -> Unit,
    onPrintReceipt: (Int) -> Unit,
    onRefreshPayment: () -> Unit,
    onFinishFlow: () -> Unit,
    onClearTable: () -> Unit,
) {
    val app=LocalContext.current.applicationContext as EventMenuGoApplication
    val paymentOptionsViewModel:PaymentOptionsViewModel=viewModel(factory=PaymentOptionsViewModel.Factory(app.paymentRepository))
    val paymentOptions by paymentOptionsViewModel.state.collectAsState()
    val order=state.posOrder

    LaunchedEffect(order?.id){if(order!=null)paymentOptionsViewModel.refresh()}
    LaunchedEffect(paymentOptions.completedVersion){if(paymentOptions.completedVersion>0)onRefreshPayment()}

    if(order!=null){
        PosPaymentScreen(
            state=state,
            discountRequest=discountRequest,
            canRequestDiscount=canRequestDiscount,
            pixAvailable=paymentOptions.pixAvailable,
            cashAvailable=paymentOptions.cashAvailable,
            externalTerminalAvailable=paymentOptions.externalTerminalAvailable,
            externalTerminalReferenceRequired=paymentOptions.externalTerminalReferenceRequired,
            nfcAvailable=paymentOptions.cardPresentAvailableInThisApk,
            nfcConfiguredButUnavailable=paymentOptions.nfcConfiguredButUnavailable,
            paymentOptionsLoading=paymentOptions.loading,
            paymentOptionsError=paymentOptions.error,
            paymentOptionsMessage=paymentOptions.message,
            onRequestDiscount=onRequestDiscount,
            onRefreshDiscount=onRefreshDiscount,
            onCash=onCash,
            onPix=onPix,
            onExternalTerminal={amount,method,machine,reference->paymentOptionsViewModel.confirmExternalTerminal(order.id,amount,method,machine,reference)},
            onNfc=onNfc,
            onReceipt=onReceipt,
            onPrintReceipt=onPrintReceipt,
            onRefresh={paymentOptionsViewModel.refresh();onRefreshPayment()},
            onFinishFlow=onFinishFlow,
        )
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
                        Text(product.priceCents.toMoney(),style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
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
                                Text((product.priceCents*qty).toMoney(),fontWeight=FontWeight.Bold)
                                OutlinedButton(onClick={onRemove(product.id)}){Text("−")}
                                OutlinedButton(onClick={onAdd(product.id)}){Text("+")}
                            }
                        }
                    }
                    HorizontalDivider()
                    Text("TOTAL ${previewTotal.toMoney()}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)
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
    pixAvailable: Boolean,
    cashAvailable: Boolean,
    externalTerminalAvailable: Boolean,
    externalTerminalReferenceRequired: Boolean,
    nfcAvailable: Boolean,
    nfcConfiguredButUnavailable: Boolean,
    paymentOptionsLoading:Boolean,
    paymentOptionsError:String?,
    paymentOptionsMessage:String?,
    onRequestDiscount: (Int, Int, String) -> Unit,
    onRefreshDiscount: (Int) -> Unit,
    onCash: (Int) -> Unit,
    onPix: (Int,String) -> Unit,
    onExternalTerminal: (Int,String,String,String) -> Unit,
    onNfc: (Int,String,Int) -> Unit,
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
    var cardDialog by remember{mutableStateOf(false)}
    var terminalDialog by remember{mutableStateOf(false)}

    LazyColumn(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{
            Text("Pagamento · Pedido #${order.id}",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black)
            if(state.posReturnScreen==AppScreen.TABLE_ACCOUNT)Text("Recebimento vinculado à comanda da mesa.")
            Text("Total: ${(balance?.totalCents?:order.totalCents).toMoney()}")
            Text("Pago: ${(balance?.paidCents?:0).toMoney()}")
            Text("Restante: ${remaining.toMoney()}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)
            if(paymentOptionsLoading)Text("Atualizando formas de pagamento…")
            paymentOptionsError?.let{Text("⚠️ $it")}
            paymentOptionsMessage?.let{Text("✅ $it")}
        }

        if(canRequestDiscount && (balance?.paidCents?:0)==0 && remaining>0){
            item{
                Card(Modifier.fillMaxWidth()){
                    Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(7.dp)){
                        Text("Desconto",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)
                        when(discountRequest?.status){
                            "pending"->{Text("⏳ Aguardando aprovação · ${discountLabel(discountRequest)}");Text(discountRequest.reason);OutlinedButton(onClick={onRefreshDiscount(order.id)},modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR APROVAÇÃO")}}
                            "approved"->{Text("✅ Desconto aplicado · ${discountLabel(discountRequest)}",fontWeight=FontWeight.Bold);if(discountRequest.autoApproved)Text("Aprovado automaticamente pela política da empresa.")else Text("Autorizado por gerente/administrador.")}
                            "rejected"->Text("❌ Última solicitação não foi aprovada.")
                            else->Text("O servidor decide se o desconto é automático ou se precisa de aprovação, conforme o limite do seu cargo.")
                        }
                        if(discountRequest?.status!="pending")OutlinedButton(onClick={discountDialog=true},modifier=Modifier.fillMaxWidth()){Text("APLICAR DESCONTO")}
                    }
                }
            }
        }

        if(balance?.payments?.isNotEmpty()==true){
            item{Text("Pagamentos",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold)}
            items(balance.payments,key={it.id}){part->
                Card(Modifier.fillMaxWidth()){
                    Row(Modifier.fillMaxWidth().padding(14.dp),horizontalArrangement=Arrangement.SpaceBetween){
                        Column(Modifier.weight(1f)){
                            Text("${paymentLabel(part.provider)} · #${part.id}",fontWeight=FontWeight.Bold)
                            Text(part.status)
                            if(part.paymentMethod.isNotBlank())Text(if(part.paymentMethod=="debit")"Débito" else if(part.paymentMethod=="credit")"Crédito" else part.paymentMethod)
                            if(part.machineLabel.isNotBlank())Text(part.machineLabel)
                        }
                        Text(part.amountCents.toMoney(),fontWeight=FontWeight.Black)
                    }
                }
            }
        }
        if(remaining>0){
            item{
                Card(Modifier.fillMaxWidth()){
                    Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){
                        Text("Adicionar pagamento",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black)
                        Text("Para pagamento dividido, informe o valor desta parcela.")
                        OutlinedTextField(amountText,{amountText=it},label={Text("Valor da parcela (R$)")},singleLine=true,modifier=Modifier.fillMaxWidth())
                        Text("Saldo máximo: ${remaining.toMoney()}")

                        if(cashAvailable){
                            Button(onClick={onCash(amount)},enabled=state.cashOpen&&amount in 1..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("DINHEIRO")}
                            if(!state.cashOpen)Text("Abra o caixa financeiro para receber dinheiro.")
                        }
                        if(pixAvailable){
                            Button(onClick={pixTaxDialog=true},enabled=amount in 1..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("PIX · GERAR QR CODE")}
                        }
                        if(externalTerminalAvailable){
                            OutlinedButton(onClick={terminalDialog=true},enabled=amount in 1..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("PAGO NA MAQUININHA")}
                        }
                        if(nfcAvailable){
                            Button(onClick={cardDialog=true},enabled=amount in 100..remaining&&discountRequest?.status!="pending",modifier=Modifier.fillMaxWidth()){Text("CRÉDITO / DÉBITO · APROXIMAÇÃO")}
                        }else if(nfcConfiguredButUnavailable){
                            Text("NFC está habilitado no servidor, mas esta versão do EventMenu GO foi instalada sem o SDK Tap to Pay.")
                        }
                        if(!pixAvailable&&!cashAvailable&&!externalTerminalAvailable&&!nfcAvailable){
                            Text("Nenhuma forma de pagamento está liberada para esta empresa. Solicite ao administrador.")
                        }
                        if(discountRequest?.status=="pending")Text("Pagamento bloqueado enquanto o desconto aguarda decisão. O servidor também rejeita alteração de preço com cobrança ativa.")
                    }
                }
            }
        }else{
            item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){Text("✅ PAGAMENTO CONCLUÍDO",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text("O servidor confirmou que a soma das parcelas atingiu exatamente o total do pedido.");Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick={onReceipt(order.id)},modifier=Modifier.weight(1f)){Text("ENVIAR")};OutlinedButton(onClick={onPrintReceipt(order.id)},modifier=Modifier.weight(1f)){Text("IMPRIMIR")}};Button(onClick=onFinishFlow,modifier=Modifier.fillMaxWidth()){Text(if(state.posReturnScreen==AppScreen.TABLE_ACCOUNT)"VOLTAR À CONTA" else "NOVO PEDIDO")}}}}
        }
        item{OutlinedButton(onClick={onRefresh();onRefreshDiscount(order.id)},modifier=Modifier.fillMaxWidth()){Text("ATUALIZAR PAGAMENTO")}}
    }

    if(discountDialog){
        var type by remember{mutableStateOf("fixed")}
        var value by remember{mutableStateOf("")}
        var reason by remember{mutableStateOf("")}
        val numeric=value.replace(',','.').toDoubleOrNull()?:0.0
        val cents=(numeric*100).toInt()
        val bps=(numeric*100).toInt()
        val baseTotal=balance?.totalCents?:order.totalCents
        val estimatedPercentDiscount=(baseTotal*(bps/10000.0)).toInt()
        val encoded=if(type=="percent")-bps else cents
        val valid=if(type=="percent")bps in 1..10000 else cents in 1..baseTotal
        AlertDialog(
            onDismissRequest={discountDialog=false},
            title={Text("Aplicar desconto")},
            text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){
                Text("Pedido #${order.id} · total atual ${baseTotal.toMoney()}")
                Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){
                    FilterChip(selected=type=="fixed",onClick={type="fixed";value=""},label={Text("R$")})
                    FilterChip(selected=type=="percent",onClick={type="percent";value=""},label={Text("%")})
                }
                OutlinedTextField(value,{value=it},label={Text(if(type=="percent")"Percentual do desconto" else "Valor do desconto (R$)")},singleLine=true,modifier=Modifier.fillMaxWidth(),supportingText={if(type=="percent"&&bps>0)Text("Estimativa: ${estimatedPercentDiscount.toMoney()}")})
                OutlinedTextField(reason,{reason=it.take(500)},label={Text("Motivo")},modifier=Modifier.fillMaxWidth())
                Text("Dentro do limite do seu cargo, o servidor aplica na hora. Acima do limite, envia para aprovação.")
            }},
            confirmButton={Button(onClick={discountDialog=false;onRequestDiscount(order.id,encoded,reason)},enabled=valid&&reason.isNotBlank()){Text("CONFIRMAR")}},
            dismissButton={TextButton(onClick={discountDialog=false}){Text("CANCELAR")}},
        )
    }

    if(pixTaxDialog){
        var taxId by remember{mutableStateOf("")}
        AlertDialog(
            onDismissRequest={pixTaxDialog=false},
            title={Text("PIX · ${amount.toMoney()}")},
            text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){
                Text("O EventMenu gera o QR/copia-e-cola no provedor configurado e só confirma após validar o pagamento no servidor.")
                OutlinedTextField(taxId,{taxId=it.filter(Char::isDigit).take(14)},label={Text("CPF ou CNPJ")},singleLine=true)
            }},
            confirmButton={Button(onClick={pixTaxDialog=false;onPix(amount,taxId)},enabled=taxId.length in setOf(11,14)){Text("GERAR PIX")}},
            dismissButton={TextButton(onClick={pixTaxDialog=false}){Text("CANCELAR")}},
        )
    }

    if(terminalDialog){
        var method by remember{mutableStateOf("credit")}
        var machine by remember{mutableStateOf("")}
        var reference by remember{mutableStateOf("")}
        AlertDialog(
            onDismissRequest={terminalDialog=false},
            title={Text("Pago na maquininha · ${amount.toMoney()}")},
            text={Column(verticalArrangement=Arrangement.spacedBy(9.dp)){
                Text("Use somente depois que a maquininha externa mostrar APROVADO. Este registro fica vinculado ao operador, pedido e aparelho.")
                Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){
                    FilterChip(selected=method=="credit",onClick={method="credit"},label={Text("Crédito")})
                    FilterChip(selected=method=="debit",onClick={method="debit"},label={Text("Débito")})
                }
                OutlinedTextField(machine,{machine=it.take(120)},label={Text("Maquininha (opcional)")},placeholder={Text("Ex.: Cielo balcão")},modifier=Modifier.fillMaxWidth())
                OutlinedTextField(reference,{reference=it.take(190)},label={Text(if(externalTerminalReferenceRequired)"NSU / código da transação *" else "NSU / código da transação")},modifier=Modifier.fillMaxWidth())
                Text("O EventMenu não consegue consultar uma máquina sem integração. Por isso o NSU é recomendado para conferência e evita registro duplicado.")
            }},
            confirmButton={Button(onClick={terminalDialog=false;onExternalTerminal(amount,method,machine,reference)},enabled=amount in 1..remaining&&(!externalTerminalReferenceRequired||reference.isNotBlank())){Text("CONFIRMAR PAGO")}},
            dismissButton={TextButton(onClick={terminalDialog=false}){Text("CANCELAR")}},
        )
    }

    if(cardDialog){
        CardMethodDialog(
            amountCents=amount,
            onDismiss={cardDialog=false},
            onConfirm={method,installments->cardDialog=false;onNfc(amount,method,installments)},
        )
    }
}

private fun discountLabel(request:DiscountRequest):String=if(request.discountType=="percent"&&request.requestedBps>0)"%.2f%% · %s".format(request.requestedBps/100.0,request.requestedCents.toMoney()) else request.requestedCents.toMoney()
private fun paymentLabel(provider:String)=when(provider){"manual"->"Dinheiro";"terminal_manual"->"Maquininha";"sumup"->"SumUp";"pagbank"->"PagBank";"stripe"->"Stripe";"mercadopago"->"Mercado Pago";"pagarme"->"Pagar.me";"asaas"->"Asaas";"openpix"->"OpenPix";else->provider}
private fun Int.toMoney()="R$ %.2f".format(this/100.0).replace('.',',')
