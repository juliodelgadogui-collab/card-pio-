package br.com.eventmenu.go.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

private val Purple = Color(0xFF5B34D6)
private val PurpleDark = Color(0xFF3F1CA6)
private val PurpleSoft = Color(0xFFF1ECFF)
private val Ink = Color(0xFF1E1B2B)
private val Muted = Color(0xFF716B80)
private val Canvas = Color(0xFFF6F7FB)
private val Border = Color(0xFFE5E1EC)
private val Green = Color(0xFF159B63)
private val Gold = Color(0xFFF5B942)
private val Red = Color(0xFFE34855)

private val EventMenuLight = lightColorScheme(
    primary = Purple,
    onPrimary = Color.White,
    primaryContainer = PurpleSoft,
    onPrimaryContainer = PurpleDark,
    secondary = Green,
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFE8F8F0),
    onSecondaryContainer = Color(0xFF0A603B),
    tertiary = Gold,
    onTertiary = Color(0xFF2A1D00),
    background = Canvas,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFF3F0F8),
    onSurfaceVariant = Muted,
    outline = Border,
    error = Red,
    onError = Color.White,
)

private val EventMenuShapes = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

private val EventMenuTypography = Typography(
    headlineLarge = TextStyle(fontSize = 31.sp, lineHeight = 35.sp, fontWeight = FontWeight.ExtraBold, color = Ink),
    headlineMedium = TextStyle(fontSize = 25.sp, lineHeight = 30.sp, fontWeight = FontWeight.ExtraBold, color = Ink),
    headlineSmall = TextStyle(fontSize = 20.sp, lineHeight = 25.sp, fontWeight = FontWeight.Bold, color = Ink),
    titleLarge = TextStyle(fontSize = 18.sp, lineHeight = 23.sp, fontWeight = FontWeight.Bold, color = Ink),
    titleMedium = TextStyle(fontSize = 15.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold, color = Ink),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp, color = Ink),
    bodyMedium = TextStyle(fontSize = 13.sp, lineHeight = 19.sp, color = Ink),
    labelLarge = TextStyle(fontSize = 13.sp, lineHeight = 17.sp, fontWeight = FontWeight.Bold, color = Ink),
)

@Composable
fun EventMenuTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = EventMenuLight,
        typography = EventMenuTypography,
        shapes = EventMenuShapes,
        content = content,
    )
}
