package com.journeyto.autoposter.data.repository

data class SiteCredentials(
    val siteUrl: String,
    val username: String,
    val applicationPassword: String,
) {
    /** Normalized base URL, always without a trailing slash, e.g. https://letsjourneyto.com */
    val normalizedSiteUrl: String
        get() = siteUrl.trim().trimEnd('/')

    val restBaseUrl: String
        get() = "$normalizedSiteUrl/wp-json/"
}
