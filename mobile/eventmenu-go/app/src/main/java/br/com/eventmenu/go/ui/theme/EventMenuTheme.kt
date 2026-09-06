package br.com.eventmenu.go.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

private val Gold = Color(0xFFF4B942)
private val GoldSoft = Color(0xFFFFD675)
private val Ink = Color(0xFF080C13)
private val SurfaceDark = Color(0xFF111823)
private val SurfaceRaised = Color(0xFF182231)
private val BorderDark = Color(0xFF2A374A)

private val Dark = darkColorScheme(
    primary = Gold,
    onPrimary = Color(0xFF1B1405),
    primaryContainer = Color(0xFF3D2E0E),
    onPrimaryContainer = GoldSoft,
    secondary = Color(0xFF76D9B0),
    onSecondary = Color(0xFF052117),
    tertiary = Color(0xFF8CB7FF),
    background = Ink,
    onBackground = Color(0xFFF7F9FC),
    surface = SurfaceDark,
    onSurface = Color(0xFFF7F9FC),
    surfaceVariant = SurfaceRaised,
    onSurfaceVariant = Color(0xFFB7C1D0),
    outline = BorderDark,
    error = Color(0xFFFF7E87),
)

private val Light = lightColorScheme(
    primary = Color(0xFF9A6700),
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE5A5),
    onPrimaryContainer = Color(0xFF2B1B00),
    secondary = Color(0xFF197452),
    background = Color(0xFFF5F7FA),
    onBackground = Color(0xFF151B24),
    surface = Color.White,
    onSurface = Color(0xFF151B24),
    surfaceVariant = Color(0xFFEDF1F6),
    onSurfaceVariant = Color(0xFF566273),
    outline = Color(0xFFD3DAE4),
)

private val PremiumShapes = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(18.dp),
    large = RoundedCornerShape(24.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

private val PremiumTypography = Typography(
    headlineLarge = TextStyle(fontSize = 32.sp, lineHeight = 36.sp, fontWeight = FontWeight.Black),
    headlineMedium = TextStyle(fontSize = 26.sp, lineHeight = 30.sp, fontWeight = FontWeight.Black),
    headlineSmall = TextStyle(fontSize = 22.sp, lineHeight = 27.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 20.sp, lineHeight = 25.sp, fontWeight = FontWeight.Bold),
    titleMedium = TextStyle(fontSize = 16.sp, lineHeight = 21.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 23.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontSize = 14.sp, lineHeight = 18.sp, fontWeight = FontWeight.Bold),
)

@Composable
fun EventMenuTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (isSystemInDarkTheme()) Dark else Light,
        typography = PremiumTypography,
        shapes = PremiumShapes,
        content = content,
    )
}
