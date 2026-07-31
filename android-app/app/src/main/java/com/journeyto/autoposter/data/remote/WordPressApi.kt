package com.journeyto.autoposter.data.remote

import com.journeyto.autoposter.data.remote.dto.MediaDto
import com.journeyto.autoposter.data.remote.dto.SiteInfoDto
import com.journeyto.autoposter.data.remote.dto.TaxonomyDto
import com.journeyto.autoposter.data.remote.dto.UserMeDto
import com.journeyto.autoposter.data.remote.dto.WpPost
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Talks straight to WordPress core's own REST API (wp/v2/*) using Application Password
 * basic auth, plus the small `autoposter/v1/*` surface the companion plugin adds.
 * No custom backend involved — this *is* the client.
 */
interface WordPressApi {

    @GET("wp/v2/users/me")
    suspend fun getCurrentUser(): Response<UserMeDto>

    @GET("wp/v2/posts")
    suspend fun getPosts(
        @Query("search") search: String? = null,
        @Query("status") status: String = "publish,draft,pending,future",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("_embed") embed: Boolean = true,
        @Query("orderby") orderBy: String = "date",
        @Query("order") order: String = "desc",
    ): Response<List<WpPost>>

    @GET("wp/v2/posts/{id}")
    suspend fun getPost(
        @Path("id") id: Int,
        @Query("context") context: String = "edit",
        @Query("_embed") embed: Boolean = true,
    ): Response<WpPost>

    @POST("wp/v2/posts")
    suspend fun createPost(@Body body: Map<String, @JvmSuppressWildcards Any?>): Response<WpPost>

    @POST("wp/v2/posts/{id}")
    suspend fun updatePost(
        @Path("id") id: Int,
        @Body body: Map<String, @JvmSuppressWildcards Any?>,
    ): Response<WpPost>

    @DELETE("wp/v2/posts/{id}")
    suspend fun deletePost(
        @Path("id") id: Int,
        @Query("force") force: Boolean = true,
    ): Response<WpPost>

    @Multipart
    @POST("wp/v2/media")
    suspend fun uploadMedia(
        @Part file: MultipartBody.Part,
        @Part("alt_text") altText: okhttp3.RequestBody? = null,
    ): Response<MediaDto>

    @GET("wp/v2/categories")
    suspend fun getCategories(
        @Query("per_page") perPage: Int = 100,
        @Query("search") search: String? = null,
    ): Response<List<TaxonomyDto>>

    @POST("wp/v2/categories")
    suspend fun createCategory(@Body body: Map<String, String>): Response<TaxonomyDto>

    @GET("wp/v2/tags")
    suspend fun getTags(
        @Query("per_page") perPage: Int = 100,
        @Query("search") search: String? = null,
    ): Response<List<TaxonomyDto>>

    @POST("wp/v2/tags")
    suspend fun createTag(@Body body: Map<String, String>): Response<TaxonomyDto>

    /** Provided by the AutoPoster Companion plugin; 404s gracefully if it isn't installed. */
    @GET("autoposter/v1/site-info")
    suspend fun getSiteInfo(): Response<SiteInfoDto>
}
