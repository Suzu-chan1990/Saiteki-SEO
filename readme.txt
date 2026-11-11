=== Saiteki SEO ===
Contributors: Saguya
Tags: seo, video seo, xml sitemap, schema, indexnow
Requires at least: 6.9
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast and lightweight SEO plugin for video-focused WordPress sites with dynamic schema, XML sitemaps on a custom path, and built-in instant indexing support.

== Description ==

Saiteki SEO (最適) is a performance-focused SEO engine built for video-heavy WordPress environments.

Instead of storing large amounts of redundant metadata, Saiteki generates SEO output dynamically from existing post data. Structured data, Twitter Player Cards, and XML sitemaps are created on demand to reduce database overhead while staying compatible with modern search engines.

= Core Features =

* High-performance XML sitemap with video and image extensions
* Sitemap on a custom path: directory, index file name and file prefix are freely configurable (virtual files, nothing is written to disk)
* Automatic 301 redirect from the previous sitemap location after a path change
* Dynamic JSON-LD schema (VideoObject, BreadcrumbList, ItemList)
* Meta description, Open Graph and Twitter Player Cards
* Instant indexing via IndexNow and the Google Indexing API
* Multi-key rotation for the Google Indexing API, with per-key test
* Optional SEO Health Audit
* Bridge to Hydro Shortlinks
* Dashboard with SEO status, recommendations and instant-saving switches

= Performance =

* Settings are read from one small autoloaded option: no extra query per request
* Google keys are stored in a separate row that is only read while publishing
* Sitemap requests are answered before the WordPress main query runs
* Sitemap files support conditional requests (ETag / 304)
* Posts, meta data and featured images of a sitemap file are loaded in batches

= Security =

API credentials are stored encrypted (AES-256-GCM), bound to the salts in wp-config.php.

== Installation ==

1. Upload the `saiteki` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open "Saiteki SEO" in the WordPress dashboard.
4. Set the sitemap path under XML Sitemap > Path & Files and enable the modules you want to use.

== Frequently Asked Questions ==

= Where is the sitemap? =

New installations: `/sitemap_index.xml`. Installations updated from 1.x keep their previous URL. Both can be changed under XML Sitemap > Path & Files.

= Do I have to create the sitemap directory? =

No. The path is virtual and answered by WordPress. The web server only has to pass requests for files that do not exist on to WordPress (the normal permalink setup).

= What happens to the old sitemap URL when I change the path? =

It is redirected permanently (301) to the new location. This can be switched off.

= Does Saiteki work alongside other SEO plugins? =

Running multiple SEO plugins creates duplicate meta tags. The dashboard warns when another SEO plugin is active.

= Where does the video ID come from? =

From the theme helper `jpop_get_turbo_video()` if it exists, otherwise from the first YouTube link in the post content. Other sources can use the `saiteki_video_data` filter.

= Why are tag archives set to noindex? =

This helps search engines prioritize primary content instead of thin archive pages. It can be switched off under Frontend SEO > Meta & Social.

== Changelog ==

= 2.0.0 =
* New: sitemap path settings (directory, index file name, file prefix) with live preview, reachability test and 301 redirect from the previous location. Existing installs keep their URL.
* New: admin screen rebuilt (dashboard with SEO status and recommendations, section tiles, tabs, instant-saving switches).
* New: Google key list with per-key test and removal, add-or-replace mode; rotation unchanged.
* New: settings for posts per file, sitemap contents, title suffix, tag noindex, IndexNow key renewal and test.
* New: video ID fallback from the post content and the `saiteki_video_data` filter.
* Fix: Google keys were loaded on every page view (autoloaded); they now live in their own row.
* Fix: several queries per post while a sitemap file was built; posts are now loaded in batches.
* Fix: protected pages leaked content into the meta description; protected posts are left out of the sitemap and of instant indexing.
* Fix: wrong canonical on a separate posts page; missing Twitter title/description on non-video pages.
* Fix: stored keys could not be removed; the key counter showed 1 without keys.
* Fix: IndexNow stayed dead after the wp-config.php salts changed; a new key is generated.
* Fix: IndexNow key file on sites installed in a sub-directory.
* Fix: IndexNow got one request per post during bulk imports (HTTP 429); posts are sent in batches now.
* Fix: admin page used the Hydro stylesheet and untranslated labels; German translation is complete.
* Change: secrets are encrypted with AES-256-GCM (older values stay readable and are converted).
* Change: sitemap and key file are answered before the main query; sitemap files send ETag and answer with 304.

= 1.2.1 =
* Fixes for Google key storage, sitemap output, canonicals and the German translation. See CHANGELOG.md.

= 1.2.0 =
* Dedicated settings table with caching and passive migration from wp_options.

= 1.1.0 =
* Added i18n support and the SEO Health Audit module.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
The sitemap URL of existing sites stays the same. Google keys are moved automatically; after this update a downgrade to 1.x requires pasting the keys again.
