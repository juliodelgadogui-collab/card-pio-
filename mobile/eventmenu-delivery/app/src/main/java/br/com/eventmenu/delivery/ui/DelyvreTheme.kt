package br.com.eventmenu.delivery.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight

val DelyvreBrand = Color(0xFFFF4F3D)
val DelyvreBrandStrong = Color(0xFFE83C2C)
val DelyvreInk = Color(0xFF211B19)
val DelyvreMuted = Color(0xFF756C68)
val DelyvreCanvas = Color(0xFFFFFAF7)
val DelyvreSurfaceMuted = Color(0xFFF7F3F0)
val DelyvreLine = Color(0xFFEEE5E1)
val DelyvreSuccess = Color(0xFF168451)
val DelyvreWarning = Color(0xFFA76300)

private val DelyvreLightColors = lightColorScheme(
    primary = DelyvreBrand,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE1DB),
    onPrimaryContainer = Color(0xFF7E160D),
    secondary = DelyvreInk,
    onSecondary = Color.White,
    secondaryContainer = DelyvreSurfaceMuted,
    onSecondaryContainer = DelyvreInk,
    background = DelyvreCanvas,
    onBackground = DelyvreInk,
    surface = Color.White,
    onSurface = DelyvreInk,
    surfaceVariant = DelyvreSurfaceMuted,
    onSurfaceVariant = DelyvreMuted,
    outline = Color(0xFFD7CCC7),
    outlineVariant = DelyvreLine,
    error = Color(0xFFC83232),
    onError = Color.White,
    errorContainer = Color(0xFFFFDAD7),
    onErrorContainer = Color(0xFF410004),
)

private val DelyvreDarkColors = darkColorScheme(
    primary = Color(0xFFFFB4A8),
    onPrimary = Color(0xFF690006),
    primaryContainer = Color(0xFF93000D),
    onPrimaryContainer = Color(0xFFFFDAD5),
    secondary = Color(0xFFE8BDB6),
    onSecondary = Color(0xFF442925),
    secondaryContainer = Color(0xFF5D3F3A),
    onSecondaryContainer = Color(0xFFFFDAD5),
    background = Color(0xFF171211),
    onBackground = Color(0xFFF1DEDA),
    surface = Color(0xFF1F1A18),
    onSurface = Color(0xFFF1DEDA),
    surfaceVariant = Color(0xFF2B2523),
    onSurfaceVariant = Color(0xFFD9C2BD),
    outline = Color(0xFFA98C86),
    outlineVariant = Color(0xFF55413D),
    error = Color(0xFFFFB4AB),
    onError = Color(0xFF690005),
    errorContainer = Color(0xFF93000A),
    onErrorContainer = Color(0xFFFFDAD6),
)

private val DelyvreShapes = Shapes(
    extraSmall = RoundedCornerShape(DelyvreSpacing.xs),
    small = RoundedCornerShape(DelyvreSpacing.sm),
    medium = RoundedCornerShape(DelyvreSize.cardRadius),
    large = RoundedCornerShape(DelyvreSize.largeCardRadius),
    extraLarge = RoundedCornerShape(DelyvreSize.sheetRadius),
)

private val DelyvreTypography = Typography().let { base ->
    base.copy(
        headlineLarge = base.headlineLarge.copy(fontWeight = FontWeight.Black),
        headlineMedium = base.headlineMedium.copy(fontWeight = FontWeight.Black),
        headlineSmall = base.headlineSmall.copy(fontWeight = FontWeight.ExtraBold),
        titleLarge = base.titleLarge.copy(fontWeight = FontWeight.Bold),
        titleMedium = base.titleMedium.copy(fontWeight = FontWeight.Bold),
        titleSmall = base.titleSmall.copy(fontWeight = FontWeight.SemiBold),
        labelLarge = base.labelLarge.copy(fontWeight = FontWeight.Bold),
        labelMedium = base.labelMedium.copy(fontWeight = FontWeight.SemiBold),
    )
}

@Composable
fun DelyvreTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DelyvreDarkColors else DelyvreLightColors,
        shapes = DelyvreShapes,
        typography = DelyvreTypography,
        content = content,
    )
}
