package com.journeyto.autoposter.ui.editor

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddPhotoAlternate
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import com.journeyto.autoposter.content.VideoLink
import com.journeyto.autoposter.content.VideoLinkParser
import com.journeyto.autoposter.content.VideoPlatform
import com.journeyto.autoposter.core.ServiceLocator
import com.journeyto.autoposter.core.ViewModelFactory
import com.journeyto.autoposter.ui.components.ErrorBanner
import com.journeyto.autoposter.ui.components.FullScreenLoader
import com.journeyto.autoposter.ui.components.PrimaryButton
import com.journeyto.autoposter.ui.components.SectionLabel
import com.journeyto.autoposter.ui.components.StripeCard
import com.journeyto.autoposter.ui.components.StripeTextField
import com.journeyto.autoposter.ui.theme.BackgroundGray
import com.journeyto.autoposter.ui.theme.EarthGreen
import com.journeyto.autoposter.ui.theme.MutedText
import com.journeyto.autoposter.ui.theme.OceanBlue
import com.journeyto.autoposter.ui.theme.SlateText

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PostEditorScreen(
    locator: ServiceLocator,
    postId: Int?,
    onBack: () -> Unit,
    onSaved: () -> Unit,
) {
    val context = LocalContext.current
    val viewModel: PostEditorViewModel = viewModel(factory = ViewModelFactory { PostEditorViewModel(locator, postId) })

    val imagePicker = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(20)) { uris ->
        if (uris.isNotEmpty()) viewModel.addImages(context, uris)
    }
    val filePicker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri: Uri? ->
        uri?.let { viewModel.importArticleText(context, it) }
    }

    var shortUrlDraft by remember { mutableStateOf("") }
    var longformUrlDraft by remember { mutableStateOf("") }
    var newCategoryDraft by remember { mutableStateOf("") }
    var newTagDraft by remember { mutableStateOf("") }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (viewModel.mode == EditorMode.CREATE) "New Post" else "Edit Post") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        if (viewModel.isLoadingPost) {
            FullScreenLoader(modifier = Modifier.fillMaxSize().padding(padding).padding(top = 48.dp))
            return@Scaffold
        }

        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
        ) {
            viewModel.errorMessage?.let {
                ErrorBanner(message = it)
                Spacer(modifier = Modifier.height(16.dp))
            }

            StripeTextField(value = viewModel.title, onValueChange = { viewModel.title = it }, label = "Title")
            Spacer(modifier = Modifier.height(16.dp))

            if (viewModel.mode == EditorMode.CREATE) {
                SectionLabel("Article")
                StripeTextField(
                    value = viewModel.articleText,
                    onValueChange = { viewModel.articleText = it },
                    label = "Paste your article text",
                    placeholder = "Paste or type the full article here — separate paragraphs with a blank line.",
                    singleLine = false,
                    minLines = 8,
                )
                Spacer(modifier = Modifier.height(8.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Description, contentDescription = null, tint = OceanBlue, modifier = Modifier.size(18.dp))
                    Spacer(modifier = Modifier.width(6.dp))
                    Text(
                        "Import from a text file",
                        color = OceanBlue,
                        style = MaterialTheme.typography.labelLarge,
                        modifier = Modifier.clickableText { filePicker.launch(arrayOf("text/plain")) },
                    )
                }
            } else {
                SectionLabel("Content")
                StripeTextField(
                    value = viewModel.existingContent,
                    onValueChange = { viewModel.existingContent = it },
                    label = "Post content",
                    singleLine = false,
                    minLines = 10,
                    supportingText = "This is the post's existing WordPress content. Add more images or videos below — they'll be appended without disturbing this.",
                )
            }

            Spacer(modifier = Modifier.height(24.dp))

            SectionLabel("Images")
            StripeCard {
                Text(
                    "Images are placed automatically at even intervals through the article.",
                    style = MaterialTheme.typography.bodySmall,
                    color = SlateText,
                )
                Spacer(modifier = Modifier.height(12.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(viewModel.newImages, key = { it.mediaId }) { image ->
                        Box(modifier = Modifier.size(84.dp)) {
                            AsyncImage(
                                model = image.url,
                                contentDescription = null,
                                contentScale = ContentScale.Crop,
                                modifier = Modifier.size(84.dp).clip(RoundedCornerShape(12.dp)),
                            )
                            IconButton(
                                onClick = { viewModel.removeImage(image.mediaId) },
                                modifier = Modifier.size(22.dp).align(Alignment.TopEnd)
                                    .background(androidx.compose.ui.graphics.Color.Black.copy(alpha = 0.55f), CircleShape),
                            ) {
                                Icon(Icons.Filled.Close, contentDescription = "Remove", tint = androidx.compose.ui.graphics.Color.White, modifier = Modifier.size(14.dp))
                            }
                            IconButton(
                                onClick = { viewModel.setFeaturedImage(image.mediaId) },
                                modifier = Modifier.size(24.dp).align(Alignment.BottomStart),
                            ) {
                                Icon(
                                    if (viewModel.featuredImageId == image.mediaId) Icons.Filled.Star else Icons.Filled.StarBorder,
                                    contentDescription = "Set as featured image",
                                    tint = if (viewModel.featuredImageId == image.mediaId) EarthGreen else androidx.compose.ui.graphics.Color.White,
                                )
                            }
                        }
                    }
                    item {
                        Box(
                            modifier = Modifier
                                .size(84.dp)
                                .clip(RoundedCornerShape(12.dp))
                                .background(BackgroundGray)
                                .then(Modifier.clickableText {
                                    imagePicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                                }),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Filled.AddPhotoAlternate, contentDescription = "Add images", tint = OceanBlue)
                        }
                    }
                }
                if (viewModel.uploadingImageCount > 0) {
                    Spacer(modifier = Modifier.height(8.dp))
                    Text("Uploading ${viewModel.uploadingImageCount} image(s)…", style = MaterialTheme.typography.labelSmall, color = MutedText)
                }
            }

            Spacer(modifier = Modifier.height(24.dp))

            SectionLabel("Shorts (TikTok / YouTube Shorts)")
            VideoLinkSection(
                urlDraft = shortUrlDraft,
                onUrlDraftChange = { shortUrlDraft = it },
                links = viewModel.shorts,
                onAdd = {
                    val error = viewModel.addVideoLink(shortUrlDraft, isShortSection = true)
                    if (error == null) shortUrlDraft = ""
                },
                onRemove = viewModel::removeShort,
            )

            Spacer(modifier = Modifier.height(24.dp))

            SectionLabel("Long-form (YouTube)")
            VideoLinkSection(
                urlDraft = longformUrlDraft,
                onUrlDraftChange = { longformUrlDraft = it },
                links = viewModel.longform,
                onAdd = {
                    val error = viewModel.addVideoLink(longformUrlDraft, isShortSection = false)
                    if (error == null) longformUrlDraft = ""
                },
                onRemove = viewModel::removeLongform,
            )

            Spacer(modifier = Modifier.height(24.dp))

            SectionLabel("Categories")
            TaxonomyPicker(
                items = viewModel.availableCategories.map { it.id to it.name },
                selectedIds = viewModel.selectedCategoryIds,
                onToggle = viewModel::toggleCategory,
                newDraft = newCategoryDraft,
                onNewDraftChange = { newCategoryDraft = it },
                onAddNew = { viewModel.addNewCategory(newCategoryDraft); newCategoryDraft = "" },
            )

            Spacer(modifier = Modifier.height(20.dp))

            SectionLabel("Tags")
            TaxonomyPicker(
                items = viewModel.availableTags.map { it.id to it.name },
                selectedIds = viewModel.selectedTagIds,
                onToggle = viewModel::toggleTag,
                newDraft = newTagDraft,
                onNewDraftChange = { newTagDraft = it },
                onAddNew = { viewModel.addNewTag(newTagDraft); newTagDraft = "" },
            )

            Spacer(modifier = Modifier.height(24.dp))

            SectionLabel("Status")
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                SegmentedButton(
                    selected = viewModel.status == "draft",
                    onClick = { viewModel.status = "draft" },
                    shape = SegmentedButtonDefaults.itemShape(index = 0, count = 2),
                ) { Text("Save as Draft") }
                SegmentedButton(
                    selected = viewModel.status == "publish",
                    onClick = { viewModel.status = "publish" },
                    shape = SegmentedButtonDefaults.itemShape(index = 1, count = 2),
                ) { Text("Publish") }
            }

            Spacer(modifier = Modifier.height(28.dp))

            PrimaryButton(
                text = if (viewModel.status == "publish") "Publish" else "Save Draft",
                onClick = { viewModel.save { onSaved() } },
                loading = viewModel.isSaving,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(modifier = Modifier.height(32.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun VideoLinkSection(
    urlDraft: String,
    onUrlDraftChange: (String) -> Unit,
    links: List<VideoLink>,
    onAdd: () -> Unit,
    onRemove: (VideoLink) -> Unit,
) {
    StripeCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(modifier = Modifier.weight(1f)) {
                StripeTextField(value = urlDraft, onValueChange = onUrlDraftChange, label = "Paste a link", placeholder = "https://…")
            }
            Spacer(modifier = Modifier.width(8.dp))
            com.journeyto.autoposter.ui.components.SecondaryButton(text = "Add", onClick = onAdd, modifier = Modifier.height(56.dp))
        }
        if (links.isNotEmpty()) {
            Spacer(modifier = Modifier.height(12.dp))
            links.forEach { link ->
                Row(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.weight(1f)) {
                        val thumb = VideoLinkParser.youTubeThumbnail(link.url)
                        if (thumb != null) {
                            AsyncImage(
                                model = thumb,
                                contentDescription = null,
                                contentScale = ContentScale.Crop,
                                modifier = Modifier.size(40.dp).clip(RoundedCornerShape(8.dp)),
                            )
                        } else {
                            Icon(Icons.Filled.PlayCircle, contentDescription = null, tint = OceanBlue, modifier = Modifier.size(32.dp))
                        }
                        Spacer(modifier = Modifier.width(10.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(link.platform.label, style = MaterialTheme.typography.labelMedium)
                            Text(link.url, style = MaterialTheme.typography.bodySmall, color = MutedText, maxLines = 1)
                        }
                    }
                    IconButton(onClick = { onRemove(link) }) {
                        Icon(Icons.Filled.Close, contentDescription = "Remove", tint = MutedText)
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun TaxonomyPicker(
    items: List<Pair<Int, String>>,
    selectedIds: List<Int>,
    onToggle: (Int) -> Unit,
    newDraft: String,
    onNewDraftChange: (String) -> Unit,
    onAddNew: () -> Unit,
) {
    Column {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items.forEach { (id, name) ->
                FilterChip(selected = selectedIds.contains(id), onClick = { onToggle(id) }, label = { Text(name) })
            }
        }
        Spacer(modifier = Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(modifier = Modifier.weight(1f)) {
                StripeTextField(value = newDraft, onValueChange = onNewDraftChange, label = "Add new", placeholder = "Type a new name")
            }
            Spacer(modifier = Modifier.width(8.dp))
            com.journeyto.autoposter.ui.components.SecondaryButton(text = "Add", onClick = onAddNew, modifier = Modifier.height(56.dp))
        }
    }
}

private fun Modifier.clickableText(onClick: () -> Unit): Modifier =
    this.clickable(onClick = onClick)
