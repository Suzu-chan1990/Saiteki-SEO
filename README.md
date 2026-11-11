<p align="center"><img src="./assets/banner-1544x500.png" width="772" alt="Saiteki SEO"></p>

# Saiteki SEO (最適)

SEO plugin for WordPress sites whose posts are videos. It prints meta tags and structured data, serves an XML sitemap with video and image entries on a path you choose, and reports new posts to search engines.

Nothing is stored per post. Every tag and every sitemap entry is built from data the post already has (title, excerpt, featured image, video ID), so the database does not grow with the number of posts.

## Contents

- [Requirements](#requirements)
- [Installation and updates](#installation-and-updates)
- [Admin screen](#admin-screen)
- [XML sitemap](#xml-sitemap)
- [Frontend output](#frontend-output)
- [Instant indexing](#instant-indexing)
- [Hydro Shortlinks bridge](#hydro-shortlinks-bridge)
- [Health audit](#health-audit)
- [Where the video comes from](#where-the-video-comes-from)
- [Filters](#filters)
- [Data storage](#data-storage)
- [Uninstall](#uninstall)
- [Troubleshooting](#troubleshooting)
- [Files](#files)
- [License](#license)

## Requirements

- WordPress 6.9 or newer
- PHP 8.4 or newer with the OpenSSL extension
- A permalink structure other than "Plain"
- A web server that passes requests for files that do not exist to WordPress (the normal permalink setup)

## Installation and updates

1. Download `saiteki.zip` from the latest [release](https://github.com/Suzu-chan1990/Saiteki-SEO/releases).
2. Upload it under **Plugins > Add New > Upload Plugin** and activate it.
3. Open **Saiteki SEO** in the admin menu.

On activation the plugin creates one table (`{prefix}saiteki_settings`) and generates an IndexNow key. All modules except the health audit are switched on.

Updates come from the GitHub releases of this repository and appear on the normal WordPress update screen. The check runs in the admin, during WP-Cron and in WP-CLI, never on frontend requests. A release must have a ZIP file attached whose top-level folder is `saiteki`.

Settings are migrated automatically on the first request after an update. Sites that update from 1.x keep their sitemap URL.

## Admin screen

The dashboard shows a status score, up to three recommendations, the sitemap URLs, a few numbers and the list of modules. The score is a checklist, not a ranking prediction:

| Check | Points |
| --- | --- |
| Site is visible to search engines (Settings > Reading) | 20 |
| XML sitemap is enabled | 20 |
| A permalink structure is set | 10 |
| No other SEO plugin is active | 10 |
| JSON-LD schema is enabled | 10 |
| Instant indexing is enabled | 10 |
| Sitemap is announced in robots.txt | 5 |
| Twitter player cards are enabled | 5 |
| IndexNow key is readable | 5 |
| Stored Google keys can be decrypted | 5 |

85 and above is shown as "Optimal", 65 to 84 as "Attention", below that as "Critical".

The settings are grouped into four sections:

| Section | Tabs |
| --- | --- |
| XML Sitemap | Path & Files, Content |
| Frontend SEO | Meta & Social, Schema & Titles |
| Instant Indexing | IndexNow, Google Indexing API, Hydro Bridge |
| Tools | Health Audit, System |

Switches save the moment they are clicked. Text fields have their own save button. All requests are nonce-protected and require the `manage_options` capability.

## XML sitemap

### Files

| File | Content |
| --- | --- |
| `{dir}/{index}` | Sitemap index listing all files below with their last-modified date |
| `{dir}/{prefix}-pages.xml` | Home page, published pages without a password, categories with at least one post |
| `{dir}/{prefix}-1.xml`, `-2.xml`, … | Published posts, 5,000 per file by default |

Posts are ordered by ID, oldest first. A new post is appended to the last file and all earlier files stay unchanged, so search engines only have to fetch one file again.

Each post entry contains the permalink and the last-modified date. If enabled, it also contains:

- an image entry with the featured image
- a video entry with thumbnail, title, description (excerpt, or the title if there is none) and the YouTube embed URL

Left out: drafts, password-protected posts, tag archives.

When the sitemap module is on, the WordPress core sitemap (`/wp-sitemap.xml`) is switched off.

### Path

Under **XML Sitemap > Path & Files**:

| Setting | Allowed | Example | Result |
| --- | --- | --- | --- |
| Directory | Letters, digits, `.`, `_`, `-`, up to 6 levels; empty = site root | `media/sitemaps` | `https://example.com/media/sitemaps/…` |
| Index file name | Letters, digits, `.`, `_`, `-`; `.xml` is added if missing | `sitemap_index.xml` | `…/sitemap_index.xml` |
| Prefix of the sub-sitemaps | Letters, digits, `_`, `-` | `sitemap` | `…/sitemap-pages.xml`, `…/sitemap-1.xml` |

Defaults for a new installation: site root, `sitemap_index.xml`, `sitemap`. A site updated from 1.x keeps `appurodo/saito-mappu`.

The path is virtual. No directory and no file is created, so it does not matter whether the directory exists, and it works when the upload or content directory has been renamed. If a real file with the same name exists, the web server delivers that file instead.

The preview below the fields shows the resulting URLs while typing. **Test reachability** lets the server request its own index and reports the HTTP status.

### Changing the path

- The previous index, pages file and post files answer with a 301 redirect to the new URLs. This can be switched off, and the previous location can be forgotten.
- Only the last location is remembered. After two changes in a row the first one is no longer redirected.
- The `Sitemap:` line in the virtual robots.txt follows automatically. A physical `robots.txt` in the site root has to be edited by hand; the settings page shows a warning if one exists.
- Submit the new index URL in Google Search Console and Bing Webmaster Tools.

### Content settings

Under **XML Sitemap > Content**:

- Video entries on/off
- Image entries on/off
- Static pages on/off
- Categories on/off
- Posts per file: 100 to 20,000. Changing it moves posts between files.

### How requests are answered

- Sitemap URLs are matched exactly and answered on `parse_request`, before WordPress runs its main query.
- Responses carry an `ETag`. A request with a matching `If-None-Match` gets `304 Not Modified` without building the file.
- The file list of the index is cached until a post or page is saved or deleted, at most 12 hours. **Tools > System > Rebuild sitemap index** clears it, which is needed after an import that writes to the database directly.
- Posts, their meta data and featured images are loaded in batches of 1,000.
- Responses are sent with `X-Robots-Tag: noindex, follow`, so the sitemap itself does not show up in search results.

### Web server

Requests for the sitemap must reach WordPress. With nginx the usual rule is enough:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

A rule that answers `.xml` files directly breaks the sitemap, for example:

```nginx
# Do not use together with a virtual sitemap
location ~* \.xml$ {
    try_files $uri =404;
}
```

## Frontend output

All tags are printed in `<head>`. Titles and descriptions are plain text: HTML is stripped and entities are decoded.

### Meta and social tags

| Page | Description | `og:type` | Image | Canonical |
| --- | --- | --- | --- | --- |
| Post with video | Excerpt, otherwise "Watch {title} on {site}." | `video.other` | Featured image, otherwise the YouTube thumbnail | WordPress core |
| Post without video | Same as above | `article` | Featured image | WordPress core |
| Page | Excerpt, otherwise the first 160 characters of the content | `website` | Featured image | WordPress core |
| Home page, posts page | Site tagline | `website` | Image of the first listed post | Printed by Saiteki, including `/page/2/` |
| Category | Category description, otherwise "All videos from the category {name} on {site}." | `website` | Image of the first listed post | Printed by Saiteki, including pagination |
| Tag | "Video archive for the tag {name}" | `website` | none | Printed by Saiteki, including pagination |

Printed for all of these: `description`, `og:title`, `og:description`, `og:type`, `og:url`, `og:site_name`, `og:image`, `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`. `og:locale` is printed when the site locale has the form `xx_YY`.

Password-protected content never appears in a description.

Search results, 404 pages, author and date archives get no tags from Saiteki.

### Twitter cards

With **Twitter player cards** on, posts with a video use the `player` card with the YouTube embed (1280 × 720). Everything else, and all posts when the switch is off, use `summary_large_image`.

### Tag archives

With **Noindex tag archives** on (default), tag archives get `noindex, follow` through the WordPress robots API, so there is only one robots meta tag.

### Schema

With **JSON-LD schema** on:

| Page | Type | Fields |
| --- | --- | --- |
| Post with video | `VideoObject` | name, description, thumbnailUrl, uploadDate (post date), embedUrl, url; duration if it is a valid ISO 8601 value; view count if known |
| Every post | `BreadcrumbList` | Home > first category > post |
| Home page, category | `ItemList` | The posts on the current page |

### Titles

With **Dynamic titles** on, a suffix is appended to the browser title of single posts. The suffix is a setting; `{year}` is replaced by the current year. Default: `Video {year}`. An empty suffix adds nothing.

## Instant indexing

One switch turns both services on or off. A post is reported once, at the moment it changes to "published". Updates to an already published post and password-protected posts are not reported.

### IndexNow

IndexNow is used by Bing, Yandex, Seznam and Naver. It needs no account.

- A 32-character key is generated automatically.
- The key file is served at `https://example.com/{key}.txt`. It is virtual, like the sitemap.
- All posts published in one request or one import run are sent in a single request. During a long run a request is sent every 500 URLs. Sending one request per post leads to HTTP 429 from IndexNow.
- **Send test request** submits the home page and shows the answer. 200 and 202 mean accepted, 403 means the key file could not be verified, 422 means the URL does not belong to the host, 429 means IndexNow is throttling the site.
- **Generate new key** replaces the key. The old key file stops working immediately.

### Google Indexing API

This is optional and only active when at least one key is stored.

Setup:

1. Create a project in the Google Cloud console and enable the "Web Search Indexing API".
2. Create a service account and download its JSON key.
3. Add the service account's e-mail address as an owner of the property in Google Search Console.
4. Paste the content of the JSON file under **Instant Indexing > Google Indexing API** and save.

Several JSON files can be pasted one after the other. Entries without a readable private key are skipped and reported. The same account pasted twice is stored once.

- **Rotation:** each published post uses the next key in the list, round robin. With several service accounts the daily quota of each one is used evenly.
- **Add or replace:** new keys can be added to the stored ones, or replace them. Replacing restarts the rotation at key 1.
- **Test:** requests an access token for one key. It uses no indexing quota.
- **Remove:** single keys or all keys.
- Access tokens are cached per key for about 55 minutes.
- Private keys are stored encrypted and are never shown again. Only the account e-mail and the project ID are listed.

Google documents this API for job postings and livestream pages. Whether it has an effect on other content is up to Google.

## Hydro Shortlinks bridge

If the Hydro Shortlinks plugin is active, the short URL of a post is used as its `rel="shortlink"`. `og:url` stays the canonical URL.

The short URL is requested once per post and its slug is remembered in post meta. Later page views only check that slug, so Hydro's link table is not searched by URL on every view. Saiteki reads Hydro's table and never writes to it. The setting is kept while Hydro is inactive.

## Health audit

Two optional checks under **Tools > Health Audit**, both off by default:

- Published posts without a featured image
- Published posts whose content and excerpt are both shorter than 100 characters

Each check scans the posts table. The result is cached for 15 minutes and can be recounted with a button. Switched off, the checks run no queries.

## Where the video comes from

The video ID of a post is looked up in this order:

1. The theme function `jpop_get_turbo_video( $post_id )`, if it exists. It is expected to return an array with `video_id` and optionally `raw_meta` (`view_count`, `duration`).
2. The first YouTube link in the post content (`watch?v=`, `embed/`, `shorts/`, `live/`, `youtu.be/`).
3. Whatever the `saiteki_video_data` filter returns.

A post without a video ID still gets meta tags and a sitemap entry, just without the video parts.

## Filters

```php
// Provide the video of a post from your own source.
add_filter( 'saiteki_video_data', function ( array $data, int $post_id ) {
    $data['video_id'] = get_post_meta( $post_id, 'my_youtube_id', true );
    $data['views']    = (int) get_post_meta( $post_id, 'my_views', true );
    $data['duration'] = 'PT3M21S'; // ISO 8601
    return $data;
}, 10, 2 );

// Skip instant indexing for some posts.
add_filter( 'saiteki_should_index', function ( bool $send, WP_Post $post, string $url ) {
    return $send && ! has_tag( 'private', $post );
}, 10, 3 );
```

## Data storage

| Where | What |
| --- | --- |
| Table `{prefix}saiteki_settings`, row `saiteki_settings` | All switches and text settings, the encrypted IndexNow key |
| Same table, row `saiteki_google_keys` | Google service accounts, encrypted |
| Same table, row `saiteki_google_key_index` | Position of the key rotation |
| Option `saiteki_settings_mirror` (autoloaded) | Copy of the settings row, so reading settings needs no extra query. It never contains the Google keys or the rotation position. |
| Options `saiteki_version`, `saiteki_db_version` | Version markers for migrations |
| Transients `saiteki_sm_index`, `saiteki_health`, `saiteki_gtoken_*` | Sitemap index cache, audit result, Google access tokens (encrypted) |
| Post meta `_saiteki_hydro_slug` | Remembered Hydro slug, only with the Hydro bridge |

Secrets are encrypted with AES-256-GCM. The key is derived from the `AUTH` salt in `wp-config.php`. If the salts change, stored secrets can no longer be read: a new IndexNow key is generated automatically, Google keys have to be pasted again, and the admin screen says so.

## Uninstall

Deactivating keeps all data. Deleting the plugin removes the table, all `saiteki_*` options and transients, and the `_saiteki_hydro_slug` post meta, on every site of a multisite network.

## Troubleshooting

**The sitemap returns 404.**
Check that the sitemap module is on and that a permalink structure is set. Use **Test reachability**. If the server answers `.xml` files itself, see [Web server](#web-server).

**The sitemap shows old data.**
The index is cached for up to 12 hours if posts were written to the database without WordPress functions. Use **Tools > System > Rebuild sitemap index**. A page cache in front of WordPress may also hold a copy.

**Meta tags appear twice.**
Another SEO plugin or the theme prints the same tags. The dashboard lists detected SEO plugins.

**IndexNow answers with 429.**
The site sent too many requests in a short time. It ends on its own. Versions before 2.0 sent one request per post.

**IndexNow answers with 403.**
Open the key file URL shown under **Instant Indexing > IndexNow**. It must show the key as plain text.

**Google keys are shown as "cannot be decrypted".**
The salts in `wp-config.php` changed after the keys were saved. Paste the JSON files again.

**Posts have no video entry in the sitemap.**
No video ID was found. See [Where the video comes from](#where-the-video-comes-from).

**Going back to 1.x.**
Possible, but the Google keys have to be pasted again, because 1.x cannot read where 2.0 stores them.

## Files

```
saiteki.php                           Bootstrap, settings storage, migrations, updater
uninstall.php                         Removes all data when the plugin is deleted
includes/class-saiteki-admin.php      Admin screen and its AJAX handlers
includes/class-saiteki-crypto.php     Encryption of stored secrets
includes/class-saiteki-frontend.php   Meta tags, canonicals, JSON-LD, titles, Hydro bridge
includes/class-saiteki-indexing.php   IndexNow and Google Indexing API
includes/class-saiteki-sitemap.php    Sitemap routing and output
css/admin.css, js/admin.js            Admin screen assets
assets/                               Icon and banner
languages/                            Translation template and German translation
lib/plugin-update-checker/            Update checker library (MIT, by Janis Elsts)
```

The admin screen is in English with a complete German translation (`de_DE`). `languages/saiteki.pot` is the template for other languages.

## License

GPL-2.0-or-later, see [LICENSE](./LICENSE). The bundled update checker library is MIT-licensed. Changes per version are listed in [CHANGELOG.md](./CHANGELOG.md).
