package com.journeyto.autoposter.ui.editor

import android.content.Context
import android.net.Uri
import android.webkit.MimeTypeMap
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.text.HtmlCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.journeyto.autoposter.common.ApiResult
import com.journeyto.autoposter.content.BlockContentBuilder
import com.journeyto.autoposter.content.UploadedImage
import com.journeyto.autoposter.content.VideoLink
import com.journeyto.autoposter.content.VideoLinkParser
import com.journeyto.autoposter.content.VideoPlatform
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.data.remote.dto.TaxonomyDto
import kotlinx.coroutines.launch
import java.io.File

enum class EditorMode { CREATE, EDIT }

class PostEditorViewModel(
    private val locator: ServiceLocator,
    private val postId: Int?,
) : ViewModel() {

    val mode: EditorMode = if (postId == null) EditorMode.CREATE else EditorMode.EDIT

    var isLoadingPost by mutableStateOf(postId != null)
        private set
    var isSaving by mutableStateOf(false)
        private set
    var errorMessage by mutableStateOf<String?>(null)
        private set

    var title by mutableStateOf("")
    /** CREATE mode: the pasted/imported article text the composer will lay out. */
    var articleText by mutableStateOf("")
    /** EDIT mode: the post's existing raw content, editable directly. */
    var existingContent by mutableStateOf("")
    var status by mutableStateOf("draft")

    val newImages = mutableStateListOf<UploadedImage>()
    var uploadingImageCount by mutableStateOf(0)
        private set
    val shorts = mutableStateListOf<VideoLink>()
    val longform = mutableStateListOf<VideoLink>()
    var featuredImageId by mutableStateOf<Int?>(null)

    val availableCategories = mutableStateListOf<TaxonomyDto>()
    val availableTags = mutableStateListOf<TaxonomyDto>()
    val selectedCategoryIds = mutableStateListOf<Int>()
    val selectedTagIds = mutableStateListOf<Int>()

    init {
        loadTaxonomies()
        if (postId != null) loadExistingPost(postId)
    }

    private fun loadTaxonomies() {
        viewModelScope.launch {
            (locator.repository.getCategories() as? ApiResult.Success)?.let { availableCategories.addAll(it.data) }
            (locator.repository.getTags() as? ApiResult.Success)?.let { availableTags.addAll(it.data) }
        }
    }

    private fun loadExistingPost(id: Int) {
        viewModelScope.launch {
            isLoadingPost = true
            when (val result = locator.repository.getPost(id)) {
                is ApiResult.Success -> {
                    val post = result.data
                    title = HtmlCompat.fromHtml(
                        post.title.raw ?: post.title.rendered,
                        HtmlCompat.FROM_HTML_MODE_LEGACY,
                    ).toString()
                    existingContent = post.content.raw ?: post.content.rendered
                    status = post.status
                    featuredImageId = post.featuredMedia.takeIf { it > 0 }
                    selectedCategoryIds.addAll(post.categories)
                    selectedTagIds.addAll(post.tags)
                }
                is ApiResult.Error -> errorMessage = result.message
            }
            isLoadingPost = false
        }
    }

    fun importArticleText(context: Context, uri: Uri) {
        viewModelScope.launch {
            try {
                val text = withContentResolver(context, uri) { it.bufferedReader().readText() }
                articleText = text
            } catch (e: Exception) {
                errorMessage = "Couldn't read that file."
            }
        }
    }

    fun addImages(context: Context, uris: List<Uri>) {
        if (uris.isEmpty()) return
        viewModelScope.launch {
            uploadingImageCount += uris.size
            for (uri in uris) {
                try {
                    val mimeType = context.contentResolver.getType(uri) ?: "image/jpeg"
                    val extension = MimeTypeMap.getSingleton().getExtensionFromMimeType(mimeType) ?: "jpg"
                    val tempFile = File.createTempFile("upload_", ".$extension", context.cacheDir)
                    withContentResolver(context, uri) { input ->
                        tempFile.outputStream().use { output -> input.copyTo(output) }
                    }

                    when (val result = locator.repository.uploadMedia(tempFile, mimeType, title.ifBlank { null })) {
                        is ApiResult.Success -> {
                            val media = result.data
                            val uploaded = UploadedImage(
                                mediaId = media.id,
                                url = media.sourceUrl.orEmpty(),
                                altText = media.altText.orEmpty(),
                            )
                            newImages.add(uploaded)
                            if (featuredImageId == null) featuredImageId = uploaded.mediaId
                        }
                        is ApiResult.Error -> errorMessage = result.message
                    }
                    tempFile.delete()
                } catch (e: Exception) {
                    errorMessage = "Couldn't upload one of the selected images."
                } finally {
                    uploadingImageCount--
                }
            }
        }
    }

    private inline fun <T> withContentResolver(context: Context, uri: Uri, block: (java.io.InputStream) -> T): T {
        return context.contentResolver.openInputStream(uri)!!.use(block)
    }

    fun removeImage(mediaId: Int) {
        newImages.removeAll { it.mediaId == mediaId }
        if (featuredImageId == mediaId) featuredImageId = newImages.firstOrNull()?.mediaId
    }

    fun setFeaturedImage(mediaId: Int) {
        featuredImageId = mediaId
    }

    fun addVideoLink(url: String, isShortSection: Boolean): String? {
        if (!VideoLinkParser.isValidVideoUrl(url)) return "Please paste a full video link (starting with https://)."
        val platform = VideoLinkParser.detectPlatform(url)
        val link = VideoLink(url.trim(), platform, isShortSection)
        if (isShortSection) shorts.add(link) else longform.add(link)
        return null
    }

    fun removeShort(link: VideoLink) { shorts.remove(link) }
    fun removeLongform(link: VideoLink) { longform.remove(link) }

    fun toggleCategory(id: Int) {
        if (selectedCategoryIds.contains(id)) selectedCategoryIds.remove(id) else selectedCategoryIds.add(id)
    }

    fun toggleTag(id: Int) {
        if (selectedTagIds.contains(id)) selectedTagIds.remove(id) else selectedTagIds.add(id)
    }

    fun addNewCategory(name: String) {
        if (name.isBlank()) return
        viewModelScope.launch {
            when (val result = locator.repository.createCategory(name.trim())) {
                is ApiResult.Success -> {
                    availableCategories.add(result.data)
                    selectedCategoryIds.add(result.data.id)
                }
                is ApiResult.Error -> errorMessage = result.message
            }
        }
    }

    fun addNewTag(name: String) {
        if (name.isBlank()) return
        viewModelScope.launch {
            when (val result = locator.repository.createTag(name.trim())) {
                is ApiResult.Success -> {
                    availableTags.add(result.data)
                    selectedTagIds.add(result.data.id)
                }
                is ApiResult.Error -> errorMessage = result.message
            }
        }
    }

    fun save(onSuccess: (link: String?) -> Unit) {
        if (title.isBlank()) {
            errorMessage = "Give your post a title first."
            return
        }
        viewModelScope.launch {
            isSaving = true
            errorMessage = null
            val allVideos = shorts + longform

            val content = when (mode) {
                EditorMode.CREATE -> BlockContentBuilder.compose(articleText, newImages, allVideos)
                EditorMode.EDIT -> BlockContentBuilder.appendMedia(existingContent, newImages, allVideos)
            }

            val result = when (mode) {
                EditorMode.CREATE -> locator.repository.createPost(
                    title = title.trim(),
                    content = content,
                    status = status,
                    categoryIds = selectedCategoryIds.toList(),
                    tagIds = selectedTagIds.toList(),
                    featuredMediaId = featuredImageId,
                )
                EditorMode.EDIT -> locator.repository.updatePost(
                    id = postId!!,
                    title = title.trim(),
                    content = content,
                    status = status,
                    categoryIds = selectedCategoryIds.toList(),
                    tagIds = selectedTagIds.toList(),
                    featuredMediaId = featuredImageId,
                )
            }

            isSaving = false
            when (result) {
                is ApiResult.Success -> onSuccess(result.data.link)
                is ApiResult.Error -> errorMessage = result.message
            }
        }
    }
}
