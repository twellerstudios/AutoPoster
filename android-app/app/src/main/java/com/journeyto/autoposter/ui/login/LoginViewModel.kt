package com.journeyto.autoposter.ui.login

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.journeyto.autoposter.common.ApiResult
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.data.repository.SiteCredentials
import kotlinx.coroutines.launch

class LoginViewModel(private val locator: ServiceLocator) : ViewModel() {

    var siteUrl by mutableStateOf("")
    var username by mutableStateOf("")
    var appPassword by mutableStateOf("")
    var isLoading by mutableStateOf(false)
        private set
    var errorMessage by mutableStateOf<String?>(null)
        private set

    fun signIn(onSuccess: () -> Unit) {
        val trimmedUrl = siteUrl.trim()
        val trimmedUser = username.trim()
        val trimmedPassword = appPassword.trim()

        if (trimmedUrl.isBlank() || trimmedUser.isBlank() || trimmedPassword.isBlank()) {
            errorMessage = "Please fill in every field."
            return
        }

        val normalizedUrl = if (!trimmedUrl.startsWith("http://") && !trimmedUrl.startsWith("https://")) {
            "https://$trimmedUrl"
        } else trimmedUrl

        val credentials = SiteCredentials(normalizedUrl, trimmedUser, trimmedPassword)

        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            when (val result = locator.repository.verifyLogin(credentials)) {
                is ApiResult.Success -> {
                    locator.signIn(credentials)
                    isLoading = false
                    onSuccess()
                }
                is ApiResult.Error -> {
                    isLoading = false
                    errorMessage = result.message
                }
            }
        }
    }
}
