package com.journeyto.autoposter.content

enum class VideoPlatform(val slug: String, val label: String) {
    YOUTUBE("youtube", "YouTube"),
    TIKTOK("tiktok", "TikTok"),
    OTHER("embed", "Video"),
}

data class VideoLink(
    val url: String,
    val platform: VideoPlatform,
    /** true = short-form (TikTok / YouTube Shorts), false = long-form. */
    val isShort: Boolean,
)

object VideoLinkParser {

    private val youtubeRegex = Regex("(youtube\\.com|youtu\\.be)", RegexOption.IGNORE_CASE)
    private val tiktokRegex = Regex("tiktok\\.com", RegexOption.IGNORE_CASE)
    private val shortsRegex = Regex("/shorts/", RegexOption.IGNORE_CASE)
    private val youTubeIdRegex = Regex("(?:youtu\\.be/|shorts/|v=|/embed/)([A-Za-z0-9_-]{11})")

    fun detectPlatform(url: String): VideoPlatform = when {
        youtubeRegex.containsMatchIn(url) -> VideoPlatform.YOUTUBE
        tiktokRegex.containsMatchIn(url) -> VideoPlatform.TIKTOK
        else -> VideoPlatform.OTHER
    }

    /** A YouTube Shorts URL or a TikTok URL both count as "short-form" by default. */
    fun looksLikeShort(url: String): Boolean = when (detectPlatform(url)) {
        VideoPlatform.YOUTUBE -> shortsRegex.containsMatchIn(url)
        VideoPlatform.TIKTOK -> true
        VideoPlatform.OTHER -> false
    }

    fun isValidVideoUrl(url: String): Boolean {
        val trimmed = url.trim()
        return trimmed.startsWith("http://") || trimmed.startsWith("https://")
    }

    /** Thumbnail via YouTube's public image CDN — no API key required. Null for non-YouTube links. */
    fun youTubeThumbnail(url: String): String? {
        val id = youTubeIdRegex.find(url)?.groupValues?.get(1) ?: return null
        return "https://img.youtube.com/vi/$id/hqdefault.jpg"
    }
}
