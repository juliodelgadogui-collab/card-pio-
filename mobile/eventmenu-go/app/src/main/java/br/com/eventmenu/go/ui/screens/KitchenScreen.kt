package br.com.eventmenu.go.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import br.com.eventmenu.go.data.KitchenTicket
import kotlinx.coroutines.delay
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
fun KitchenScreen(tickets: List<KitchenTicket>, onRefresh: () -> Unit, onStatus: (Int,String) -> Unit) {
    LaunchedEffect(Unit) { while (true) { delay(15_000); onRefresh() } }
    Column(Modifier.fillMaxSize().padding(12.dp)) {
        Row(Modifier.fillMaxWidth().padding(bottom=10.dp),horizontalArrangement=Arrangement.SpaceBetween){
            Column{Text("Cozinha / KDS",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Black);Text("Novos ${tickets.count{it.status=="confirmed"}} · Preparando ${tickets.count{it.status=="preparing"}}")}
            Button(onClick=onRefresh){Text("ATUALIZAR")}
        }
        LazyVerticalGrid(columns=GridCells.Adaptive(290.dp),modifier=Modifier.fillMaxSize(),horizontalArrangement=Arrangement.spacedBy(10.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){
            items(tickets,key={it.id}){ticket->
                Card(Modifier.fillMaxWidth()){
                    Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(7.dp)){
                        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){
                            Text("#${ticket.id}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black)
                            Text(elapsed(ticket.createdAt),fontWeight=FontWeight.Black)
                        }
                        Text(if(ticket.status=="confirmed")"🆕 NOVO" else "🔥 PREPARANDO",color=MaterialTheme.colorScheme.primary,fontWeight=FontWeight.Bold)
                        val origin=ticket.tableName.ifBlank{ticket.customerName.ifBlank{ticket.channel.uppercase()}}
                        Text(origin)
                        ticket.items.forEach{item->
                            Column{Text("${formatQty(item.quantity)}× ${item.name}",style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.Bold);if(item.notes.isNotBlank())Text("Obs.: ${item.notes}")}
                        }
                        if(ticket.notes.isNotBlank())Text("PEDIDO: ${ticket.notes}",fontWeight=FontWeight.Bold)
                        if(ticket.status=="confirmed")Button(onClick={onStatus(ticket.id,"preparing")},modifier=Modifier.fillMaxWidth()){Text("INICIAR PREPARO")}
                        else Button(onClick={onStatus(ticket.id,"ready")},modifier=Modifier.fillMaxWidth()){Text("PEDIDO PRONTO")}
                    }
                }
            }
        }
    }
}

private fun elapsed(value:String):String{
    val created=runCatching{SimpleDateFormat("yyyy-MM-dd HH:mm:ss",Locale.US).parse(value)?.time}.getOrNull()?:return "—"
    val seconds=((Date().time-created)/1000).coerceAtLeast(0);val min=seconds/60;val sec=seconds%60
    return "%02d:%02d".format(min,sec)
}
private fun formatQty(q:Double)=if(q%1.0==0.0)q.toInt().toString() else q.toString()
