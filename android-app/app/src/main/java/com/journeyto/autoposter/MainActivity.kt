package com.journeyto.autoposter

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import com.journeyto.autoposter.ui.navigation.AutoPosterNavHost
import com.journeyto.autoposter.ui.theme.AutoPosterTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        val locator = (application as AutoPosterApplication).locator

        setContent {
            AutoPosterTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    AutoPosterNavHost(locator)
                }
            }
        }
    }
}
