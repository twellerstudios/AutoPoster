=== AutoPoster Companion ===
Contributors: journeyto
Tags: rest-api, mobile app, social links
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion plugin for the Journey To Poster Android app.

== Description ==

The Journey To Poster Android app manages posts, media, categories and
tags entirely through WordPress core's own REST API (`wp/v2/*`), using an
Application Password for authentication — no custom backend, no separate
database. This plugin adds the one small thing core doesn't provide:

* A **Settings → AutoPoster Companion** page to enter your social media
  profile URLs (Facebook, Instagram, TikTok, YouTube, X, Pinterest,
  LinkedIn, website).
* A read-only REST route, `GET /wp-json/autoposter/v1/site-info`, that the
  app calls to display those links as tappable icons.
* A "Follow us" icon row automatically appended to every published post,
  and light CSS to keep the TikTok/YouTube embeds the app inserts
  responsive on any theme.

Videos, images, and article text sent from the app become ordinary
Gutenberg blocks (`wp:paragraph`, `wp:image`, `wp:embed`) — the exact same
markup the block editor itself produces — so every post stays fully
editable from wp-admin afterwards, with nothing proprietary in the
database.

== Installation ==

1. Upload the `autoposter-companion` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings → AutoPoster Companion and add your social links.
4. In the Journey To Poster app, sign in with your site URL, WordPress
   username, and an Application Password (WP Admin → Users → Your Profile
   → Application Passwords).

== Frequently Asked Questions ==

= Does this plugin store posts or images itself? =

No. All content lives in WordPress's normal posts and media tables,
exactly as if you'd written it in wp-admin. This plugin only stores your
social link settings.

= Do I need HTTPS? =

Yes — WordPress Application Passwords, which the app uses to authenticate,
require an HTTPS connection (or a recognized local development URL).

== Changelog ==

= 1.0.0 =
* Initial release: social links settings, site-info REST route, frontend
  follow-us row, responsive embed styling.
