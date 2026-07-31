package com.journeyto.autoposter.content

/** An image already uploaded to the WordPress media library, ready to be placed in a post. */
data class UploadedImage(
    val mediaId: Int,
    val url: String,
    val altText: String = "",
)
