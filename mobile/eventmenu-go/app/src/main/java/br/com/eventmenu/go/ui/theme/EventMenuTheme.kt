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
import br.com.eventmenu.go.data.AppBranding

private val DefaultBranding = AppBranding()
private val Border = Color(0xFFE5E1EC)
private val Gold = Color(0xFFF5B942)
private val Red = Color(0xFFE34855)

val LocalEventMenuBranding = staticCompositionLocalOf { DefaultBranding }

private val EventMenuShapes = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

private val EventMenuTypography = Typography(
    headlineLarge = TextStyle(fontSize = 31.sp, lineHeight = 35.sp, fontWeight = FontWeight.ExtraBold),
    headlineMedium = TextStyle(fontSize = 25.sp, lineHeight = 30.sp, fontWeight = FontWeight.ExtraBold),
    headlineSmall = TextStyle(fontSize = 20.sp, lineHeight = 25.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 18.sp, lineHeight = 23.sp, fontWeight = FontWeight.Bold),
    titleMedium = TextStyle(fontSize = 15.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 13.sp, lineHeight = 19.sp),
    labelLarge = TextStyle(fontSize = 13.sp, lineHeight = 17.sp, fontWeight = FontWeight.Bold),
)

@Composable
fun EventMenuTheme(branding: AppBranding = DefaultBranding, content: @Composable () -> Unit) {
    val active = if (branding.applyApp) branding else DefaultBranding
    val primary = parseColor(active.primaryColor, Color(0xFF5B34D6))
    val secondary = parseColor(active.secondaryColor, Color(0xFF159B63))
    val background = parseColor(active.backgroundColor, Color(0xFFF6F7FB))
    val surface = parseColor(active.surfaceColor, Color.White)
    val text = parseColor(active.textColor, Color(0xFF1E1B2B))
    val scheme = lightColorScheme(
        primary = primary,
        onPrimary = contrastText(primary),
        primaryContainer = blend(primary, Color.White, .88f),
        onPrimaryContainer = text,
        secondary = secondary,
        onSecondary = contrastText(secondary),
        secondaryContainer = blend(secondary, Color.White, .88f),
        onSecondaryContainer = text,
        tertiary = Gold,
        onTertiary = Color(0xFF2A1D00),
        background = background,
        onBackground = text,
        surface = surface,
        onSurface = text,
        surfaceVariant = blend(surface, primary, .05f),
        onSurfaceVariant = blend(text, background, .38f),
        outline = blend(Border, primary, .05f),
        error = Red,
        onError = Color.White,
    )

    CompositionLocalProvider(LocalEventMenuBranding provides active) {
        MaterialTheme(
            colorScheme = scheme,
            typography = EventMenuTypography,
            shapes = EventMenuShapes,
            content = content,
        )
    }
}

private fun parseColor(hex: String, fallback: Color): Color {
    val clean = hex.trim()
    if (!Regex("^#[0-9A-Fa-f]{6}$").matches(clean)) return fallback
    return runCatching { Color(AndroidColor.parseColor(clean)) }.getOrDefault(fallback)
}

private fun blend(a: Color, b: Color, amount: Float): Color {
    val t = amount.coerceIn(0f, 1f)
    return Color(
        red = a.red + (b.red - a.red) * t,
        green = a.green + (b.green - a.green) * t,
        blue = a.blue + (b.blue - a.blue) * t,
        alpha = 1f,
    )
}

private fun contrastText(background: Color): Color {
    val luminance = .2126f * background.red + .7152f * background.green + .0722f * background.blue
    return if (luminance > .58f) Color(0xFF17141F) else Color.White
}
