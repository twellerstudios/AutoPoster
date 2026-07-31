package com.journeyto.autoposter.core

import android.content.Context
import com.google.gson.Gson
import com.journeyto.autoposter.data.remote.AuthInterceptor
import com.journeyto.autoposter.data.remote.WordPressApi
import com.journeyto.autoposter.data.repository.CredentialsStore
import com.journeyto.autoposter.data.repository.SiteCredentials
import com.journeyto.autoposter.data.repository.WordPressRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Small hand-rolled service locator (no DI framework) that rebuilds the Retrofit
 * client whenever the signed-in site changes. Kept intentionally simple: this
 * app only ever talks to one WordPress site at a time.
 */
class ServiceLocator(context: Context) {

    val credentialsStore = CredentialsStore(context.applicationContext)

    private val _session = MutableStateFlow(credentialsStore.load())
    val session: StateFlow<SiteCredentials?> = _session.asStateFlow()

    private var cachedApi: WordPressApi? = null
    private var cachedFor: SiteCredentials? = null

    val repository: WordPressRepository by lazy { WordPressRepository(this) }

    fun signIn(credentials: SiteCredentials) {
        credentialsStore.save(credentials)
        _session.value = credentials
    }

    fun signOut() {
        credentialsStore.clear()
        _session.value = null
        cachedApi = null
        cachedFor = null
    }

    /** Builds (or reuses) a [WordPressApi] pointed at the given credentials. */
    fun apiFor(credentials: SiteCredentials): WordPressApi {
        if (cachedApi != null && cachedFor == credentials) return cachedApi!!

        val logging = HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC }
        val client = OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(credentials.username, credentials.applicationPassword))
            .addInterceptor(logging)
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .writeTimeout(60, TimeUnit.SECONDS)
            .build()

        val retrofit = Retrofit.Builder()
            .baseUrl(credentials.restBaseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(Gson()))
            .build()

        return retrofit.create(WordPressApi::class.java).also {
            cachedApi = it
            cachedFor = credentials
        }
    }

    /** The current session's API, or null if nobody is signed in. */
    fun currentApi(): WordPressApi? = _session.value?.let { apiFor(it) }
}
