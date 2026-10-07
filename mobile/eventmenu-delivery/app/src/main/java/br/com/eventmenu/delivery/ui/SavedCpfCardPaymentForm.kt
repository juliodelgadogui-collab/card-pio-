package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.data.PaymentUiContext
import br.com.eventmenu.delivery.money
import com.mercadopago.sdk.android.coremethods.domain.interactor.coreMethods
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
import java.math.BigDecimal
import kotlin.math.roundToInt

private data class SavedInstallment(
    val count: Int,
    val installmentAmountCents: Int,
    val totalAmountCents: Int,
    val hasInterest: Boolean,
)

private data class SavedMethod(
    val id: String,
    val paymentTypeId: String,
    val cvvLength: Int,
)

@Composable
fun SavedCpfCardPaymentForm(
    publicKey: String,
    maxInstallments: Int,
    customerName: String,
    payerCpf: String,
    paymentTypes: Set<String> = setOf("credit_card", "debit_card"),
    onTokenized: suspend (token: String, paymentMethodId: String, paymentTypeId: String, installments: Int) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val number = rememberPCIFieldState()
    val expiration = rememberPCIFieldState()
    val cvv = rememberPCIFieldState()
    val amountCents = PaymentUiContext.amountCents

    var bin by remember { mutableStateOf("") }
    var holder by remember { mutableStateOf(customerName) }
    var installments by remember { mutableIntStateOf(0) }
    var installmentOptions by remember { mutableStateOf<List<SavedInstallment>>(emptyList()) }
    var methodChoices by remember { mutableStateOf<List<SavedMethod>>(emptyList()) }
    var methodsLoading by remember { mutableStateOf(false) }
    var installmentsLoading by remember { mutableStateOf(false) }
    var paymentMethodId by remember { mutableStateOf("") }
    var paymentTypeId by remember { mutableStateOf("") }
    var cvvLength by remember { mutableIntStateOf(3) }
    var sdkReady by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(publicKey) {
        sdkReady = false
        error = null
        methodChoices = emptyList()
        installmentOptions = emptyList()
        installments = 0
        paymentMethodId = ""
        paymentTypeId = ""
        if (publicKey.isBlank()) {
            error = "Cartão não configurado pelo restaurante."
            return@LaunchedEffect
        }
        runCatching {
            if (MercadoPagoSDK.isInitialized) {
                MercadoPagoSDK.setNewConfiguration(publicKey, CountryCode.BRA)
            } else {
                MercadoPagoSDK.initialize(context.applicationContext, publicKey, CountryCode.BRA)
            }
        }.onSuccess {
            sdkReady = true
        }.onFailure {
            error = "Não foi possível iniciar o pagamento por cartão."
        }
    }

    LaunchedEffect(bin, sdkReady, paymentTypes) {
        methodChoices = emptyList()
        installmentOptions = emptyList()
        installments = 0
        paymentMethodId = ""
        paymentTypeId = ""
        cvvLength = 3
        if (!sdkReady || bin.length < 8) return@LaunchedEffect

        methodsLoading = true
        error = null
        try {
            val result = MercadoPagoSDK.getInstance().coreMethods.getPaymentMethods(bin)
            if (result !is Result.Success) {
                error = "Não foi possível identificar o cartão. Revise o número."
                return@LaunchedEffect
            }
            val choices = result.data
                .mapNotNull { method ->
                    val id = method.id.orEmpty()
                    val type = method.paymentTypeId.orEmpty()
                    if (id.isBlank() || type !in paymentTypes || type !in setOf("credit_card", "debit_card")) {
                        null
                    } else {
                        SavedMethod(
                            id = id,
                            paymentTypeId = type,
                            cvvLength = method.card?.securityCode?.length?.coerceIn(3, 4) ?: 3,
                        )
                    }
                }
                .distinctBy { it.paymentTypeId }
                .sortedBy { if (it.paymentTypeId == "credit_card") 0 else 1 }

            if (choices.isEmpty()) {
                error = "Este cartão não está disponível para crédito ou débito nesta conta."
                return@LaunchedEffect
            }
            methodChoices = choices
            paymentMethodId = choices.first().id
            paymentTypeId = choices.first().paymentTypeId
            cvvLength = choices.first().cvvLength
        } catch (_: Throwable) {
            error = "Não foi possível identificar as funções disponíveis deste cartão."
        } finally {
            methodsLoading = false
        }
    }

    LaunchedEffect(paymentTypeId, paymentMethodId, bin, amountCents, maxInstallments, sdkReady) {
        installmentOptions = emptyList()
        installments = 0
        if (!sdkReady || bin.length < 8 || amountCents <= 0 || paymentMethodId.isBlank()) return@LaunchedEffect

        if (paymentTypeId == "debit_card") {
            installmentOptions = listOf(SavedInstallment(1, amountCents, amountCents, false))
            installments = 1
            return@LaunchedEffect
        }
        if (paymentTypeId != "credit_card") return@LaunchedEffect

        installmentsLoading = true
        error = null
        try {
            val amount = BigDecimal.valueOf(amountCents.toLong(), 2)
            val result = MercadoPagoSDK.getInstance().coreMethods.getInstallments(bin = bin, amount = amount)
            if (result !is Result.Success) {
                error = "Não foi possível consultar as parcelas disponíveis."
                return@LaunchedEffect
            }
            val allowed = result.data
                .filter { it.paymentMethodId.isNullOrBlank() || it.paymentMethodId == paymentMethodId }
                .flatMap { it.payerCost.orEmpty() }
                .mapNotNull { cost ->
                    val count = cost.instalments ?: return@mapNotNull null
                    if (count !in 1..maxInstallments.coerceIn(1, 12)) return@mapNotNull null
                    val each = ((cost.installmentAmount ?: 0f) * 100f).roundToInt().coerceAtLeast(0)
                    val total = ((cost.totalAmount ?: (amountCents / 100f)) * 100f).roundToInt().coerceAtLeast(amountCents)
                    SavedInstallment(
                        count = count,
                        installmentAmountCents = if (each > 0) each else (total / count).coerceAtLeast(1),
                        totalAmountCents = total,
                        hasInterest = (cost.instalmentsRate ?: 0f) > 0.0001f || total > amountCents + 1,
                    )
                }
                .distinctBy { it.count }
                .sortedBy { it.count }

            if (allowed.isEmpty()) {
                error = "Nenhuma condição de parcelamento está disponível para este cartão de crédito."
                return@LaunchedEffect
            }
            installmentOptions = allowed
            installments = allowed.firstOrNull { it.count == 1 }?.count ?: allowed.first().count
        } catch (_: Throwable) {
            error = "Não foi possível consultar as condições do cartão agora."
        } finally {
            installmentsLoading = false
        }
    }

    fun selectMethod(choice: SavedMethod) {
        paymentMethodId = choice.id
        paymentTypeId = choice.paymentTypeId
        cvvLength = choice.cvvLength
        error = null
    }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            when (paymentTypeId) {
                "debit_card" -> "Cartão de débito"
                "credit_card" -> "Cartão de crédito"
                else -> "Cartão de crédito ou débito"
            },
            style = MaterialTheme.typography.titleLarge,
        )
        Text(
            "O CPF cadastrado é usado somente na tokenização e no processamento do pagamento. Número do cartão e CVV permanecem nos campos PCI do Mercado Pago.",
            style = MaterialTheme.typography.bodySmall,
        )
        if (payerCpf.length != 11) {
            Text("Não foi possível carregar a identificação segura do titular. Atualize seu perfil.", color = MaterialTheme.colorScheme.error)
            return@Column
        }
        if (amountCents <= 0) {
            Text("Não foi possível carregar o valor deste pedido.", color = MaterialTheme.colorScheme.error)
            return@Column
        }
        if (!sdkReady) {
            if (error == null) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            return@Column
        }

        SecureSavedBox("Número do cartão") {
            CardNumberTextField(
                state = number,
                onEvent = { event ->
                    if (event is CardNumberTextFieldEvent.OnBinChanged) {
                        bin = event.cardBin.orEmpty()
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            )
        }

        if (methodsLoading) {
            LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            Text("Identificando crédito/débito…", style = MaterialTheme.typography.bodySmall)
        } else if (methodChoices.size > 1) {
            Text("Como deseja pagar?", style = MaterialTheme.typography.labelLarge)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                methodChoices.forEach { choice ->
                    FilterChip(
                        selected = paymentTypeId == choice.paymentTypeId,
                        onClick = { selectMethod(choice) },
                        label = { Text(if (choice.paymentTypeId == "debit_card") "Débito" else "Crédito") },
                    )
                }
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(modifier = Modifier.weight(1f)) {
                SecureSavedBox("Validade") {
                    ExpirationDateTextField(
                        modifier = Modifier.fillMaxWidth(),
                        state = expiration,
                        onEvent = {},
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    )
                }
            }
            Box(modifier = Modifier.weight(1f)) {
                SecureSavedBox("CVV") {
                    SecurityCodeTextField(
                        modifier = Modifier.fillMaxWidth(),
                        state = cvv,
                        onEvent = {},
                        securityCodeSize = cvvLength,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                        visualTransformation = PasswordVisualTransformation(),
                    )
                }
            }
        }

        OutlinedTextField(
            value = holder,
            onValueChange = { holder = it },
            label = { Text("Nome no cartão") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        if (bin.length >= 8 && paymentTypeId == "credit_card") {
            Text("Parcelas", style = MaterialTheme.typography.labelLarge)
            if (installmentsLoading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            } else if (installmentOptions.isNotEmpty()) {
                Row(
                    modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    installmentOptions.forEach { option ->
                        val label = buildString {
                            append(option.count).append("x de ").append(money(option.installmentAmountCents))
                            if (!option.hasInterest) append(" sem juros")
                        }
                        FilterChip(
                            selected = installments == option.count,
                            onClick = { installments = option.count },
                            label = { Text(label) },
                        )
                    }
                }
                installmentOptions.firstOrNull { it.count == installments }?.let { selected ->
                    if (selected.hasInterest) {
                        Text("Total no cartão: ${money(selected.totalAmountCents)}", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        } else if (paymentTypeId == "debit_card") {
            Text("Débito à vista · ${money(amountCents)}")
        }

        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        Button(
            onClick = {
                if (holder.isBlank() || bin.length < 8) {
                    error = "Preencha cartão e nome do titular."
                    return@Button
                }
                if (
                    paymentMethodId.isBlank() ||
                    paymentTypeId !in setOf("credit_card", "debit_card") ||
                    installments < 1 ||
                    installmentOptions.none { it.count == installments }
                ) {
                    error = "Aguarde a validação do cartão e escolha uma condição disponível."
                    return@Button
                }
                busy = true
                error = null
                scope.launch {
                    try {
                        val tokenResult = MercadoPagoSDK.getInstance().coreMethods.generateCardToken(
                            cardNumberState = number,
                            expirationDateState = expiration,
                            securityCodeState = cvv,
                            buyerIdentification = BuyerIdentification(holder, payerCpf, "CPF"),
                        )
                        val token = when (tokenResult) {
                            is Result.Success -> tokenResult.data.token
                            is Result.Error -> error("Não foi possível tokenizar o cartão. Revise os dados.")
                        }
                        onTokenized(token, paymentMethodId, paymentTypeId, installments)
                    } catch (t: Throwable) {
                        error = t.message ?: "Não foi possível processar o cartão."
                    } finally {
                        busy = false
                    }
                }
            },
            enabled = !busy && !methodsLoading && !installmentsLoading && installmentOptions.isNotEmpty(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(
                if (busy) "Processando…"
                else if (paymentTypeId == "debit_card") "Pagar no débito"
                else "Pagar no crédito",
            )
        }
    }
}

@Composable
private fun SecureSavedBox(
    label: String,
    content: @Composable () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium)
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(12.dp))
                .padding(horizontal = 14.dp, vertical = 16.dp),
        ) {
            content()
        }
    }
}
