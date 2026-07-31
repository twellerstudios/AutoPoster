package com.journeyto.autoposter.data.repository

import com.journeyto.autoposter.common.ApiResult
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.data.remote.dto.MediaDto
import com.journeyto.autoposter.data.remote.dto.SiteInfoDto
import com.journeyto.autoposter.data.remote.dto.TaxonomyDto
import com.journeyto.autoposter.data.remote.dto.UserMeDto
import com.journeyto.autoposter.data.remote.dto.WpPost
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import retrofit2.Response
import java.io.File

/**
 * Everything the app does to a WordPress site funnels through here, using
 * WordPress core's own REST API (wp/v2/*) plus the companion plugin's
 * autoposter/v1/site-info route. There is no separate backend or database —
 * WordPress itself is the source of truth for posts, media, categories and tags.
 */
class WordPressRepository(private val locator: ServiceLocator) {

    private suspend fun <T> call(block: suspend () -> Response<T>): ApiResult<T> = withContext(Dispatchers.IO) {
        try {
            val response = block()
            if (response.isSuccessful) {
                val body = response.body()
                if (body != null) ApiResult.Success(body) else ApiResult.Error("Empty response from WordPress.")
            } else {
                ApiResult.Error(describeError(response.code()))
            }
        } catch (e: java.net.UnknownHostException) {
            ApiResult.Error("Couldn't reach that site. Check the site URL and your connection.")
        } catch (e: java.net.SocketTimeoutException) {
            ApiResult.Error("The site took too long to respond. Please try again.")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Something went wrong talking to WordPress.")
        }
    }

    private fun describeError(code: Int): String = when (code) {
        401, 403 -> "Login failed. Check your username and Application Password."
        404 -> "Not found. Is the site URL correct and REST API enabled?"
        in 500..599 -> "The WordPress site had a server error. Please try again shortly."
        else -> "WordPress returned an error (HTTP $code)."
    }

    suspend fun verifyLogin(credentials: SiteCredentials): ApiResult<UserMeDto> =
        call { locator.apiFor(credentials).getCurrentUser() }

    suspend fun getPosts(search: String? = null, status: String, page: Int): ApiResult<List<WpPost>> =
        call { requireApi().getPosts(search = search, status = status, page = page) }

    suspend fun getPost(id: Int): ApiResult<WpPost> =
        call { requireApi().getPost(id) }

    suspend fun deletePost(id: Int): ApiResult<WpPost> =
        call { requireApi().deletePost(id) }

    suspend fun createPost(
        title: String,
        content: String,
        status: String,
        categoryIds: List<Int>,
        tagIds: List<Int>,
        featuredMediaId: Int?,
    ): ApiResult<WpPost> = call {
        requireApi().createPost(postBody(title, content, status, categoryIds, tagIds, featuredMediaId))
    }

    suspend fun updatePost(
        id: Int,
        title: String,
        content: String,
        status: String,
        categoryIds: List<Int>,
        tagIds: List<Int>,
        featuredMediaId: Int?,
    ): ApiResult<WpPost> = call {
        requireApi().updatePost(id, postBody(title, content, status, categoryIds, tagIds, featuredMediaId))
    }

    private fun postBody(
        title: String,
        content: String,
        status: String,
        categoryIds: List<Int>,
        tagIds: List<Int>,
        featuredMediaId: Int?,
    ): Map<String, Any?> = buildMap {
        put("title", title)
        put("content", content)
        put("status", status)
        if (categoryIds.isNotEmpty()) put("categories", categoryIds)
        if (tagIds.isNotEmpty()) put("tags", tagIds)
        if (featuredMediaId != null && featuredMediaId > 0) put("featured_media", featuredMediaId)
    }

    /** Uploads a local file to the WordPress media library and returns the created attachment. */
    suspend fun uploadMedia(file: File, mimeType: String, altText: String?): ApiResult<MediaDto> = call {
        val requestBody = file.asRequestBody(mimeType.toMediaTypeOrNull())
        val part = MultipartBody.Part.createFormData("file", file.name, requestBody)
        val altBody = altText?.toRequestBody("text/plain".toMediaTypeOrNull())
        requireApi().uploadMedia(part, altBody)
    }

    suspend fun getCategories(search: String? = null): ApiResult<List<TaxonomyDto>> =
        call { requireApi().getCategories(search = search) }

    suspend fun createCategory(name: String): ApiResult<TaxonomyDto> =
        call { requireApi().createCategory(mapOf("name" to name)) }

    suspend fun getTags(search: String? = null): ApiResult<List<TaxonomyDto>> =
        call { requireApi().getTags(search = search) }

    suspend fun createTag(name: String): ApiResult<TaxonomyDto> =
        call { requireApi().createTag(mapOf("name" to name)) }

    /** Returns null (not an error) if the companion plugin isn't installed — the endpoint 404s. */
    suspend fun getSiteInfo(): SiteInfoDto? = withContext(Dispatchers.IO) {
        try {
            val response = requireApi().getSiteInfo()
            if (response.isSuccessful) response.body() else null
        } catch (e: Exception) {
            null
        }
    }

    private fun requireApi() = locator.currentApi()
        ?: throw IllegalStateException("Not signed in to a WordPress site.")
}
