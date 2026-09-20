package br.com.eventmenu.delivery.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.LocationManager
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import br.com.eventmenu.delivery.BuildConfig
import br.com.eventmenu.delivery.DeliveryViewModel
import br.com.eventmenu.delivery.Screen
import br.com.eventmenu.delivery.data.Address

@Composable
fun DelyvreAccountExperienceRoot(vm: DeliveryViewModel) {
    val isAccountScreen = vm.screen in setOf(Screen.Register, Screen.VerifyEmail, Screen.Profile, Screen.AddressEditor)
    if (!isAccountScreen) {
        DelyvreExperienceRoot(vm)
        return
    }
    DelyvreTheme {
        Box(Modifier.fillMaxSize()) {
            when (vm.screen) {
                Screen.Register -> DelyvreRegisterScreen(vm)
                Screen.VerifyEmail -> DelyvreVerifyEmailScreen(vm)
                Screen.Profile -> DelyvreProfileScreen(vm)
                Screen.AddressEditor -> DelyvreAddressEditorScreen(vm)
                else -> Unit
            }
            if (vm.busy) {
                Surface(Modifier.fillMaxSize(), color = DelyvreInk.copy(alpha = .16f)) {
                    Box(contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreRegisterScreen(vm: DeliveryViewModel) {
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var cpf by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var passwordVisible by remember { mutableStateOf(false) }
    var accepted by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }
    DelyvreAccountMessages(vm, snackbar)

    fun openLegal(path: String) {
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.API_BASE_URL + path))) }
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Criar conta", fontWeight = FontWeight.Bold) }, navigationIcon = { IconButton({ vm.navigate(Screen.Login) }) { Icon(Icons.Default.ArrowBack, "Voltar") } }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { pad ->
        Column(Modifier.fillMaxSize().padding(pad).verticalScroll(rememberScrollState()).padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("DELYVRE", color = DelyvreBrandStrong, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Black)
            Text("Seus dados para pedir com mais rapidez", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
            Text("Depois do cadastro, confirme seu e-mail para entrar e finalizar pedidos.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            OutlinedTextField(name, { name = it.take(160) }, label = { Text("Nome") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(cpf, { cpf = it.filter(Char::isDigit).take(11) }, label = { Text("CPF") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(email, { email = it.trimStart() }, label = { Text("E-mail") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(phone, { phone = it.filter { c -> c.isDigit() || c in "+()- " }.take(24) }, label = { Text("Celular") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(
                password, { password = it }, label = { Text("Senha · mínimo 8 caracteres") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                visualTransformation = if (passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
                trailingIcon = { IconButton({ passwordVisible = !passwordVisible }) { Icon(if (passwordVisible) Icons.Default.VisibilityOff else Icons.Default.Visibility, null) } },
            )
            Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(18.dp)) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(Modifier.fillMaxWidth().clickable { accepted = !accepted }, verticalAlignment = Alignment.CenterVertically) { Checkbox(accepted, { accepted = it }); Text("Li e aceito os Termos de Uso e a Política de Privacidade.", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall) }
                    Row { TextButton({ openLegal("delivery-terms.php") }) { Text("Termos") }; TextButton({ openLegal("delivery-privacy.php") }) { Text("Privacidade") } }
                }
            }
            Button({ vm.registerWithCpf(name, cpf, email, phone, password, accepted) }, Modifier.fillMaxWidth().heightIn(min = 54.dp), enabled = name.isNotBlank() && cpf.length == 11 && email.isNotBlank() && password.length >= 8 && accepted) { Text("Criar conta") }
            TextButton({ vm.navigate(Screen.Login) }, Modifier.fillMaxWidth()) { Text("Já tenho uma conta") }
        }
    }
}

@Composable
private fun DelyvreVerifyEmailScreen(vm: DeliveryViewModel) {
    val snackbar = remember { SnackbarHostState() }
    DelyvreAccountMessages(vm, snackbar)
    Scaffold(snackbarHost = { SnackbarHost(snackbar) }) { pad ->
        Column(Modifier.fillMaxSize().padding(pad).padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) { Icon(Icons.Default.MarkEmailRead, null, Modifier.padding(22.dp).size(58.dp), tint = DelyvreBrand) }
            Spacer(Modifier.height(24.dp)); Text("Confirme seu e-mail", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
            Spacer(Modifier.height(8.dp)); Text("Enviamos um link para ${vm.pendingEmail}.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(6.dp)); Text("Abra a mensagem e toque em “Confirmar meu e-mail”. Depois, volte ao DELYVRE para entrar.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(24.dp)); Button(vm::resendVerification, Modifier.fillMaxWidth()) { Text("Reenviar confirmação") }
            OutlinedButton({ vm.navigate(Screen.Login) }, Modifier.fillMaxWidth()) { Text("Já confirmei · entrar") }
            TextButton({ vm.navigate(Screen.Home) }, Modifier.fillMaxWidth()) { Text("Continuar navegando") }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreProfileScreen(vm: DeliveryViewModel) {
    val customer = vm.customer
    var name by remember(customer) { mutableStateOf(customer?.name.orEmpty()) }
    var phone by remember(customer) { mutableStateOf(customer?.phone.orEmpty()) }
    var cpf by remember(customer?.cpfConfigured) { mutableStateOf("") }
    val snackbar = remember { SnackbarHostState() }
    DelyvreAccountMessages(vm, snackbar)
    Scaffold(
        topBar = { TopAppBar(title = { Text("Perfil", fontWeight = FontWeight.Bold) }, navigationIcon = { IconButton({ if (vm.selectedOrder != null) vm.navigate(Screen.Checkout) else vm.navigate(Screen.Home) }) { Icon(Icons.Default.ArrowBack, "Voltar") } }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) { Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(999.dp)) { Icon(Icons.Default.Person, null, Modifier.padding(12.dp), tint = DelyvreBrand) }; Column { Text(customer?.name.orEmpty().ifBlank { "Minha conta" }, fontWeight = FontWeight.Bold); Text(customer?.email.orEmpty(), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) } }
                        OutlinedTextField(name, { name = it.take(160) }, label = { Text("Nome") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(phone, { phone = it.take(24) }, label = { Text("Celular") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth())
                        if (customer?.cpfConfigured == true) {
                            OutlinedTextField(customer.cpfMasked, {}, readOnly = true, label = { Text("CPF") }, modifier = Modifier.fillMaxWidth())
                            Text("CPF protegido e já configurado para pagamentos.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else {
                            OutlinedTextField(cpf, { cpf = it.filter(Char::isDigit).take(11) }, label = { Text("CPF") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                            Text("Informe uma única vez para liberar Pix e cartão.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Button({ vm.saveProfile(name, phone, if (customer?.cpfConfigured == true) "" else cpf) }, Modifier.fillMaxWidth(), enabled = name.isNotBlank() && (customer?.cpfConfigured == true || cpf.length == 11)) { Text("Salvar dados") }
                        if (vm.selectedOrder != null) OutlinedButton({ vm.navigate(Screen.Checkout) }, Modifier.fillMaxWidth()) { Text("Voltar ao pagamento") }
                    }
                }
            }
            item { Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { Column(Modifier.weight(1f)) { Text("Endereços", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold); Text("Escolha onde quer receber seus pedidos.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }; FilledTonalIconButton({ vm.editAddress() }) { Icon(Icons.Default.Add, "Adicionar endereço") } } }
            if (customer?.addresses.isNullOrEmpty()) item { DelyvreAccountNotice("Nenhum endereço salvo", "Cadastre seu primeiro endereço de entrega.") }
            else items(customer!!.addresses, key = { it.id }) { address ->
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.LocationOn, null, tint = DelyvreBrand); Spacer(Modifier.width(10.dp)); Column(Modifier.weight(1f)) { Row(verticalAlignment = Alignment.CenterVertically) { Text(address.label, fontWeight = FontWeight.Bold); if (address.isDefault) { Spacer(Modifier.width(6.dp)); Badge { Text("Principal") } } }; Text("${address.street}, ${address.number}", style = MaterialTheme.typography.bodySmall); Text("${address.city}/${address.state}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }; IconButton({ vm.editAddress(address) }) { Icon(Icons.Default.Edit, "Editar") }; IconButton({ vm.deleteAddress(address.id) }) { Icon(Icons.Default.Delete, "Excluir") } }
                }
            }
            item { OutlinedButton(vm::logout, Modifier.fillMaxWidth()) { Icon(Icons.Default.Logout, null); Spacer(Modifier.width(8.dp)); Text("Sair da conta") } }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DelyvreAddressEditorScreen(vm: DeliveryViewModel) {
    val original = vm.editingAddress
    var label by remember(original) { mutableStateOf(original?.label ?: "Casa") }
    var street by remember(original) { mutableStateOf(original?.street.orEmpty()) }
    var number by remember(original) { mutableStateOf(original?.number.orEmpty()) }
    var complement by remember(original) { mutableStateOf(original?.complement.orEmpty()) }
    var neighborhood by remember(original) { mutableStateOf(original?.neighborhood.orEmpty()) }
    var city by remember(original) { mutableStateOf(original?.city.orEmpty()) }
    var state by remember(original) { mutableStateOf(original?.state.orEmpty()) }
    var cep by remember(original) { mutableStateOf(original?.postalCode.orEmpty()) }
    var reference by remember(original) { mutableStateOf(original?.reference.orEmpty()) }
    var lat by remember(original) { mutableStateOf(original?.latitude) }
    var lng by remember(original) { mutableStateOf(original?.longitude) }
    var isDefault by remember(original, vm.customer) { mutableStateOf(original?.isDefault ?: vm.customer?.addresses.isNullOrEmpty()) }
    val snackbar = remember { SnackbarHostState() }
    DelyvreAccountMessages(vm, snackbar)

    Scaffold(topBar = { TopAppBar(title = { Text(if (original == null) "Novo endereço" else "Editar endereço", fontWeight = FontWeight.Bold) }, navigationIcon = { IconButton({ vm.navigate(Screen.Profile) }) { Icon(Icons.Default.ArrowBack, "Voltar") } }, colors = TopAppBarDefaults.topAppBarColors(containerColor = DelyvreCanvas)) }, snackbarHost = { SnackbarHost(snackbar) }) { pad ->
        LazyColumn(Modifier.fillMaxSize().padding(pad), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { DelyvreLocationButton(lat != null && lng != null) { a, b -> lat = a; lng = b } }
            item {
                Card(colors = CardDefaults.cardColors(containerColor = Color.White), border = CardDefaults.outlinedCardBorder()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        OutlinedTextField(label, { label = it.take(60) }, label = { Text("Nome do endereço") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(cep, { cep = it.filter(Char::isDigit).take(8) }, label = { Text("CEP") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(street, { street = it.take(180) }, label = { Text("Rua") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(number, { number = it.take(30) }, label = { Text("Número") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(complement, { complement = it.take(120) }, label = { Text("Complemento · opcional") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(neighborhood, { neighborhood = it.take(120) }, label = { Text("Bairro") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(city, { city = it.take(120) }, label = { Text("Cidade") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(state, { state = it.filter(Char::isLetter).uppercase().take(2) }, label = { Text("UF") }, modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(reference, { reference = it.take(180) }, label = { Text("Ponto de referência · opcional") }, modifier = Modifier.fillMaxWidth())
                        Row(Modifier.fillMaxWidth().clickable { isDefault = !isDefault }, verticalAlignment = Alignment.CenterVertically) { Checkbox(isDefault, { isDefault = it }); Text("Usar como endereço principal") }
                        Button({ vm.saveAddress(Address(original?.id ?: 0, label.trim(), street.trim(), number.trim(), complement.trim(), neighborhood.trim(), city.trim(), state.trim(), cep.trim(), reference.trim(), vm.customer?.phone.orEmpty(), lat, lng, isDefault)) }, Modifier.fillMaxWidth().heightIn(min = 54.dp), enabled = street.isNotBlank() && number.isNotBlank() && city.isNotBlank() && state.length == 2) { Text("Salvar endereço") }
                    }
                }
            }
        }
    }
}

@Composable
private fun DelyvreLocationButton(hasLocation: Boolean, onLocation: (Double, Double) -> Unit) {
    val context = LocalContext.current
    var feedback by remember(hasLocation) { mutableStateOf(if (hasLocation) "Localização adicionada ✓" else "Usar minha localização") }
    fun capture() {
        val pair = delyvreLastKnownLocation(context)
        if (pair != null) { onLocation(pair.first, pair.second); feedback = "Localização adicionada ✓" } else feedback = "Não encontramos sua posição. Ative o GPS e tente novamente."
    }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants -> if (grants.values.any { it }) capture() else feedback = "Permita o acesso à localização para usar este recurso." }
    OutlinedButton({
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        if (fine || coarse) capture() else launcher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
    }, Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Icon(Icons.Default.MyLocation, null); Spacer(Modifier.width(8.dp)); Text(feedback) }
}

private fun delyvreLastKnownLocation(context: Context): Pair<Double, Double>? {
    val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    if (!fine && !coarse) return null
    val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null
    val location = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).mapNotNull { provider ->
        try { manager.getLastKnownLocation(provider) } catch (_: SecurityException) { null }
    }.maxByOrNull { it.time }
    return location?.let { it.latitude to it.longitude }
}

@Composable private fun DelyvreAccountNotice(title: String, text: String) { Surface(color = DelyvreSurfaceMuted, shape = RoundedCornerShape(18.dp)) { Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) { Icon(Icons.Default.Info, null, tint = DelyvreBrand); Column { Text(title, fontWeight = FontWeight.Bold); Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) } } } }
@Composable private fun DelyvreAccountMessages(vm: DeliveryViewModel, host: SnackbarHostState) { LaunchedEffect(vm.message) { vm.message?.let { host.showSnackbar(it.replace("EventMenu Delivery", "DELYVRE").replace("EventMenu", "DELYVRE").replace("Mercado Pago", "pagamento")); vm.clearMessage() } } }
