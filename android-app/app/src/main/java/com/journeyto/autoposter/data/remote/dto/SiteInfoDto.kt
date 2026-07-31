package com.journeyto.autoposter.data.remote.dto

import com.google.gson.annotations.SerializedName

/** Returned by the companion plugin's custom route: GET autoposter/v1/site-info */
data class SiteInfoDto(
    @SerializedName("site_name") val siteName: String? = null,
    @SerializedName("site_icon_url") val siteIconUrl: String? = null,
    @SerializedName("social_links") val socialLinks: Map<String, String> = emptyMap(),
)
