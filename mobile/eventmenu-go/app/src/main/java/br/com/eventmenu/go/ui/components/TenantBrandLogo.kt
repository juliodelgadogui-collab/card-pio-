package br.com.eventmenu.go.ui.components

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.net.HttpURLConnection
import java.net.URL

@Composable
fun TenantBrandLogo(
    logoUrl: String,
    displayName: String,
    size: Dp = 48.dp,
    modifier: Modifier = Modifier,
) {
    val bitmap by produceState<android.graphics.Bitmap?>(initialValue = null, key1 = logoUrl) {
        value = if (logoUrl.isBlank()) null else withContext(Dispatchers.IO) {
            runCatching {
                val connection = URL(logoUrl).openConnection() as HttpURLConnection
                connection.connectTimeout = 5_000
                connection.readTimeout = 7_000
                connection.instanceFollowRedirects = true
                try {
                    connection.inputStream.use { input -> BitmapFactory.decodeStream(input) }
                } finally {
                    connection.disconnect()
                }
            }.getOrNull()
        }
    }

    val shape = RoundedCornerShape((size.value * .24f).dp)
    Box(
        modifier = modifier.size(size).clip(shape).background(MaterialTheme.colorScheme.surface),
        contentAlignment = Alignment.Center,
    ) {
        val image = bitmap
        if (image != null) {
            Image(
                bitmap = image.asImageBitmap(),
                contentDescription = displayName,
                modifier = Modifier.size(size),
                contentScale = ContentScale.Fit,
            )
        } else {
            Text(
                text = displayName.trim().firstOrNull()?.uppercaseChar()?.toString() ?: "E",
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Black,
            )
        }
    }
}
