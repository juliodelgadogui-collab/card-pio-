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

private val Purple = Color(0xFF6C36E8)
private val PurpleDark = Color(0xFF4B1FB6)
private val PurpleSoft = Color(0xFFF0E9FF)
private val Ink = Color(0xFF1D1B2A)
private val Muted = Color(0xFF716C80)
private val Canvas = Color(0xFFF7F6FB)
private val Border = Color(0xFFE7E3EF)
private val Green = Color(0xFF159B63)
private val Red = Color(0xFFE34855)

private val EventMenuLight = lightColorScheme(
    primary = Purple,
    onPrimary = Color.White,
    primaryContainer = PurpleSoft,
    onPrimaryContainer = PurpleDark,
    secondary = Green,
    onSecondary = Color.White,
    tertiary = Color(0xFF3D7AE8),
    background = Canvas,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFF2EFF8),
    onSurfaceVariant = Muted,
    outline = Border,
    error = Red,
    onError = Color.White,
)

private val EventMenuShapes = Shapes(
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(18.dp),
    extraLarge = RoundedCornerShape(24.dp),
)

private val EventMenuTypography = Typography(
    headlineLarge = TextStyle(fontSize = 30.sp, lineHeight = 34.sp, fontWeight = FontWeight.ExtraBold, color = Ink),
    headlineMedium = TextStyle(fontSize = 24.sp, lineHeight = 29.sp, fontWeight = FontWeight.ExtraBold, color = Ink),
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
