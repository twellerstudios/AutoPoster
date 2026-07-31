package com.journeyto.autoposter.ui.posts

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.journeyto.autoposter.common.ApiResult
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.data.remote.dto.WpPost
import kotlinx.coroutines.launch

enum class StatusFilter(val label: String, val apiValue: String) {
    ALL("All", "publish,draft,pending,future"),
    PUBLISHED("Published", "publish"),
    DRAFTS("Drafts", "draft"),
    SCHEDULED("Scheduled", "future"),
}

class PostListViewModel(private val locator: ServiceLocator) : ViewModel() {

    var posts by mutableStateOf<List<WpPost>>(emptyList())
        private set
    var isLoading by mutableStateOf(false)
        private set
    var isLoadingMore by mutableStateOf(false)
        private set
    var errorMessage by mutableStateOf<String?>(null)
        private set
    var canLoadMore by mutableStateOf(false)
        private set

    var searchQuery by mutableStateOf("")
        private set
    var statusFilter by mutableStateOf(StatusFilter.ALL)
        private set

    private var currentPage = 1

    init {
        refresh()
    }

    fun onSearchChanged(query: String) {
        searchQuery = query
        refresh()
    }

    fun onStatusChanged(filter: StatusFilter) {
        statusFilter = filter
        refresh()
    }

    fun refresh() {
        currentPage = 1
        loadPage(replace = true)
    }

    fun loadMore() {
        if (!canLoadMore || isLoading || isLoadingMore) return
        currentPage++
        loadPage(replace = false)
    }

    private fun loadPage(replace: Boolean) {
        viewModelScope.launch {
            if (replace) isLoading = true else isLoadingMore = true
            errorMessage = null

            when (val result = locator.repository.getPosts(
                search = searchQuery.ifBlank { null },
                status = statusFilter.apiValue,
                page = currentPage,
            )) {
                is ApiResult.Success -> {
                    posts = if (replace) result.data else posts + result.data
                    canLoadMore = result.data.isNotEmpty()
                }
                is ApiResult.Error -> {
                    if (replace) posts = emptyList()
                    canLoadMore = false
                    errorMessage = result.message
                }
            }
            isLoading = false
            isLoadingMore = false
        }
    }

    fun deletePost(id: Int, onDone: (String?) -> Unit) {
        viewModelScope.launch {
            when (val result = locator.repository.deletePost(id)) {
                is ApiResult.Success -> {
                    posts = posts.filterNot { it.id == id }
                    onDone(null)
                }
                is ApiResult.Error -> onDone(result.message)
            }
        }
    }
}
