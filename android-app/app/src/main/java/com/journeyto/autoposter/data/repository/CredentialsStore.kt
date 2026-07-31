package com.journeyto.autoposter.data.repository

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Stores the WordPress site URL + Application Password credentials in an
 * EncryptedSharedPreferences file backed by the Android Keystore. Excluded
 * from backups (see backup_rules.xml / data_extraction_rules.xml).
 */
class CredentialsStore(context: Context) {

    private val prefs: SharedPreferences by lazy {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            PREFS_FILE_NAME,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    fun save(credentials: SiteCredentials) {
        prefs.edit()
            .putString(KEY_SITE_URL, credentials.siteUrl)
            .putString(KEY_USERNAME, credentials.username)
            .putString(KEY_APP_PASSWORD, credentials.applicationPassword)
            .apply()
    }

    fun load(): SiteCredentials? {
        val siteUrl = prefs.getString(KEY_SITE_URL, null) ?: return null
        val username = prefs.getString(KEY_USERNAME, null) ?: return null
        val appPassword = prefs.getString(KEY_APP_PASSWORD, null) ?: return null
        return SiteCredentials(siteUrl, username, appPassword)
    }

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val PREFS_FILE_NAME = "autoposter_secure_prefs"
        private const val KEY_SITE_URL = "site_url"
        private const val KEY_USERNAME = "username"
        private const val KEY_APP_PASSWORD = "app_password"
    }
}
