<p align="center"><img src="./assets/banner-1544x500.png" width="772" alt="Saiteki SEO"></p>

# Saiteki SEO (最適)

Fast and lightweight SEO plugin for video-focused WordPress sites: dynamic schema, XML sitemaps on a custom path, and instant indexing with key rotation.

Saiteki generates its output from existing post data. Nothing is stored per post, so the database does not grow with the number of videos.

## Features

| Area | What it does |
| --- | --- |
| **XML sitemap** | Index, pages file and post files with video and image extensions. Posts are ordered oldest first, so a new post only changes the last file. |
| **Custom path** | Directory, index file name and file prefix are configurable. The files are virtual, nothing is written to disk. The previous location is redirected (301). |
| **Frontend SEO** | Meta description, Open Graph, Twitter Player Cards, canonicals for archives, optional `noindex` for tag archives, title suffix. |
| **Schema** | JSON-LD `VideoObject` and `BreadcrumbList` on posts, `ItemList` on the home page and categories. |
| **Instant indexing** | IndexNow and the Google Indexing API. Any number of Google service accounts with round-robin rotation and a per-key test. |
| **Integrations** | Hydro Shortlinks bridge (`rel="shortlink"`). |
| **Tools** | Optional SEO health audit, system overview. |

## Requirements

- WordPress 6.9 or newer
- PHP 8.4 or newer with OpenSSL
- A permalink structure other than "Plain"

## Installation

1. Download the ZIP of the latest [release](https://github.com/Suzu-chan1990/Saiteki-SEO/releases).
2. Upload it under **Plugins > Add New > Upload Plugin** and activate it.
3. Open **Saiteki SEO** in the admin menu.

Updates are delivered through the built-in GitHub update checker.

## Sitemap path

Under **XML Sitemap > Path & Files**:

| Setting | Example | Result |
| --- | --- | --- |
| Directory | `media/sitemaps` | `https://example.com/media/sitemaps/…` (empty = site root) |
| Index file name | `sitemap_index.xml` | `…/sitemap_index.xml` |
| Prefix of the sub-sitemaps | `sitemap` | `…/sitemap-pages.xml`, `…/sitemap-1.xml`, … |

New installations use the site root. Sites updated from 1.x keep the URL they had.

The path is answered by WordPress. The web server must pass requests for files that do not exist to `index.php`, which is the normal permalink setup. The **Test reachability** button checks this.

## Developer notes

```php
// Provide the video of a post from your own source.
add_filter( 'saiteki_video_data', function ( array $data, int $post_id ) {
    $data['video_id'] = get_post_meta( $post_id, 'my_youtube_id', true );
    return $data; // keys: video_id, views, duration (ISO 8601, e.g. PT3M21S)
}, 10, 2 );

// Skip instant indexing for single posts.
add_filter( 'saiteki_should_index', fn( $send, $post, $url ) => $send && ! has_tag( 'private', $post ), 10, 3 );
```

A GitHub token against API rate limits of the update checker can be set in `wp-config.php`:

```php
define( 'SAITEKI_GITHUB_TOKEN', 'github_pat_…' );
```

## Data storage

- Table `{prefix}saiteki_settings`: all settings. Google keys are stored encrypted (AES-256-GCM) in their own row.
- Option `saiteki_settings_mirror`: autoloaded copy of the small settings, without keys.
- Post meta `_saiteki_hydro_slug`: remembered Hydro slug (only with the Hydro bridge).

Deleting the plugin removes all of it.

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE). Changes are listed in [CHANGELOG.md](./CHANGELOG.md).
