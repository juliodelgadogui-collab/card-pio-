package br.com.eventmenu.go.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Dark = darkColorScheme(
    primary = Color(0xFF4ADE80),
    secondary = Color(0xFF38BDF8),
    background = Color(0xFF090D12),
    surface = Color(0xFF111821),
    surfaceVariant = Color(0xFF19232F),
)

private val Light = lightColorScheme(
    primary = Color(0xFF087A45),
    secondary = Color(0xFF0369A1),
    background = Color(0xFFF5F7FA),
    surface = Color.White,
)

@Composable
fun EventMenuTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) Dark else Light, content = content)
}
