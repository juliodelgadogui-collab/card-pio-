package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.IssuedUniversalQr
import br.com.eventmenu.go.data.ResolvedUniversalQr

@Composable
fun UniversalQrResultDialog(
    result: ResolvedUniversalQr,
    actionLabel: String? = null,
    actionEnabled: Boolean = true,
    actionHint: String = "",
    onAction: (() -> Unit)? = null,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(result.title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (result.subtitle.isNotBlank()) Text(result.subtitle, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                result.details.forEach { Text(it) }
                if (result.type == "delivery_user") {
                    Text(if (result.deliveryShiftOpen) "🟢 Turno Delivery aberto" else "⚪ Sem turno Delivery aberto", fontWeight = FontWeight.Bold)
                }
                if (actionHint.isNotBlank()) Text(actionHint)
                Text("Identidade resolvida pela API EventMenu. O QR não concede permissões por conta própria.")
            }
        },
        confirmButton = {
            if (actionLabel != null && onAction != null) {
                Button(onClick = onAction, enabled = actionEnabled) { Text(actionLabel) }
            } else TextButton(onClick = onDismiss) { Text("FECHAR") }
        },
        dismissButton = {
            if (actionLabel != null) TextButton(onClick = onDismiss) { Text("CANCELAR") }
        },
    )
}

@Composable
fun MyEmployeeQrDialog(
    qr: IssuedUniversalQr,
    onRevoke: () -> Unit,
    onDismiss: () -> Unit,
) {
    val bitmap = remember(qr.payload) { qrBitmap(qr.payload) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (qr.type == "delivery_user") "Meu QR de Entregador" else "Meu QR de Funcionário") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        bitmap?.let { Image(it.asImageBitmap(), contentDescription = "QR do funcionário", modifier = Modifier.fillMaxWidth()) }
                        if (qr.label.isNotBlank()) Text(qr.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        qr.expiresAt.takeIf { it.isNotBlank() }?.let { Text("Expira: $it") }
                    }
                }
                Text("Gerar um novo QR revoga o anterior. O código identifica você; permissões e turno são sempre conferidos no servidor.")
                OutlinedButton(onClick = onRevoke, modifier = Modifier.fillMaxWidth()) { Text("REVOGAR ESTE QR") }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("FECHAR") } },
    )
}
