package com.journeyto.autoposter.ui.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.journeyto.autoposter.BuildConfig
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.core.ViewModelFactory
import com.journeyto.autoposter.ui.components.SectionLabel
import com.journeyto.autoposter.ui.components.SecondaryButton
import com.journeyto.autoposter.ui.components.SocialIconRow
import com.journeyto.autoposter.ui.components.StripeCard
import com.journeyto.autoposter.ui.theme.MutedText
import com.journeyto.autoposter.ui.theme.SlateText

@Composable
fun SettingsScreen(locator: ServiceLocator, onSignedOut: () -> Unit) {
    val viewModel: SettingsViewModel = viewModel(factory = ViewModelFactory { SettingsViewModel(locator) })

    Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
        Spacer(modifier = Modifier.height(16.dp))
        Text("Settings", style = MaterialTheme.typography.headlineMedium)
        Spacer(modifier = Modifier.height(20.dp))

        SectionLabel("Connected site")
        StripeCard {
            Text(viewModel.siteName ?: viewModel.siteUrl, style = MaterialTheme.typography.titleMedium)
            Spacer(modifier = Modifier.height(2.dp))
            Text(viewModel.siteUrl, style = MaterialTheme.typography.bodySmall, color = SlateText)
            Spacer(modifier = Modifier.height(2.dp))
            Text("Signed in as ${viewModel.username}", style = MaterialTheme.typography.bodySmall, color = MutedText)
        }

        Spacer(modifier = Modifier.height(24.dp))

        SectionLabel("Follow us")
        StripeCard {
            if (!viewModel.pluginDetected) {
                Text(
                    "Install the AutoPoster Companion plugin on your site to manage these links.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MutedText,
                )
            } else if (viewModel.socialLinks.isEmpty()) {
                Text(
                    "No social links configured yet. Add them under WordPress Admin → Settings → AutoPoster Companion.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MutedText,
                )
            } else {
                SocialIconRow(links = viewModel.socialLinks)
            }
        }

        Spacer(modifier = Modifier.height(24.dp))

        SecondaryButton(text = "Log Out", onClick = { viewModel.signOut(); onSignedOut() }, modifier = Modifier.fillMaxWidth())

        Spacer(modifier = Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(modifier = Modifier.height(12.dp))
        Text("Journey To Poster v${BuildConfig.VERSION_NAME}", style = MaterialTheme.typography.labelSmall, color = MutedText)
    }
}
