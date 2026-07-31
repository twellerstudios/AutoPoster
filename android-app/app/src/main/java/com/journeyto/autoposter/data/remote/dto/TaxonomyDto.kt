package com.journeyto.autoposter.data.remote.dto

/** Shared shape for both wp/v2/categories and wp/v2/tags entries. */
data class TaxonomyDto(
    val id: Int = 0,
    val name: String = "",
    val slug: String? = null,
    val count: Int = 0,
)
