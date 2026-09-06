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
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("EVENTMENU GO", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            if (unitState.tenantName.isNotBlank()) Text("Empresa: ${unitState.tenantName}", style = MaterialTheme.typography.titleLarge)
            Text("Funcionário: ${user.name}")
            Text("Modo: ${mode.label}")
            Text("Turno: Fechado")
        }

        if (unitState.units.isNotEmpty()) {
            item { Text("Unidade", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black) }
            items(unitState.units, key = { it.id }) { unit ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        FilterChip(
                            selected = unitState.selectedUnitId == unit.id,
                            onClick = { onSelectUnit(unit.id) },
                            label = { Text(unit.name) },
                        )
                        if (unit.address.isNotBlank()) Text(unit.address)
                        if (unit.isDefault) Text("Unidade padrão", color = MaterialTheme.colorScheme.primary)
                    }
                }
            }
        } else {
            item { Text("Unidade: Principal", style = MaterialTheme.typography.titleMedium) }
            item { Text("Esta empresa ainda não usa separação por unidades; a operação continua no escopo principal.") }
        }

        item {
            Button(
                onClick = onStart,
                enabled = !unitState.loading && (unitState.units.size <= 1 || unitState.selectedUnitId != null),
                modifier = Modifier.fillMaxWidth(),
            ) { Text("INICIAR TURNO") }
        }
        onChangeMode?.let { change ->
            item { OutlinedButton(onClick = change, modifier = Modifier.fillMaxWidth()) { Text("TROCAR MODO") } }
        }
        item { OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text("SAIR") } }
        item { Text("A unidade é validada pela API e gravada no turno; mudar valores no celular não concede acesso a outra unidade.") }
    }
}
