package com.journeyto.autoposter.data.remote.dto

import com.google.gson.annotations.SerializedName

data class MediaDto(
    val id: Int = 0,
    @SerializedName("source_url") val sourceUrl: String? = null,
    @SerializedName("alt_text") val altText: String? = null,
)
