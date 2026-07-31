package com.journeyto.autoposter.data.remote.dto

import com.google.gson.annotations.SerializedName

data class RenderedField(
    val rendered: String = "",
    val raw: String? = null,
)

/** Shape returned by GET wp/v2/posts and wp/v2/posts/{id}. */
data class WpPost(
    val id: Int = 0,
    val date: String? = null,
    val modified: String? = null,
    val slug: String? = null,
    val status: String = "draft",
    val link: String? = null,
    val title: RenderedField = RenderedField(),
    val content: RenderedField = RenderedField(),
    val excerpt: RenderedField = RenderedField(),
    @SerializedName("featured_media") val featuredMedia: Int = 0,
    val categories: List<Int> = emptyList(),
    val tags: List<Int> = emptyList(),
    @SerializedName("_embedded") val embedded: EmbeddedData? = null,
) {
    val featuredImageUrl: String?
        get() = embedded?.featuredMedia?.firstOrNull()?.sourceUrl
}

data class EmbeddedData(
    @SerializedName("wp:featuredmedia") val featuredMedia: List<MediaDto>? = null,
)
