=== Tag Landing Mapper ===
Contributors: custom-wordpress-tools
Tags: tag, landing page, woocommerce, elementor, campaign
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Assign pages to tag archive URLs, schedule campaigns, and restore earlier mappings.

== Installation ==
1. WordPress Admin > Plugins > Add New Plugin > Upload Plugin.
2. Upload tag-landing-mapper.zip and activate it.
3. Open Tools > لندینگ تگ‌ها.
4. Search for a post tag (or WooCommerce product tag), then a published page.
5. Save. The tag URL stays unchanged and shows the page's content.

== Features ==
- Post tags and WooCommerce product tags.
- Searchable tag/page selectors, avoiding long select menus.
- Page built with WordPress editor or Elementor.
- Enable, disable, edit, and remove each mapping.
- Optional start/end time for promotions, based on WordPress site timezone.
- Up to 300 history records showing before/after state and editor.
- Restore the state before any recorded change. Restores are themselves logged.
- No changes to tag slugs, original archive templates, or database content of pages.
- On plugin deactivation, normal archive templates work again.
- Best-effort cache invalidation for LiteSpeed Cache, WP Rocket, W3TC, and WP Super Cache.

== Important notes ==
- Mapping changes the visible tag archive body, not the main query. SEO titles, canonical behavior and tag indexing settings continue to be controlled by WordPress and the active SEO plugin.
- Existing landing page URL remains accessible. Handle duplicate content/canonicals in your SEO plugin.
- Page header/footer and global layout come from the active theme, not Elementor's Page Canvas template. Complex theme-specific archive layouts can need CSS adjustments.
- Scheduled expiry uses request-time checks, so archives return to normal automatically when a request reaches WordPress. Full-page caches, proxies and CDNs might continue to serve an old response until purged.
- Deactivating the plugin does NOT delete settings or history. Uninstalling it deletes its options.

== Changelog ==
= 1.0.0 =
* Initial release.
