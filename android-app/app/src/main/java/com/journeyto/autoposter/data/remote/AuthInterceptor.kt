package com.journeyto.autoposter.data.remote

import android.util.Base64
import okhttp3.Interceptor
import okhttp3.Response

/** Attaches HTTP Basic auth using a WordPress Application Password (core feature since 5.6). */
class AuthInterceptor(
    private val username: String,
    private val applicationPassword: String,
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val credentials = "$username:$applicationPassword"
        val token = Base64.encodeToString(credentials.toByteArray(Charsets.UTF_8), Base64.NO_WRAP)
        val request = chain.request().newBuilder()
            .addHeader("Authorization", "Basic $token")
            .build()
        return chain.proceed(request)
    }
}
