package com.journeyto.autoposter.ui.login

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Public
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.core.ViewModelFactory
import com.journeyto.autoposter.ui.components.ErrorBanner
import com.journeyto.autoposter.ui.components.PrimaryButton
import com.journeyto.autoposter.ui.components.StripeTextField
import com.journeyto.autoposter.ui.theme.EarthGreen
import com.journeyto.autoposter.ui.theme.MutedText
import com.journeyto.autoposter.ui.theme.OceanBlue
import com.journeyto.autoposter.ui.theme.OceanBlueTint
import com.journeyto.autoposter.ui.theme.SlateText

@Composable
fun LoginScreen(locator: ServiceLocator, onSignedIn: () -> Unit) {
    val viewModel: LoginViewModel = viewModel(factory = ViewModelFactory { LoginViewModel(locator) })

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(modifier = Modifier.height(48.dp))

        Box(
            modifier = Modifier
                .size(72.dp)
                .background(OceanBlueTint, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Filled.Public, contentDescription = null, tint = OceanBlue, modifier = Modifier.size(36.dp))
        }

        Spacer(modifier = Modifier.height(20.dp))
        Text("Journey To Poster", style = MaterialTheme.typography.headlineMedium)
        Spacer(modifier = Modifier.height(6.dp))
        Text(
            "Connect your WordPress site to create, manage and publish posts.",
            style = MaterialTheme.typography.bodyMedium,
            color = SlateText,
            textAlign = TextAlign.Center,
        )

        Spacer(modifier = Modifier.height(32.dp))

        StripeTextField(
            value = viewModel.siteUrl,
            onValueChange = { viewModel.siteUrl = it },
            label = "Site URL",
            placeholder = "letsjourneyto.com",
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
        )
        Spacer(modifier = Modifier.height(12.dp))
        StripeTextField(
            value = viewModel.username,
            onValueChange = { viewModel.username = it },
            label = "WordPress Username",
        )
        Spacer(modifier = Modifier.height(12.dp))
        StripeTextField(
            value = viewModel.appPassword,
            onValueChange = { viewModel.appPassword = it },
            label = "Application Password",
            placeholder = "xxxx xxxx xxxx xxxx xxxx xxxx",
            supportingText = "WP Admin → Users → Your Profile → Application Passwords → Add New",
        )

        Spacer(modifier = Modifier.height(20.dp))

        viewModel.errorMessage?.let {
            ErrorBanner(message = it)
            Spacer(modifier = Modifier.height(16.dp))
        }

        PrimaryButton(
            text = "Connect",
            onClick = { viewModel.signIn(onSignedIn) },
            loading = viewModel.isLoading,
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(modifier = Modifier.height(24.dp))
        Text(
            "Powered by WordPress's own REST API — no extra server required.",
            style = MaterialTheme.typography.labelSmall,
            color = MutedText,
            textAlign = TextAlign.Center,
        )
    }
}
