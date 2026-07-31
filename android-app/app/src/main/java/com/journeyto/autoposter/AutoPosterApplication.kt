package com.journeyto.autoposter

import android.app.Application
import com.journeyto.autoposter.core.ServiceLocator

class AutoPosterApplication : Application() {
    lateinit var locator: ServiceLocator
        private set

    override fun onCreate() {
        super.onCreate()
        locator = ServiceLocator(this)
    }
}
