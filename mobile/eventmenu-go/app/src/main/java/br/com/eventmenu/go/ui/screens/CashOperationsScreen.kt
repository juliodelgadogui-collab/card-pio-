package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
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
import br.com.eventmenu.go.data.CashSummary

@Composable
fun CashOperationsScreen(
    open: Boolean,
    summary: CashSummary?,
    onOpen: (Int, String) -> Unit,
    onSupply: (Int, String) -> Unit,
    onWithdrawal: (Int, String) -> Unit,
    onClose: (Int, String) -> Unit,
    onRefresh: () -> Unit,
) {
    var openingDialog by remember { mutableStateOf(false) }
    var supplyDialog by remember { mutableStateOf(false) }
    var withdrawalDialog by remember { mutableStateOf(false) }
    var closeDialog by remember { mutableStateOf(false) }
    val session = summary?.session

    LazyColumn(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Caixa financeiro", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text(if (open) "🟢 ABERTO" else "⚪ FECHADO")
        }

        if (open && session != null && summary != null) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Caixa #${session.id}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Aberto em ${session.openedAt}")
                        Text("Fundo inicial: ${cashMoney(session.openingCashCents)}")
                        HorizontalDivider()
                        Text("DINHEIRO ESPERADO", fontWeight = FontWeight.Bold)
                        Text(cashMoney(summary.expectedCashCents), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                        Text("Esse valor é calculado pelo servidor: fundo inicial + entradas em dinheiro − saídas em dinheiro.")
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { supplyDialog = true }, modifier = Modifier.weight(1f)) { Text("SUPRIMENTO") }
                    OutlinedButton(onClick = { withdrawalDialog = true }, modifier = Modifier.weight(1f)) { Text("SANGRIA") }
                }
            }

            if (summary.digital.isNotEmpty()) {
                item { Text("Conciliação digital", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
                items(summary.digital, key = { it.provider }) { d ->
                    Card(Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                            Column { Text(providerLabel(d.provider), fontWeight = FontWeight.Bold); Text("${d.qty} pagamento(s)") }
                            Text(cashMoney(d.totalCents), fontWeight = FontWeight.Black)
                        }
                    }
                }
            }

            item { Text("Movimentos do caixa", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold) }
            items(summary.movements.take(40), key = { it.id }) { movement ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(Modifier.weight(1f)) {
                            Text(movementLabel(movement.type), fontWeight = FontWeight.Bold)
                            Text("${methodLabel(movement.method)} · ${if (movement.direction == "in") "Entrada" else "Saída"}")
                            if (movement.notes.isNotBlank()) Text(movement.notes)
                            if (movement.createdAt.isNotBlank()) Text(movement.createdAt, style = MaterialTheme.typography.bodySmall)
                        }
                        Text((if (movement.direction == "in") "+ " else "− ") + cashMoney(movement.amountCents), fontWeight = FontWeight.Black)
                    }
                }
            }
            item { Button(onClick = { closeDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("CONFERIR E FECHAR CAIXA") } }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Nenhum caixa aberto", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                        Text("Abra o caixa para receber dinheiro físico no PDV. PIX e cartão continuam sendo confirmados diretamente pelos provedores.")
                        Button(onClick = { openingDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("ABRIR CAIXA") }
                    }
                }
            }
            if (session?.status == "closed") {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Text("Último fechamento · Caixa #${session.id}", fontWeight = FontWeight.Bold)
                            Text("Esperado: ${cashMoney(session.expectedCashCents ?: summary?.expectedCashCents ?: 0)}")
                            Text("Contado: ${cashMoney(session.closingCashCents ?: 0)}")
                            Text("Diferença: ${cashMoney(session.differenceCents ?: 0)}")
                            if (session.closedAt.isNotBlank()) Text("Fechado em ${session.closedAt}")
                        }
                    }
                }
            }
        }

        item { OutlinedButton(onClick = onRefresh, modifier = Modifier.fillMaxWidth()) { Text("ATUALIZAR CAIXA") } }
    }

    if (openingDialog) MoneyDialog("Abrir caixa","Fundo inicial (R$)","Observação (opcional)",false,onDismiss={openingDialog=false},onConfirm={amount,notes->openingDialog=false;onOpen(amount,notes)})
    if (supplyDialog) MoneyDialog("Suprimento","Valor que entrou (R$)","Origem/observação (opcional)",false,onDismiss={supplyDialog=false},onConfirm={amount,notes->supplyDialog=false;onSupply(amount,notes)})
    if (withdrawalDialog) MoneyDialog("Sangria","Valor retirado (R$)","Motivo da sangria *",true,onDismiss={withdrawalDialog=false},onConfirm={amount,notes->withdrawalDialog=false;onWithdrawal(amount,notes)})
    if (closeDialog) MoneyDialog("Conferir fechamento","Dinheiro contado (R$)","Observação do fechamento (opcional)",false,hint="Esperado pelo sistema: ${cashMoney(summary?.expectedCashCents ?: 0)}. Informe o valor realmente contado; a diferença será registrada na auditoria.",onDismiss={closeDialog=false},onConfirm={amount,notes->closeDialog=false;onClose(amount,notes)})
}

@Composable
private fun MoneyDialog(title:String,valueLabel:String,notesLabel:String,requireNotes:Boolean,hint:String="",onDismiss:()->Unit,onConfirm:(Int,String)->Unit) {
    var value by remember { mutableStateOf("") };var notes by remember { mutableStateOf("") };val cents=((value.replace(',','.').toDoubleOrNull()?:0.0)*100).toInt()
    AlertDialog(
        onDismissRequest=onDismiss,
        title={Text(title)},
        text={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){if(hint.isNotBlank())Text(hint);OutlinedTextField(value,{value=it},label={Text(valueLabel)},singleLine=true,modifier=Modifier.fillMaxWidth());OutlinedTextField(notes,{notes=it.take(500)},label={Text(notesLabel)},modifier=Modifier.fillMaxWidth())}},
        confirmButton={Button(onClick={onConfirm(cents,notes.trim())},enabled=cents>=0&&(!requireNotes||notes.isNotBlank())&&(title=="Abrir caixa"||title=="Conferir fechamento"||cents>0)){Text("CONFIRMAR")}},
        dismissButton={TextButton(onClick=onDismiss){Text("CANCELAR")}},
    )
}

private fun movementLabel(type:String)=when(type){"sale"->"Venda";"supply"->"Suprimento";"withdrawal"->"Sangria";"refund"->"Estorno";"delivery_handoff"->"Repasse do entregador";"adjustment"->"Ajuste";else->type}
private fun methodLabel(method:String)=when(method){"cash"->"Dinheiro";"pix"->"PIX";"card"->"Cartão";else->method}
private fun providerLabel(provider:String)=when(provider){"pagbank"->"PagBank";"stripe"->"Stripe";"mercadopago"->"Mercado Pago";else->provider}
private fun cashMoney(cents:Int)="R$ %.2f".format(cents/100.0).replace('.',',')
