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
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.eventmenu.go.data.TenantBrand

val LocalTenantBrand = staticCompositionLocalOf<TenantBrand?> { null }

object EventMenuUi {
    val SpaceXs: Dp = 6.dp
    val SpaceSm: Dp = 10.dp
    val SpaceMd: Dp = 16.dp
    val SpaceLg: Dp = 22.dp
    val SpaceXl: Dp = 28.dp
    val TouchTarget: Dp = 48.dp
    val ActionHeight: Dp = 56.dp
    val CardRadius: Dp = 20.dp
    val SheetRadius: Dp = 28.dp

    val Success = Color(0xFF0F9F6E)
    val Warning = Color(0xFFC27B08)
    val Danger = Color(0xFFD92D48)
    val Info = Color(0xFF2563EB)
}

private val DefaultPurple = Color(0xFF5B34D6)
private val PurpleDark = Color(0xFF3F1CA6)
private val PurpleSoft = Color(0xFFF1ECFF)
private val DefaultInk = Color(0xFF1D1A27)
private val Muted = Color(0xFF6F6A79)
private val DefaultCanvas = Color(0xFFF5F6FA)
private val Border = Color(0xFFE2DFE8)
private val SurfaceSoft = Color(0xFFF0F1F6)
private val SuccessSoft = Color(0xFFE8F8F0)
private val SuccessInk = Color(0xFF087750)
private val WarningSoft = Color(0xFFFFF5E5)
private val SnackbarSurface = Color(0xFF241F2B)
private val SnackbarText = Color(0xFFFFFFFF)
private val SnackbarAction = Color(0xFFD8CAFF)

private val EventMenuShapes = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(EventMenuUi.CardRadius),
    extraLarge = RoundedCornerShape(EventMenuUi.SheetRadius),
)

private fun typography(ink: Color) = Typography(
    headlineLarge = TextStyle(fontSize = 31.sp, lineHeight = 36.sp, fontWeight = FontWeight.ExtraBold, color = ink),
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
    val background = active?.let { parsed(it.backgroundColor, DefaultCanvas) } ?: DefaultCanvas
    val surface = active?.let { parsed(it.surfaceColor, Color.White) } ?: Color.White
    val ink = active?.let { parsed(it.textColor, DefaultInk) } ?: DefaultInk

    val scheme = lightColorScheme(
        primary = primary,
        onPrimary = Color.White,
        primaryContainer = PurpleSoft,
        onPrimaryContainer = PurpleDark,
        secondary = EventMenuUi.Success,
        onSecondary = Color.White,
        secondaryContainer = SuccessSoft,
        onSecondaryContainer = SuccessInk,
        tertiary = EventMenuUi.Warning,
        onTertiary = Color.White,
        tertiaryContainer = WarningSoft,
        onTertiaryContainer = Color(0xFF7A4D00),
        background = background,
        onBackground = ink,
        surface = surface,
        onSurface = ink,
        surfaceVariant = SurfaceSoft,
        onSurfaceVariant = Muted,
        outline = Border,
        outlineVariant = Border,
        error = EventMenuUi.Danger,
        onError = Color.White,
        errorContainer = Color(0xFFFFEDF0),
        onErrorContainer = Color(0xFF8D263A),
        inverseSurface = SnackbarSurface,
        inverseOnSurface = SnackbarText,
        inversePrimary = SnackbarAction,
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
