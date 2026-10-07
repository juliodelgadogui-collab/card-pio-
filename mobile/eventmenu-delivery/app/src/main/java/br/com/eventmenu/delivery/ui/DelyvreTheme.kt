package br.com.eventmenu.delivery.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.shape.RoundedCornerShape

val DelyvreBrand = Color(0xFFFF4F3D)
val DelyvreBrandStrong = Color(0xFFE83C2C)
val DelyvreInk = Color(0xFF211B19)
val DelyvreMuted = Color(0xFF756C68)
val DelyvreCanvas = Color(0xFFFFFAF7)
val DelyvreSurfaceMuted = Color(0xFFF7F3F0)
val DelyvreLine = Color(0xFFEEE5E1)
val DelyvreSuccess = Color(0xFF168451)
val DelyvreWarning = Color(0xFFA76300)

private val DelyvreColors = lightColorScheme(
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
)

private val DelyvreShapes = Shapes(
    extraSmall = RoundedCornerShape(10.dp),
    small = RoundedCornerShape(14.dp),
    medium = RoundedCornerShape(18.dp),
    large = RoundedCornerShape(24.dp),
    extraLarge = RoundedCornerShape(30.dp),
)

private val DelyvreTypography = Typography().let { base ->
    base.copy(
        headlineLarge = base.headlineLarge.copy(fontWeight = FontWeight.Black),
        headlineMedium = base.headlineMedium.copy(fontWeight = FontWeight.Black),
        headlineSmall = base.headlineSmall.copy(fontWeight = FontWeight.ExtraBold),
        titleLarge = base.titleLarge.copy(fontWeight = FontWeight.Bold),
        titleMedium = base.titleMedium.copy(fontWeight = FontWeight.Bold),
        labelLarge = base.labelLarge.copy(fontWeight = FontWeight.Bold),
    )
}

@Composable
fun DelyvreTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DelyvreColors,
        shapes = DelyvreShapes,
        typography = DelyvreTypography,
        content = content,
    )
}
