package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.GoState
import br.com.eventmenu.go.ShiftUnitState
import br.com.eventmenu.go.ui.theme.LocalTenantBrand

@Composable
fun UnitShiftStartScreen(
    state: GoState,
    unitState: ShiftUnitState,
    onSelectUnit: (Int?) -> Unit,
    onStart: () -> Unit,
    onChangeMode: (() -> Unit)?,
    onLogout: () -> Unit,
) {
    val user = state.session?.user ?: return
    val mode = state.mode ?: return
    val brand = LocalTenantBrand.current
    val company = brand?.displayName?.takeIf { it.isNotBlank() }
        ?: unitState.tenantName.takeIf(::unitUseful)
        ?: user.tenantName.takeIf(::unitUseful)
        ?: "Sua empresa"

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text(company, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
            Text("Olá, ${user.name.trim().substringBefore(' ')}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("${unitModeLabel(mode.wire)} · turno ainda não iniciado", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }

        if (unitState.units.isNotEmpty()) {
            item {
                Text("Onde você vai trabalhar?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                Text("Escolha a unidade deste turno.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            items(unitState.units, key = { it.id }) { unit ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        FilterChip(
                            selected = unitState.selectedUnitId == unit.id,
                            onClick = { onSelectUnit(unit.id) },
                            label = { Text(unit.name) },
                        )
                        unit.address.takeIf(::unitUseful)?.let { Text(it, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        if (unit.isDefault) Text("Unidade principal", color = MaterialTheme.colorScheme.primary)
                    }
                }
            }
        } else {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Unidade principal", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text("Seu turno será iniciado na unidade principal.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }

        item {
            Button(
                onClick = onStart,
                enabled = !unitState.loading && (unitState.units.size <= 1 || unitState.selectedUnitId != null),
                modifier = Modifier.fillMaxWidth(),
            ) { Text(if (unitState.loading) "Iniciando..." else "Iniciar turno") }
        }
        onChangeMode?.let { change ->
            item { OutlinedButton(onClick = change, modifier = Modifier.fillMaxWidth()) { Text("Trocar atividade") } }
        }
        item { OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("Sair") } }
    }
}

private fun unitModeLabel(mode: String) = when (mode.lowercase()) {
    "operation" -> "Operação"
    "delivery" -> "Delivery"
    "events" -> "Eventos"
    "pay" -> "Pagamentos"
    else -> "Operação"
}

private fun unitUseful(value: String): Boolean {
    val clean = value.trim()
    return clean.isNotBlank() && !clean.equals("null", true) && !clean.equals("undefined", true)
}
