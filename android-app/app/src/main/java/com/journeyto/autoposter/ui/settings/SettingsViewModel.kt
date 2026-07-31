package com.journeyto.autoposter.ui.settings

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.journeyto.autoposter.core.ServiceLocator
import kotlinx.coroutines.launch

class SettingsViewModel(private val locator: ServiceLocator) : ViewModel() {

    val siteUrl: String get() = locator.session.value?.normalizedSiteUrl.orEmpty()
    val username: String get() = locator.session.value?.username.orEmpty()

    var siteName by mutableStateOf<String?>(null)
        private set
    var socialLinks by mutableStateOf<Map<String, String>>(emptyMap())
        private set
    var pluginDetected by mutableStateOf(true)
        private set

    init {
        viewModelScope.launch {
            val info = locator.repository.getSiteInfo()
            if (info == null) {
                pluginDetected = false
            } else {
                siteName = info.siteName
                socialLinks = info.socialLinks
            }
        }
    }

    fun signOut() {
        locator.signOut()
    }
}
