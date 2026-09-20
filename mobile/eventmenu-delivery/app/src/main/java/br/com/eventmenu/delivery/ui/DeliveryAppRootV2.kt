package br.com.eventmenu.delivery.ui

import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.CreditCard
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.QrCode2
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import br.com.eventmenu.delivery.BuildConfig
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.money
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter

private val V2Primary = Color(0xFF6D28D9)
private val V2Dark = Color(0xFF4C1D95)

@Composable
fun DeliveryAppRootV2(vm: DeliveryViewModel) {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = V2Primary,
            primaryContainer = Color(0xFFE9DDFF),
            background = Color(0xFFFAF8FF),
            surface = Color.White,
        ),
    ) {
        when (vm.screen) {
            Screen.Register -> CpfRegisterScreen(vm)
            Screen.Checkout -> NativeCheckoutScreen(vm)
            Screen.Profile -> CpfProfileScreen(vm)
            else -> DeliveryAppRoot(vm)
        }
    }
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun CpfRegisterScreen(vm: DeliveryViewModel) {
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var cpf by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var accepted by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it)
            vm.clearMessage()
        }
    }

    fun openLegal(path: String) {
        runCatching {
            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.API_BASE_URL + path)))
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Criar conta", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = { vm.navigate(Screen.Login) }) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Voltar")
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                "Seus dados ficam salvos para agilizar pedidos e pagamentos.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            OutlinedTextField(
                value = name,
                onValueChange = { name = it.take(160) },
                label = { Text("Nome") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = cpf,
                onValueChange = { cpf = it.filter(Char::isDigit).take(11) },
                label = { Text("CPF") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = phone,
                onValueChange = { phone = it.filter { c -> c.isDigit() || c in "+()- " } },
                label = { Text("Celular") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("E-mail") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                label = { Text("Senha · mínimo 8 caracteres") },
                visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                trailingIcon = {
                    IconButton(onClick = { visible = !visible }) {
                        Icon(
                            if (visible) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                            contentDescription = null,
                        )
                    }
                },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Card {
                Column(modifier = Modifier.padding(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(checked = accepted, onCheckedChange = { accepted = it })
                        Text(
                            "Li e aceito os Termos de Uso e a Política de Privacidade.",
                            modifier = Modifier.fillMaxWidth(),
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    Row {
                        TextButton(onClick = { openLegal("delivery-terms.php") }) { Text("Termos") }
                        TextButton(onClick = { openLegal("delivery-privacy.php") }) { Text("Privacidade") }
                    }
                }
            }
            Button(
                onClick = { vm.registerWithCpf(name, cpf, email, phone, password, accepted) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
                enabled = name.isNotBlank() && cpf.length == 11 && email.isNotBlank() && password.length >= 8 && accepted,
            ) {
                Text("Cadastrar e confirmar e-mail", fontWeight = FontWeight.Bold)
            }
        }
    }
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun NativeCheckoutScreen(vm: DeliveryViewModel) {
    val order = vm.selectedOrder ?: return
    val methods = vm.paymentMethods
    val customer = vm.customer
    val clipboard = LocalClipboardManager.current
    val snackbar = remember { SnackbarHostState() }
    var showCard by remember { mutableStateOf(false) }
    var showCash by remember { mutableStateOf(false) }
    var changeText by remember { mutableStateOf("") }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it)
            vm.clearMessage()
        }
    }
    LaunchedEffect(showCard, customer?.cpfConfigured) {
        if (showCard && customer?.cpfConfigured == true && vm.payerCpf.isBlank()) {
            vm.prepareCardIdentification()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Pagamento", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = { vm.openOrder(order.orderNumber) }) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Voltar")
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Card {
                    Column(
                        modifier = Modifier.padding(18.dp),
                        verticalArrangement = Arrangement.spacedBy(5.dp),
                    ) {
                        Text(order.storeName, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text("Pedido #${order.orderNumber}")
                        Text(money(order.totalCents), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, color = V2Dark)
                    }
                }
            }

            if (customer?.cpfConfigured != true) {
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer)) {
                        Column(
                            modifier = Modifier.padding(16.dp),
                            verticalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            Text("Complete seu CPF para liberar PIX e cartão.", fontWeight = FontWeight.Bold)
                            Text("Você informa uma única vez e depois o EventMenu reutiliza o cadastro com segurança.")
                            Button(onClick = { vm.openProfile() }) { Text("Completar cadastro") }
                        }
                    }
                }
            }

            if (methods == null) {
                item {
                    Card {
                        Column(modifier = Modifier.padding(16.dp)) {
                            Text("Carregando formas de pagamento…")
                            LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                            TextButton(onClick = { vm.reloadPaymentMethods() }) { Text("Atualizar") }
                        }
                    }
                }
            } else {
                methods.pixProviders.firstOrNull()?.let { provider ->
                    item {
                        Card {
                            Column(
                                modifier = Modifier.padding(16.dp),
                                verticalArrangement = Arrangement.spacedBy(10.dp),
                            ) {
                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                                ) {
                                    Icon(Icons.Default.QrCode2, contentDescription = null, tint = V2Primary)
                                    Text("PIX", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                                }
                                Text(
                                    "O QR Code e o Copia e Cola ficam dentro do EventMenu. O provedor é escolhido automaticamente conforme a configuração do restaurante.",
                                    style = MaterialTheme.typography.bodySmall,
                                )
                                Button(
                                    onClick = { vm.payPix(provider, "") },
                                    modifier = Modifier.fillMaxWidth(),
                                    enabled = customer?.cpfConfigured == true && !vm.paymentWaiting,
                                ) {
                                    Text("Gerar PIX")
                                }
                            }
                        }
                    }
                }

                vm.pixPayment?.let { pix ->
                    item {
                        Card {
                            Column(
                                modifier = Modifier.padding(16.dp),
                                horizontalAlignment = Alignment.CenterHorizontally,
                                verticalArrangement = Arrangement.spacedBy(10.dp),
                            ) {
                                Text("PIX gerado", fontWeight = FontWeight.Bold)
                                rememberQr(pix.copyPaste)?.let { image ->
                                    Image(
                                        bitmap = image,
                                        contentDescription = "QR Code PIX",
                                        modifier = Modifier.size(230.dp),
                                    )
                                }
                                OutlinedTextField(
                                    value = pix.copyPaste,
                                    onValueChange = {},
                                    readOnly = true,
                                    label = { Text("PIX Copia e Cola") },
                                    modifier = Modifier.fillMaxWidth(),
                                    minLines = 3,
                                )
                                Button(
                                    onClick = { clipboard.setText(AnnotatedString(pix.copyPaste)) },
                                    modifier = Modifier.fillMaxWidth(),
                                ) {
                                    Icon(Icons.Default.ContentCopy, contentDescription = null)
                                    Spacer(modifier = Modifier.width(8.dp))
                                    Text("Copiar código")
                                }
                                if (vm.paymentWaiting) {
                                    LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                                    Text("Aguardando confirmação automática…")
                                }
                            }
                        }
                    }
                }

                methods.cards.firstOrNull()?.let { card ->
                    item {
                        Card {
                            Column(
                                modifier = Modifier.padding(16.dp),
                                verticalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                Row(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clickable(enabled = customer?.cpfConfigured == true) { showCard = !showCard },
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Icon(Icons.Default.CreditCard, contentDescription = null, tint = V2Primary)
                                    Spacer(modifier = Modifier.width(10.dp))
                                    Text("Cartão de crédito ou débito", modifier = Modifier.weight(1f), fontWeight = FontWeight.Bold)
                                    Icon(if (showCard) Icons.Default.ExpandLess else Icons.Default.ExpandMore, contentDescription = null)
                                }
                                if (showCard) {
                                    if (vm.payerCpf.length == 11) {
                                        SavedCpfCardPaymentForm(
                                            publicKey = card.publicKey,
                                            maxInstallments = card.maxInstallments,
                                            customerName = customer?.name.orEmpty(),
                                            payerCpf = vm.payerCpf,
                                            paymentTypes = card.paymentTypes,
                                        ) { token, methodId, type, installments ->
                                            vm.payCardToken(token, methodId, type, installments, "")
                                        }
                                    } else {
                                        LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                                        Text("Preparando identificação segura…", style = MaterialTheme.typography.bodySmall)
                                    }
                                }
                            }
                        }
                    }
                }

                if (methods.cash) {
                    item {
                        Card {
                            Column(
                                modifier = Modifier.padding(16.dp),
                                verticalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                Row(
                                    modifier = Modifier.fillMaxWidth().clickable { showCash = !showCash },
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Icon(Icons.Default.Payments, contentDescription = null, tint = V2Primary)
                                    Spacer(modifier = Modifier.width(10.dp))
                                    Text("Dinheiro na entrega", modifier = Modifier.weight(1f), fontWeight = FontWeight.Bold)
                                    Icon(if (showCash) Icons.Default.ExpandLess else Icons.Default.ExpandMore, contentDescription = null)
                                }
                                if (showCash) {
                                    OutlinedTextField(
                                        value = changeText,
                                        onValueChange = { changeText = it.filter { c -> c.isDigit() || c == ',' || c == '.' } },
                                        label = { Text("Troco para (opcional)") },
                                        modifier = Modifier.fillMaxWidth(),
                                    )
                                    Button(
                                        onClick = {
                                            val cents = changeText.replace(',', '.').toDoubleOrNull()?.let { (it * 100).toInt() }
                                            vm.payCash(cents)
                                        },
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        Text("Pagar em dinheiro")
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun CpfProfileScreen(vm: DeliveryViewModel) {
    val customer = vm.customer
    var name by remember(customer) { mutableStateOf(customer?.name.orEmpty()) }
    var phone by remember(customer) { mutableStateOf(customer?.phone.orEmpty()) }
    var cpf by remember { mutableStateOf("") }
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(vm.message) {
        vm.message?.let {
            snackbar.showSnackbar(it)
            vm.clearMessage()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Minha conta", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(
                        onClick = {
                            if (vm.selectedOrder != null) vm.navigate(Screen.Checkout)
                            else vm.navigate(Screen.Home)
                        },
                    ) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Voltar")
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Card {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        OutlinedTextField(
                            value = name,
                            onValueChange = { name = it },
                            label = { Text("Nome") },
                            modifier = Modifier.fillMaxWidth(),
                        )
                        OutlinedTextField(
                            value = phone,
                            onValueChange = { phone = it },
                            label = { Text("Celular") },
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                            modifier = Modifier.fillMaxWidth(),
                        )
                        Text(customer?.email.orEmpty(), style = MaterialTheme.typography.bodySmall)
                        if (customer?.cpfConfigured == true) {
                            OutlinedTextField(
                                value = customer.cpfMasked,
                                onValueChange = {},
                                readOnly = true,
                                label = { Text("CPF") },
                                modifier = Modifier.fillMaxWidth(),
                            )
                            Text(
                                "CPF protegido e já configurado.",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        } else {
                            OutlinedTextField(
                                value = cpf,
                                onValueChange = { cpf = it.filter(Char::isDigit).take(11) },
                                label = { Text("CPF") },
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                                modifier = Modifier.fillMaxWidth(),
                            )
                            Text("Informe uma única vez para completar seu cadastro.", style = MaterialTheme.typography.bodySmall)
                        }
                        Button(
                            onClick = { vm.saveProfile(name, phone, if (customer?.cpfConfigured == true) "" else cpf) },
                            modifier = Modifier.fillMaxWidth(),
                            enabled = name.isNotBlank() && (customer?.cpfConfigured == true || cpf.length == 11),
                        ) {
                            Text("Salvar perfil")
                        }
                        if (vm.selectedOrder != null) {
                            OutlinedButton(
                                onClick = { vm.navigate(Screen.Checkout) },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Text("Voltar ao pagamento")
                            }
                        }
                    }
                }
            }

            item {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("Meus endereços", modifier = Modifier.weight(1f), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    FilledTonalIconButton(onClick = { vm.editAddress() }) {
                        Icon(Icons.Default.Add, contentDescription = "Adicionar endereço")
                    }
                }
            }

            if (customer?.addresses.isNullOrEmpty()) {
                item {
                    Card {
                        Text("Cadastre seu endereço de entrega.", modifier = Modifier.padding(16.dp))
                    }
                }
            } else {
                items(customer!!.addresses, key = { it.id }) { address ->
                    Card {
                        Row(modifier = Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text(address.label, fontWeight = FontWeight.Bold)
                                Text("${address.street}, ${address.number} · ${address.city}/${address.state}")
                                if (address.isDefault) {
                                    Text("Endereço principal", style = MaterialTheme.typography.bodySmall, color = V2Primary)
                                }
                            }
                            IconButton(onClick = { vm.editAddress(address) }) {
                                Icon(Icons.Default.Edit, contentDescription = "Editar endereço")
                            }
                            IconButton(onClick = { vm.deleteAddress(address.id) }) {
                                Icon(Icons.Default.Delete, contentDescription = "Excluir endereço")
                            }
                        }
                    }
                }
            }

            item {
                OutlinedButton(onClick = { vm.logout() }, modifier = Modifier.fillMaxWidth()) {
                    Text("Sair da conta")
                }
            }
        }
    }
}

@Composable
private fun rememberQr(text: String): ImageBitmap? = remember(text) {
    runCatching {
        val matrix = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, 600, 600)
        val bitmap = Bitmap.createBitmap(600, 600, Bitmap.Config.ARGB_8888)
        for (y in 0 until 600) {
            for (x in 0 until 600) {
                bitmap.setPixel(
                    x,
                    y,
                    if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE,
                )
            }
        }
        bitmap.asImageBitmap()
    }.getOrNull()
}
