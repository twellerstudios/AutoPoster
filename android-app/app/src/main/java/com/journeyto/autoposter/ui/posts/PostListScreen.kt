package com.journeyto.autoposter.ui.posts

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Image
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.text.HtmlCompat
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.core.ViewModelFactory
import com.journeyto.autoposter.data.remote.dto.WpPost
import com.journeyto.autoposter.ui.components.ChipTone
import com.journeyto.autoposter.ui.components.EmptyState
import com.journeyto.autoposter.ui.components.ErrorBanner
import com.journeyto.autoposter.ui.components.FullScreenLoader
import com.journeyto.autoposter.ui.components.PrimaryButton
import com.journeyto.autoposter.ui.components.StatusChip
import com.journeyto.autoposter.ui.theme.BackgroundGray
import com.journeyto.autoposter.ui.theme.BorderGray
import com.journeyto.autoposter.ui.theme.MutedText
import com.journeyto.autoposter.ui.theme.OceanBlue
import com.journeyto.autoposter.ui.theme.SlateText

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PostListScreen(
    locator: ServiceLocator,
    onNewPost: () -> Unit,
    onEditPost: (Int) -> Unit,
) {
    val viewModel: PostListViewModel = viewModel(factory = ViewModelFactory { PostListViewModel(locator) })
    var postPendingDelete by remember { mutableStateOf<WpPost?>(null) }
    var deleteError by remember { mutableStateOf<String?>(null) }

    Scaffold(
        floatingActionButton = {
            FloatingActionButton(onClick = onNewPost, containerColor = OceanBlue) {
                Icon(Icons.Filled.Add, contentDescription = "New post", tint = androidx.compose.ui.graphics.Color.White)
            }
        },
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            Spacer(modifier = Modifier.height(16.dp))
            Text("Posts", style = MaterialTheme.typography.headlineMedium)
            Spacer(modifier = Modifier.height(16.dp))

            com.journeyto.autoposter.ui.components.StripeTextField(
                value = viewModel.searchQuery,
                onValueChange = viewModel::onSearchChanged,
                label = "Search",
                placeholder = "Search posts…",
                trailingIcon = { Icon(Icons.Filled.Search, contentDescription = null, tint = MutedText) },
            )
            Spacer(modifier = Modifier.height(12.dp))

            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(StatusFilter.values().toList()) { filter ->
                    FilterChip(
                        selected = viewModel.statusFilter == filter,
                        onClick = { viewModel.onStatusChanged(filter) },
                        label = { Text(filter.label) },
                    )
                }
            }
            Spacer(modifier = Modifier.height(12.dp))

            viewModel.errorMessage?.let {
                ErrorBanner(message = it, onRetry = viewModel::refresh)
                Spacer(modifier = Modifier.height(12.dp))
            }

            when {
                viewModel.isLoading -> FullScreenLoader(modifier = Modifier.padding(top = 48.dp))
                viewModel.posts.isEmpty() -> EmptyState(
                    title = "No posts yet",
                    subtitle = "Tap the + button to write and publish your first post.",
                    action = { PrimaryButton(text = "New Post", onClick = onNewPost) },
                )
                else -> LazyColumn(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(viewModel.posts, key = { it.id }) { post ->
                        PostRow(
                            post = post,
                            onClick = { onEditPost(post.id) },
                            onDelete = { postPendingDelete = post },
                        )
                    }
                    if (viewModel.canLoadMore) {
                        item {
                            TextButton(onClick = viewModel::loadMore, modifier = Modifier.fillMaxWidth()) {
                                Text(if (viewModel.isLoadingMore) "Loading…" else "Load more")
                            }
                        }
                    }
                    item { Spacer(modifier = Modifier.height(72.dp)) }
                }
            }
        }
    }

    postPendingDelete?.let { post ->
        AlertDialog(
            onDismissRequest = { postPendingDelete = null },
            title = { Text("Delete this post?") },
            text = { Text(HtmlCompat.fromHtml(post.title.rendered, HtmlCompat.FROM_HTML_MODE_LEGACY).toString().ifBlank { "(untitled)" }) },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.deletePost(post.id) { error -> deleteError = error }
                    postPendingDelete = null
                }) { Text("Delete", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = {
                TextButton(onClick = { postPendingDelete = null }) { Text("Cancel") }
            },
        )
    }

    deleteError?.let {
        AlertDialog(
            onDismissRequest = { deleteError = null },
            title = { Text("Couldn't delete post") },
            text = { Text(it) },
            confirmButton = { TextButton(onClick = { deleteError = null }) { Text("OK") } },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PostRow(post: WpPost, onClick: () -> Unit, onDelete: () -> Unit) {
    var menuExpanded by remember { mutableStateOf(false) }
    val title = remember(post.title.rendered) {
        HtmlCompat.fromHtml(post.title.rendered, HtmlCompat.FROM_HTML_MODE_LEGACY).toString().ifBlank { "(untitled)" }
    }
    val (tone, label) = when (post.status) {
        "publish" -> ChipTone.GREEN to "Published"
        "future" -> ChipTone.BLUE to "Scheduled"
        "pending" -> ChipTone.AMBER to "Pending"
        else -> ChipTone.NEUTRAL to "Draft"
    }

    Card(
        onClick = onClick,
        shape = RoundedCornerShape(16.dp),
        colors = androidx.compose.material3.CardDefaults.cardColors(containerColor = androidx.compose.ui.graphics.Color.White),
        border = androidx.compose.foundation.BorderStroke(1.dp, BorderGray),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(modifier = Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                modifier = Modifier
                    .size(56.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(BackgroundGray),
                contentAlignment = Alignment.Center,
            ) {
                if (post.featuredImageUrl != null) {
                    AsyncImage(
                        model = post.featuredImageUrl,
                        contentDescription = null,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.size(56.dp).clip(RoundedCornerShape(10.dp)),
                    )
                } else {
                    Icon(Icons.Filled.Image, contentDescription = null, tint = MutedText)
                }
            }
            Spacer(modifier = Modifier.size(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.titleMedium, maxLines = 2)
                Spacer(modifier = Modifier.height(6.dp))
                StatusChip(text = label, tone = tone)
            }
            Box {
                IconButton(onClick = { menuExpanded = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = "More", tint = SlateText)
                }
                DropdownMenu(expanded = menuExpanded, onDismissRequest = { menuExpanded = false }) {
                    DropdownMenuItem(
                        text = { Text("Edit") },
                        leadingIcon = { Icon(Icons.Filled.Edit, contentDescription = null) },
                        onClick = { menuExpanded = false; onClick() },
                    )
                    DropdownMenuItem(
                        text = { Text("Delete") },
                        leadingIcon = { Icon(Icons.Filled.Delete, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                        onClick = { menuExpanded = false; onDelete() },
                    )
                }
            }
        }
    }
}
