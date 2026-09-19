package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import com.mercadopago.sdk.android.coremethods.domain.model.BuyerIdentification
import com.mercadopago.sdk.android.coremethods.domain.utils.Result
import com.mercadopago.sdk.android.coremethods.ui.components.textfield.cardnumber.CardNumberTextField
import com.mercadopago.sdk.android.coremethods.ui.components.textfield.cardnumber.CardNumberTextFieldEvent
import com.mercadopago.sdk.android.coremethods.ui.components.textfield.expirationdate.ExpirationDateTextField
import com.mercadopago.sdk.android.coremethods.ui.components.textfield.pcitextfield.rememberPCIFieldState
import com.mercadopago.sdk.android.coremethods.ui.components.textfield.securitycode.SecurityCodeTextField
import com.mercadopago.sdk.android.domain.model.CountryCode
import com.mercadopago.sdk.android.initializer.MercadoPagoSDK
import kotlinx.coroutines.launch

@Composable
fun CardPaymentForm(
    publicKey: String,
    maxInstallments: Int,
    customerName: String,
    onTokenized: suspend (token: String, paymentMethodId: String, installments: Int, taxId: String) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val number = rememberPCIFieldState()
    val expiration = rememberPCIFieldState()
    val cvv = rememberPCIFieldState()
    var bin by remember { mutableStateOf("") }
    var holder by remember { mutableStateOf(customerName) }
    var taxId by remember { mutableStateOf("") }
    var installments by remember { mutableIntStateOf(1) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(publicKey) {
        if (publicKey.isNotBlank()) {
            MercadoPagoSDK.initialize(context.applicationContext, publicKey, CountryCode.BRA)
        }
    }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Cartão", style = MaterialTheme.typography.titleLarge)
        Text("Os dados sensíveis são tokenizados pelo SDK PCI do Mercado Pago e não são enviados em texto ao EventMenu.", style = MaterialTheme.typography.bodySmall)
        SecureBox("Número do cartão") {
            CardNumberTextField(
                state = number,
                onEvent = { event -> if (event is CardNumberTextFieldEvent.OnBinChanged) bin = event.cardBin.orEmpty() },
                modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            )
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.weight(1f)) { SecureBox("Validade") { ExpirationDateTextField(Modifier.fillMaxWidth(), expiration, onEvent = {}, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)) } }
            Box(Modifier.weight(1f)) { SecureBox("CVV") { SecurityCodeTextField(Modifier.fillMaxWidth(), cvv, onEvent = {}, securityCodeSize = 4, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword)) } }
        }
        OutlinedTextField(holder, { holder = it }, label = { Text("Nome no cartão") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(taxId, { taxId = it.filter(Char::isDigit).take(14) }, label = { Text("CPF/CNPJ") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), singleLine = true, modifier = Modifier.fillMaxWidth())
        if (maxInstallments > 1) {
            Text("Parcelas")
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(1,2,3,6,12).filter { it <= maxInstallments }.forEach { n ->
                    FilterChip(selected = installments == n, onClick = { installments = n }, label = { Text(if(n==1) "1x" else "${n}x") })
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            onClick = {
                if (publicKey.isBlank()) { error = "Cartão não configurado pelo restaurante."; return@Button }
                if (holder.isBlank() || taxId.length !in listOf(11,14) || bin.length < 6) { error = "Preencha cartão, nome e CPF/CNPJ."; return@Button }
                busy = true; error = null
                scope.launch {
                    try {
                        val core = MercadoPagoSDK.getInstance().coreMethods
                        val methods = core.getPaymentMethods(bin)
                        val paymentMethodId = when(methods) {
                            is Result.Success -> methods.data.firstOrNull()?.id.orEmpty()
                            is Result.Error -> ""
                        }
                        if (paymentMethodId.isBlank()) error("Não foi possível identificar a bandeira do cartão.")
                        val tokenResult = core.generateCardToken(number, expiration, cvv, BuyerIdentification(holder, taxId, if(taxId.length==11) "CPF" else "CNPJ"))
                        val token = when(tokenResult) {
                            is Result.Success -> tokenResult.data.token
                            is Result.Error -> error("Não foi possível tokenizar o cartão. Revise os dados.")
                        }
                        onTokenized(token, paymentMethodId, installments, taxId)
                    } catch (t: Throwable) { error = t.message ?: "Não foi possível processar o cartão." }
                    finally { busy = false }
                }
            },
            enabled = !busy,
            modifier = Modifier.fillMaxWidth(),
        ) { Text(if (busy) "Processando…" else "Pagar com cartão") }
    }
}

@Composable
private fun SecureBox(label: String, content: @Composable () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium)
        Box(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(12.dp)).padding(horizontal = 14.dp, vertical = 16.dp)) { content() }
    }
}
