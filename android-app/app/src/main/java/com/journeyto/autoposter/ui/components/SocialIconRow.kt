package com.journeyto.autoposter.ui.components

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import com.journeyto.autoposter.R
import com.journeyto.autoposter.ui.theme.BackgroundGray

/**
 * Known social platforms the companion plugin lets the site owner configure.
 * Keys must match the `social_links` map returned by /wp-json/autoposter/v1/site-info.
 */
enum class SocialPlatform(val key: String, val iconRes: Int, val label: String) {
    FACEBOOK("facebook", R.drawable.ic_social_facebook, "Facebook"),
    INSTAGRAM("instagram", R.drawable.ic_social_instagram, "Instagram"),
    TIKTOK("tiktok", R.drawable.ic_social_tiktok, "TikTok"),
    YOUTUBE("youtube", R.drawable.ic_social_youtube, "YouTube"),
    X("x", R.drawable.ic_social_x, "X"),
    PINTEREST("pinterest", R.drawable.ic_social_pinterest, "Pinterest"),
    LINKEDIN("linkedin", R.drawable.ic_social_linkedin, "LinkedIn"),
    WEBSITE("website", R.drawable.ic_social_website, "Website"),
}

/**
 * Renders one tappable icon per configured link; platforms with no URL are skipped.
 */
@Composable
fun SocialIconRow(
    links: Map<String, String>,
    modifier: Modifier = Modifier,
    iconSize: androidx.compose.ui.unit.Dp = 40.dp,
) {
    val context = LocalContext.current
    val configured = SocialPlatform.values().filter { !links[it.key].isNullOrBlank() }
    if (configured.isEmpty()) return

    Row(modifier = modifier, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        configured.forEach { platform ->
            val url = links.getValue(platform.key)
            Box(
                modifier = Modifier
                    .size(iconSize)
                    .background(BackgroundGray, CircleShape)
                    .clickable {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                    },
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    painter = painterResource(platform.iconRes),
                    contentDescription = platform.label,
                    tint = androidx.compose.ui.graphics.Color.Unspecified,
                    modifier = Modifier.size(iconSize * 0.55f),
                )
            }
        }
    }
}
