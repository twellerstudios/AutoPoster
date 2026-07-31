package com.journeyto.autoposter.core

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider

/** Minimal factory so ViewModels can take constructor args without a DI framework. */
class ViewModelFactory<T : ViewModel>(private val creator: () -> T) : ViewModelProvider.Factory {
    @Suppress("UNCHECKED_CAST")
    override fun <VM : ViewModel> create(modelClass: Class<VM>): VM = creator() as VM
}
