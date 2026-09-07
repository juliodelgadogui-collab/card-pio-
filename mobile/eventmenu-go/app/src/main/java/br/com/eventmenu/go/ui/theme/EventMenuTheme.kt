package br.com.eventmenu.go.ui.theme

import android.graphics.Color as AndroidColor
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.eventmenu.go.data.TenantBrand

val LocalTenantBrand = staticCompositionLocalOf<TenantBrand?> { null }

private val DefaultPurple = Color(0xFF5B34D6)
private val PurpleDark = Color(0xFF3F1CA6)
private val PurpleSoft = Color(0xFFF1ECFF)
private val DefaultInk = Color(0xFF1E1B2B)
private val Muted = Color(0xFF716B80)
private val DefaultCanvas = Color(0xFFF6F7FB)
private val Border = Color(0xFFE5E1EC)
private val DefaultGreen = Color(0xFF159B63)
private val Gold = Color(0xFFF5B942)
private val Red = Color(0xFFE34855)

private val EventMenuShapes = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

private fun typography(ink: Color) = Typography(
    headlineLarge = TextStyle(fontSize = 31.sp, lineHeight = 35.sp, fontWeight = FontWeight.ExtraBold, color = ink),
    headlineMedium = TextStyle(fontSize = 25.sp, lineHeight = 30.sp, fontWeight = FontWeight.ExtraBold, color = ink),
    headlineSmall = TextStyle(fontSize = 20.sp, lineHeight = 25.sp, fontWeight = FontWeight.Bold, color = ink),
    titleLarge = TextStyle(fontSize = 18.sp, lineHeight = 23.sp, fontWeight = FontWeight.Bold, color = ink),
    titleMedium = TextStyle(fontSize = 15.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold, color = ink),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp, color = ink),
    bodyMedium = TextStyle(fontSize = 13.sp, lineHeight = 19.sp, color = ink),
    labelLarge = TextStyle(fontSize = 13.sp, lineHeight = 17.sp, fontWeight = FontWeight.Bold, color = ink),
)

private fun parsed(hex: String, fallback: Color): Color = runCatching {
    Color(AndroidColor.parseColor(hex))
}.getOrDefault(fallback)

@Composable
fun EventMenuTheme(brand: TenantBrand? = null, content: @Composable () -> Unit) {
    val active = brand?.takeIf { it.applyApp }
    val primary = active?.let { parsed(it.primaryColor, DefaultPurple) } ?: DefaultPurple
    val secondary = active?.let { parsed(it.secondaryColor, DefaultGreen) } ?: DefaultGreen
    val background = active?.let { parsed(it.backgroundColor, DefaultCanvas) } ?: DefaultCanvas
    val surface = active?.let { parsed(it.surfaceColor, Color.White) } ?: Color.White
    val ink = active?.let { parsed(it.textColor, DefaultInk) } ?: DefaultInk

    val scheme = lightColorScheme(
        primary = primary,
        onPrimary = Color.White,
        primaryContainer = PurpleSoft,
        onPrimaryContainer = PurpleDark,
        secondary = secondary,
        onSecondary = Color.White,
        secondaryContainer = Color(0xFFE8F8F0),
        onSecondaryContainer = Color(0xFF0A603B),
        tertiary = Gold,
        onTertiary = Color(0xFF2A1D00),
        background = background,
        onBackground = ink,
        surface = surface,
        onSurface = ink,
        surfaceVariant = surface,
        onSurfaceVariant = Muted,
        outline = Border,
        error = Red,
        onError = Color.White,
    )

    CompositionLocalProvider(LocalTenantBrand provides active) {
        MaterialTheme(
            colorScheme = scheme,
            typography = typography(ink),
            shapes = EventMenuShapes,
            content = content,
        )
    }
}
