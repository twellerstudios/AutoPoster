package com.journeyto.autoposter.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

// Always light: white surfaces with ocean-blue / earth-green accents, per brand.
private val AutoPosterColorScheme = lightColorScheme(
    primary = OceanBlue,
    onPrimary = SurfaceWhite,
    primaryContainer = OceanBlueTint,
    onPrimaryContainer = OceanBlueDark,
    secondary = EarthGreen,
    onSecondary = SurfaceWhite,
    secondaryContainer = EarthGreenTint,
    onSecondaryContainer = EarthGreenDark,
    background = BackgroundGray,
    onBackground = InkText,
    surface = SurfaceWhite,
    onSurface = InkText,
    surfaceVariant = BackgroundGray,
    onSurfaceVariant = SlateText,
    outline = BorderGray,
    error = ErrorRed,
    onError = SurfaceWhite,
    errorContainer = ErrorRedTint,
    onErrorContainer = ErrorRed,
)

@Composable
fun AutoPosterTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = AutoPosterColorScheme,
        typography = AutoPosterTypography,
        shapes = AutoPosterShapes,
        content = content,
    )
}
