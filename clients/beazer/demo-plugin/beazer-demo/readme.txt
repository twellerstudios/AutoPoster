=== BEAZER Demo ===
Contributors: twellerstudios
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

Serves the multi-page BEAZER website demo at /beazertheband/ on this site.

== Description ==

Install, activate, open https://your-site/beazertheband/.

* Nothing else on the site changes: no pages, theme, menus or settings are touched.
* Pages: Home, Merch, Tour, Fan Club, Media, About, Contact.
* Fan club sign-ups are stored under "BEAZER Demo" in the admin (with CSV export)
  and emailed, together with contact messages, through this site's own mailer.
* With WooCommerce active, the BEAZER T-shirts are created as products (TT$250,
  sizes S-2XL, category "BEAZER Merch"), hidden from the main shop and search,
  and sold from /beazertheband/merch/ through the store's normal cart.
* Search engines are asked not to index the demo.
* Deactivate the plugin to take the demo offline.

If a page cache or Cloudflare shows an old version after an update, purge the
cache for /beazertheband/*.

== Changelog ==

= 1.1.1 =
* Merch cards on phones: separate panels, numbered, full-width size picker and larger Add to cart.
* Updating no longer re-uploads unchanged T-shirt images.

= 1.1.0 =
* Official BEAZER social links; Apple Music marked coming soon.
* Form notifications go to Titan Music by default.
* WooCommerce merch: 10 T-shirts, TT$250, sizes S-2XL.
* Line-up updated.

= 1.0.0 =
* First demo release.
