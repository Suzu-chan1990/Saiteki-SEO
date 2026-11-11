# Changelog

All notable changes to this project are documented in this file.

## [2.0.0]

### Added
- **Sitemap path settings**: directory, index file name and file prefix are configurable (XML Sitemap > Path & Files), with live preview and a reachability test. Sites updated from 1.x keep `/appurodo/saito-mappu/sitemap_index.xml`; new installations start at `/sitemap_index.xml`.
- **Redirect from the previous location**: after a path change the old index, pages file and post files answer with 301 to the new URLs (can be switched off).
- **New admin screen**: dashboard with SEO status, score and recommendations, section tiles and tabs. Switches save instantly, forms save without a page reload.
- **Google key management**: list of stored service accounts with the next key of the rotation, test per key (no indexing quota used), remove single keys or all keys, add to or replace the stored set.
- **More settings**: posts per sitemap file, pages/categories/images/videos in the sitemap, robots.txt announcement, title suffix with `{year}`, `noindex` for tag archives.
- **IndexNow**: key and key file URL are shown, new key on demand, test request.
- **Video source fallback**: without the theme helper `jpop_get_turbo_video()` the first YouTube link in the post content is used. New filters `saiteki_video_data` and `saiteki_should_index`.
- Warning when another SEO plugin is active, and when a physical `robots.txt` exists.
- `saiteki.pot` template; the German translation covers every string.

### Fixed
- **Google keys were loaded on every page view**: they were part of the autoloaded settings copy, which was also rewritten on every publish. Keys and the rotation pointer now live in their own rows and are only read while publishing.
- **Sitemap files ran several queries per post** (post, featured image, attachment meta). Posts are loaded in batches of 1,000 with a handful of queries.
- **Sitemap index ran one query per file on every request**. The file list is cached until content changes.
- **Protected pages leaked their content** into the meta description. Password-protected posts are left out of the sitemap and are not reported to search engines.
- **Posts page** (Settings > Reading) pointed its canonical to the home page.
- **Stored Google keys could not be removed**, and the counter showed "1 active" without any key.
- **IndexNow stayed dead after the wp-config.php salts changed**: an undecryptable key is replaced. Undecryptable Google keys are reported instead of failing silently.
- **IndexNow key file** was not found on sites installed in a sub-directory.
- **IndexNow was flooded during bulk imports**: every published post sent its own request, which IndexNow answers with HTTP 429. All posts of one request or import run are now sent in one batch (at the latest every 500 URLs). The test button explains 429, 403 and 422.
- Sitemap URLs were matched anywhere in the request path; they are matched exactly now.
- Twitter title and description were missing on pages without a video; posts without a video use `og:type` `article`.
- The admin page loaded the Hydro Shortlinks stylesheet, attached its script to jQuery and showed untranslated labels.
- Settings were processed while the page was rendered; saving is handled by dedicated, nonce-protected requests.
- The translation was loaded before `init`.
- The audit counted bytes instead of characters for short descriptions.
- `readme.txt` contained a second copy of itself.

### Changed
- Secrets are encrypted with AES-256-GCM (format `SAITEKI_ENC3:`). Older values stay readable and are converted during the update.
- The sitemap and the IndexNow key file are answered on `parse_request`, before the main query.
- Sitemap responses send `ETag` and answer conditional requests with `304 Not Modified`.
- The Google rotation itself is unchanged: one key per published post, round robin.
- The menu entry moved down next to the other tools (position 101).
- Uninstall also removes the remembered Hydro slugs.
- Updater: the bundled update checker is always loaded (no longer skipped when another plugin loaded its own copy), and the plugin declares an `Update URI`, so wordpress.org can never offer a foreign plugin with the same slug as an update.

### Removed
- The optional `SAITEKI_GITHUB_TOKEN` constant for the update checker. The repository is public and needs no token.

### Upgrade notes
- The migration runs automatically on the first request after the update.
- A downgrade to 1.x is possible, but the Google keys have to be pasted again.

## [1.2.1]

### Fixed
- Google Indexing API keys were damaged on save (double `wp_unslash()`); existing keys are repaired automatically.
- Literal `\n` text in the XML sitemap.
- The sitemap index is announced in robots.txt.
- Pages keep their canonical URL; paginated archives get their own canonical.
- No more HTML entities in JSON-LD, meta tags and the sitemap.
- The Hydro bridge no longer switches itself off when Hydro is inactive while saving.
- The German translation is loaded.

### Changed
- Stable sitemap files (oldest first) with real lastmod dates, new `sitemap-pages.xml`.
- Google access tokens are cached per key (55 minutes).
- Random IV per encrypted value.
- Settings are read from an autoloaded mirror.
- `hqdefault` thumbnails, meta tags for pages, `og:url` is always canonical, Hydro URL as `rel="shortlink"`.

### Removed
- Unused ping class and `.bak` file.

### Added
- `uninstall.php`.

## [1.2.0]
- Dedicated settings table (`wp_saiteki_settings`) with caching and passive migration from `wp_options`.

## [1.1.2]
- Fixed the auto updater.

## [1.1.1]
- Fixed a PHP closing tag bug in the health audit.

## [1.1.0]
- Full i18n support, SEO Health Audit module.

## [1.0.0]
- Initial release.
