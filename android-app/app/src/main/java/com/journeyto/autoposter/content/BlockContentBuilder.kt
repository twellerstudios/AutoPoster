package com.journeyto.autoposter.content

/**
 * Turns a pasted/imported article plus attached images and video links into
 * WordPress block-editor (Gutenberg) markup — the same markup WP itself
 * generates — so the resulting post stays fully editable as native blocks
 * in wp-admin afterwards. Nothing here is a proprietary format.
 *
 * Image placement is dynamic per article: images are spread evenly across
 * the paragraph breaks rather than all dumped at the top, so a 6-paragraph
 * article with 2 images gets one after paragraph ~2 and one after paragraph ~4,
 * while a 20-paragraph article with 2 images spaces them much further apart.
 */
object BlockContentBuilder {

    /** Splits raw pasted/imported text into paragraphs on blank lines. */
    fun splitParagraphs(articleText: String): List<String> =
        articleText
            .split(Regex("\\n\\s*\\n"))
            .map { it.trim().replace(Regex("\\s*\\n\\s*"), " ") }
            .filter { it.isNotEmpty() }

    /**
     * Computes, for each image index, which paragraph it should be inserted after.
     * Images are distributed evenly across the article body, never before the
     * first paragraph, so the opening paragraph always reads cleanly first.
     */
    fun computeInsertionSlots(paragraphCount: Int, imageCount: Int): List<Int> {
        if (imageCount <= 0 || paragraphCount <= 0) return emptyList()
        if (paragraphCount == 1) return List(imageCount) { 0 }

        val interval = paragraphCount.toDouble() / (imageCount + 1)
        return (1..imageCount).map { i ->
            (interval * i).toInt().coerceIn(0, paragraphCount - 1)
        }
    }

    /**
     * Builds the full post_content string: paragraph blocks with images dynamically
     * woven in, followed by a "Watch" section embedding any video links using
     * WordPress's own oEmbed block (core embeds YouTube and TikTok out of the box).
     */
    fun compose(
        articleText: String,
        images: List<UploadedImage>,
        videos: List<VideoLink>,
    ): String {
        val paragraphs = splitParagraphs(articleText)
        if (paragraphs.isEmpty() && images.isEmpty() && videos.isEmpty()) return ""

        val blocks = mutableListOf<String>()
        val slots = computeInsertionSlots(paragraphs.size, images.size)
        val imagesBySlot = images.indices.groupBy({ slots[it] }, { images[it] })

        paragraphs.forEachIndexed { index, paragraph ->
            blocks += paragraphBlock(paragraph)
            imagesBySlot[index]?.forEach { blocks += imageBlock(it) }
        }

        // Paragraph-less articles (e.g. just images/videos added to an existing post)
        // still get their images appended in order.
        if (paragraphs.isEmpty()) {
            images.forEach { blocks += imageBlock(it) }
        }

        if (videos.isNotEmpty()) {
            blocks += headingBlock("Watch")
            videos.forEach { blocks += embedBlock(it) }
        }

        return blocks.joinToString("\n\n")
    }

    private fun escapeHtml(text: String): String = text
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")

    private fun paragraphBlock(text: String): String =
        "<!-- wp:paragraph -->\n<p>${escapeHtml(text)}</p>\n<!-- /wp:paragraph -->"

    private fun headingBlock(text: String): String =
        "<!-- wp:heading -->\n<h2>${escapeHtml(text)}</h2>\n<!-- /wp:heading -->"

    private fun imageBlock(image: UploadedImage): String {
        val alt = escapeHtml(image.altText)
        return "<!-- wp:image {\"id\":${image.mediaId},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n" +
            "<figure class=\"wp-block-image size-large\"><img src=\"${image.url}\" alt=\"$alt\" class=\"wp-image-${image.mediaId}\"/></figure>\n" +
            "<!-- /wp:image -->"
    }

    private fun embedBlock(video: VideoLink): String {
        val slug = video.platform.slug
        return "<!-- wp:embed {\"url\":\"${video.url}\",\"type\":\"video\",\"providerNameSlug\":\"$slug\",\"responsive\":true} -->\n" +
            "<figure class=\"wp-block-embed is-type-video is-provider-$slug wp-block-embed-$slug wp-embed-aspect-16-9 wp-has-aspect-ratio\">" +
            "<div class=\"wp-block-embed__wrapper\">\n${video.url}\n</div></figure>\n" +
            "<!-- /wp:embed -->"
    }

    /**
     * Appends new images/videos to an existing post's raw content, for the
     * "add more media" flow when editing a post that already has structure
     * WordPress itself (or the user) has arranged. Existing content is never rewritten.
     */
    fun appendMedia(existingContent: String, images: List<UploadedImage>, videos: List<VideoLink>): String {
        val additions = mutableListOf<String>()
        images.forEach { additions += imageBlock(it) }
        if (videos.isNotEmpty()) {
            additions += headingBlock("Watch")
            videos.forEach { additions += embedBlock(it) }
        }
        if (additions.isEmpty()) return existingContent
        return if (existingContent.isBlank()) additions.joinToString("\n\n")
        else existingContent.trimEnd() + "\n\n" + additions.joinToString("\n\n")
    }
}
