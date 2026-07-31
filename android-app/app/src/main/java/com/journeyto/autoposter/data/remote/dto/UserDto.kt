package com.journeyto.autoposter.data.remote.dto

data class UserMeDto(
    val id: Int = 0,
    val name: String = "",
    val slug: String? = null,
    val roles: List<String> = emptyList(),
)
