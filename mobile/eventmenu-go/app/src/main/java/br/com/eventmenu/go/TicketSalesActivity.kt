package br.com.eventmenu.go

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import br.com.eventmenu.go.data.*
import br.com.eventmenu.go.security.DeviceIdentity
import br.com.eventmenu.go.security.SecureSessionStore
import br.com.eventmenu.go.ui.theme.EventMenuTheme
import kotlinx.coroutines.launch

class TicketSalesActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as EventMenuGoApplication
        val repo = TicketSalesRepository(
            BuildConfig.API_BASE_URL,
            DeviceIdentity.id(this),
            SecureSessionStore(this)
        )
        setContent {
            var brand by remember { mutableStateOf(app.brandRepository.cached()) }
            LaunchedEffect(Unit) {
                brand = runCatching { app.brandRepository.load() }.getOrNull() ?: brand
            }
            EventMenuTheme(brand) {
                TicketSalesPage(
                    repo = repo,
                    onBack = { finish() },
                    onOpen = { url -> startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) },
                    onShare = { text ->
                        startActivity(
                            Intent.createChooser(
                                Intent(Intent.ACTION_SEND).apply {
                                    type = "text/plain"
                                    putExtra(Intent.EXTRA_TEXT, text)
                                },
                                "Compartilhar ingresso"
                            )
                        )
                    }
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TicketSalesPage(
    repo: TicketSalesRepository,
    onBack: () -> Unit,
    onOpen: (String) -> Unit,
    onShare: (String) -> Unit
) {
    val scope = rememberCoroutineScope()
    var events by remember { mutableStateOf<List<TicketSaleEvent>>(emptyList()) }
    var catalog by remember { mutableStateOf<TicketSaleCatalog?>(null) }
    var eventId by remember { mutableStateOf<Int?>(null) }
    var batchId by remember { mutableStateOf<Int?>(null) }
    var qty by remember { mutableIntStateOf(1) }
    var method by remember { mutableStateOf("cash") }
    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var result by remember { mutableStateOf<TicketSaleResult?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun loadCatalog(id: Int) {
        scope.launch {
            loading = true
            error = null
            runCatching { repo.catalog(id) }
                .onSuccess {
                    catalog = it
                    batchId = it.batches.firstOrNull { batch -> batch.available > 0 }?.id
                }
                .onFailure { error = it.message }
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        loading = true
        runCatching { repo.events() }
            .onSuccess {
                events = it
                it.firstOrNull()?.let { event ->
                    eventId = event.id
                    loadCatalog(event.id)
                }
            }
            .onFailure { error = it.message }
        loading = false
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Vender ingresso") },
                navigationIcon = { TextButton(onClick = onBack) { Text("Voltar") } }
            )
        }
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            item {
                Text("Bilheteria presencial", style = MaterialTheme.typography.headlineSmall)
                Text(
                    "Venda avulsa sem dados obrigatórios. O servidor controla capacidade e gera um QR único para cada ingresso.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            error?.let { message -> item { Text(message, color = MaterialTheme.colorScheme.error) } }

            item {
                var open by remember { mutableStateOf(false) }
                ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
                    OutlinedTextField(
                        value = events.firstOrNull { it.id == eventId }?.name.orEmpty(),
                        onValueChange = {},
                        readOnly = true,
                        label = { Text("Evento") },
                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) },
                        modifier = Modifier.menuAnchor().fillMaxWidth()
                    )
                    ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                        events.forEach { event ->
                            DropdownMenuItem(
                                text = { Text(event.name) },
                                onClick = {
                                    eventId = event.id
                                    open = false
                                    result = null
                                    loadCatalog(event.id)
                                }
                            )
                        }
                    }
                }
            }

            catalog?.let { currentCatalog ->
                item {
                    var open by remember { mutableStateOf(false) }
                    val selected = currentCatalog.batches.firstOrNull { it.id == batchId }
                    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
                        OutlinedTextField(
                            value = selected?.let { batchLabel(it) }.orEmpty(),
                            onValueChange = {},
                            readOnly = true,
                            label = { Text("Lote / tipo") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) },
                            modifier = Modifier.menuAnchor().fillMaxWidth()
                        )
                        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                            currentCatalog.batches.forEach { batch ->
                                DropdownMenuItem(
                                    text = { Text(batchLabel(batch)) },
                                    enabled = batch.available > 0,
                                    onClick = {
                                        batchId = batch.id
                                        open = false
                                    }
                                )
                            }
                        }
                    }
                }
            }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { if (qty > 1) qty-- }) { Text("−") }
                    Text("$qty ingresso(s)", modifier = Modifier.padding(top = 12.dp))
                    OutlinedButton(onClick = { if (qty < 20) qty++ }) { Text("+") }
                }
            }

            item {
                Text("Pagamento", style = MaterialTheme.typography.titleMedium)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("cash" to "Dinheiro", "pix" to "Pix", "card_pos" to "Cartão/POS").forEach { (value, label) ->
                        FilterChip(selected = method == value, onClick = { method = value }, label = { Text(label) })
                    }
                }
                if (catalog?.canCourtesy == true) {
                    FilterChip(selected = method == "courtesy", onClick = { method = "courtesy" }, label = { Text("Cortesia") })
                }
            }

            item {
                Text("Comprador (opcional)", style = MaterialTheme.typography.titleMedium)
                Text("Deixe vazio para venda avulsa. Nenhum cliente fictício será criado.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedTextField(name, { name = it }, label = { Text("Nome") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(phone, { phone = it }, label = { Text("Telefone") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(email, { email = it }, label = { Text("E-mail") }, modifier = Modifier.fillMaxWidth())
            }

            item {
                Button(
                    onClick = {
                        val selectedEvent = eventId
                        val selectedBatch = batchId
                        if (selectedEvent != null && selectedBatch != null) {
                            scope.launch {
                                loading = true
                                error = null
                                runCatching { repo.sell(selectedEvent, selectedBatch, qty, method, name, phone, email) }
                                    .onSuccess {
                                        result = it
                                        loadCatalog(selectedEvent)
                                    }
                                    .onFailure { error = it.message }
                                loading = false
                            }
                        }
                    },
                    enabled = !loading && eventId != null && batchId != null,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(if (loading) "Processando..." else "Concluir venda")
                }
            }

            result?.let { sale ->
                item {
                    Card {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Venda #${sale.orderId}", style = MaterialTheme.typography.titleLarge)
                            Text(if (sale.anonymous) "Comprador não identificado" else "Venda identificada")
                            Text("${sale.tickets.size} ingresso(s) · ${formatMoney(sale.totalCents)} · ${if (sale.paymentStatus == "paid") "Pago" else "Pagamento pendente"}")
                            if (sale.paymentStatus != "paid") {
                                Button(
                                    onClick = { onOpen(BuildConfig.API_BASE_URL.trimEnd('/') + "/pedido.php?t=" + sale.publicToken) },
                                    modifier = Modifier.fillMaxWidth()
                                ) { Text("Abrir pagamento / Pix") }
                            }
                        }
                    }
                }
                items(sale.tickets) { ticket ->
                    Card {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(ticket.code, style = MaterialTheme.typography.titleMedium)
                            val url = BuildConfig.API_BASE_URL.trimEnd('/') + "/ingresso.php?t=" + ticket.qrToken
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                OutlinedButton(onClick = { onOpen(url) }) { Text("Ver ingresso") }
                                OutlinedButton(onClick = { onShare(url) }) { Text("Compartilhar") }
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun batchLabel(batch: TicketSaleBatch): String {
    val type = batch.typeName.takeIf { it.isNotBlank() }?.plus(" · ").orEmpty()
    return "$type${batch.name} · ${formatMoney(batch.priceCents)} · ${batch.available} disp."
}

private fun formatMoney(cents: Int): String =
    "R$ %.2f".format(cents / 100.0).replace('.', ',')
