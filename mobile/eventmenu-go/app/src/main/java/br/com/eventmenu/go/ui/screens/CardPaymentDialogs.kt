package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
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
import br.com.eventmenu.go.CardPaymentPhase
import br.com.eventmenu.go.CardPaymentUiState

@Composable
fun CardMethodDialog(
    amountCents: Int,
    onDismiss: () -> Unit,
    onConfirm: (method: String, installments: Int) -> Unit,
) {
    var method by remember { mutableStateOf("credit") }
    var installmentsText by remember { mutableStateOf("1") }
    val installments = if (method == "debit") 1 else (installmentsText.toIntOrNull() ?: 1).coerceIn(1, 12)

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Cartão por aproximação") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(androidx.compose.ui.unit.dp(10f))) {
                Text("Valor: ${cardMoney(amountCents)}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                Text("O cliente aproxima o cartão ou carteira digital diretamente no celular com NFC.")
                Row(horizontalArrangement = Arrangement.spacedBy(androidx.compose.ui.unit.dp(8f))) {
                    FilterChip(
                        selected = method == "credit",
                        onClick = { method = "credit" },
                        label = { Text("Crédito") },
                    )
                    FilterChip(
                        selected = method == "debit",
                        onClick = { method = "debit"; installmentsText = "1" },
                        label = { Text("Débito") },
                    )
                }
                if (method == "credit") {
                    OutlinedTextField(
                        value = installmentsText,
                        onValueChange = { installmentsText = it.filter(Char::isDigit).take(2) },
                        label = { Text("Parcelas · 1 a 12") },
                        supportingText = { Text("A disponibilidade final depende da conta SumUp e do cartão do cliente.") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else {
                    Text("Débito é processado em 1 vez.")
                }
            }
        },
        confirmButton = {
            Button(onClick = { onConfirm(method, installments) }) {
                Text("INICIAR APROXIMAÇÃO")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("CANCELAR") } },
    )
}

@Composable
fun CardPaymentStatusDialog(
    state: CardPaymentUiState,
    onRetry: () -> Unit,
    onDismiss: () -> Unit,
) {
    if (!state.visible) return
    val title = when (state.phase) {
        CardPaymentPhase.PREPARING -> "Preparando pagamento"
        CardPaymentPhase.AWAITING_CARD -> "Aproxime o cartão"
        CardPaymentPhase.PROCESSING -> "Processando"
        CardPaymentPhase.VALIDATING -> "Confirmando no servidor"
        CardPaymentPhase.PENDING_CONFIRMATION -> "Confirmação pendente"
        CardPaymentPhase.APPROVED -> "Pagamento confirmado"
        CardPaymentPhase.CANCELLED -> "Pagamento cancelado"
        CardPaymentPhase.FAILED -> "Pagamento não concluído"
        CardPaymentPhase.IDLE -> return
    }
    AlertDialog(
        onDismissRequest = { if (!state.blocking) onDismiss() },
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(androidx.compose.ui.unit.dp(8f))) {
                state.orderId?.let { Text("Pedido #$it · ${cardMoney(state.amountCents)}", fontWeight = FontWeight.Bold) }
                if (state.provider.isNotBlank()) Text("Provedor: ${state.provider.uppercase()}")
                Text(state.message ?: "Aguarde…")
                if (state.phase == CardPaymentPhase.PENDING_CONFIRMATION) {
                    Text(
                        "Não faça uma segunda cobrança enquanto o resultado estiver incerto. O EventMenu consulta a transação pelo identificador criado antes da aproximação.",
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            }
        },
        confirmButton = {
            when {
                state.canRetryVerification -> Button(onClick = onRetry) { Text("VERIFICAR NOVAMENTE") }
                !state.blocking -> Button(onClick = onDismiss) { Text("FECHAR") }
                else -> TextButton(onClick = {}, enabled = false) { Text("AGUARDE") }
            }
        },
    )
}

private fun cardMoney(cents: Int): String = "R$ %.2f".format(cents / 100.0).replace('.', ',')
