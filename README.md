# Tag Landing Mapper

افزونه وردپرس برای نمایش محتوای یک برگه در آدرس آرشیو برچسب نوشته‌ها یا محصولات ووکامرس، همراه با زمان‌بندی کمپین، تاریخچه تغییرات و بازگردانی تنظیمات.

Assign a published WordPress page to a post tag or WooCommerce product tag archive while preserving the tag URL. Supports WordPress editor content and Elementor pages.

## Download and install

1. Download [`tag-landing-mapper-v1.0.0.zip`](https://github.com/arez-s/tag-landing-mapper/releases/download/v1.0.0/tag-landing-mapper-v1.0.0.zip).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP, and activate it.
3. Open **Tools → لندینگ تگ‌ها**, select a tag and a published page, then save the mapping.

Requires WordPress **6.0+** and PHP **7.4+**. WooCommerce is needed only for product tag mappings.

## Features

- Searchable selectors for tags and published pages.
- Enable, disable, edit, or remove mappings.
- Optional campaign start and end times in the WordPress site timezone.
- Up to 300 history records, with restoration of earlier mapping states.
- Normal tag archives return when mappings are disabled, expire, or the plugin is deactivated.
- Best-effort cache invalidation for LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache.
- Persian administration interface.

## Behavior and limitations

Mappings replace the visible archive content while preserving the original archive query and URL. SEO titles, canonical URLs, and indexing remain controlled by WordPress and the active SEO plugin. The original page URL remains accessible; configure duplicate-content handling in your SEO plugin as needed.

Headers, footers, and layout come from the active theme. Elementor Page Canvas layouts are not reproduced. Full-page caches and CDNs may need purging at campaign boundaries.

Deactivation retains mappings and history. Uninstallation deletes the plugin's stored options.

## Release contents

The v1.0.0 release asset is the original supplied ZIP. Plugin source files are extracted from that archive; this README provides GitHub installation guidance. See [readme.txt](readme.txt) for the bundled documentation and changelog.

License: **GPLv2 or later**, as declared in the bundled readme.
