=== Saiteki SEO ===
Contributors: suzuchan
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast and lightweight SEO plugin for video-focused WordPress sites with dynamic schema, XML sitemaps, and built-in instant indexing support.

== Description ==

Saiteki SEO (最適) is a performance-focused SEO engine built for video-heavy WordPress environments.

Instead of storing large amounts of redundant metadata, Saiteki generates SEO output dynamically based on existing post data. Structured data, Twitter Player Cards, and XML sitemaps are created on demand to reduce database overhead while maintaining compatibility with modern search engines.

The plugin includes optional instant indexing integrations and supports modern schema standards designed for video content platforms.

### Core Features

* Lightweight architecture with minimal database footprint
* High-performance XML sitemap engine with video and image extensions
* Dynamic JSON-LD schema generation (VideoObject, BreadcrumbList)
* Open Graph and Twitter Player Card support
* Instant indexing integrations (Google Indexing API & IndexNow)
* Multi-key API rotation support
* Optional SEO Health Audit dashboard
* Integration support for Hydro shortlinks

### Performance Philosophy

Saiteki focuses on generating SEO data dynamically instead of storing large meta structures.

### Security

Sensitive API credentials can be stored in encrypted form before saving to the WordPress database.

== Installation ==

1. Upload the `saiteki` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the “Saiteki” menu in the WordPress dashboard.
4. Enable and configure the modules you want to use.

== Frequently Asked Questions ==

= Does Saiteki work alongside other SEO plugins? =
Running multiple SEO plugins may create duplicate meta tags.

= What is Instant Indexing? =
Saiteki can send indexing requests to supported APIs when new content is published.

= Why are tag archives set to noindex? =
This helps search engines prioritize primary content.

== Changelog ==

= 1.1.1 =

* Fixed admin health audit PHP closing tag bug

= 1.1.0 =

* Added full i18n translation support.
* Introduced SEO Health Audit module.

= 1.0.0 =

* Initial release.
