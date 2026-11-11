<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Frontend output: meta description, Open Graph, Twitter cards, canonicals and JSON-LD.
 * Everything is generated from existing post data, nothing is stored per post.
 */
class Saiteki_Frontend {
    private static $options = array();

    public static function init( $options ) {
        self::$options = $options;
        add_action( 'wp_head', array( __CLASS__, 'render_seo_tags' ), 1 );

        if ( self::$options['noindex_tags'] === '1' ) {
            add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
        }

        // Core's rel_canonical stays active: it covers posts and pages (including comment pages).
        // Saiteki only adds canonicals where core prints none (home, posts page, categories, tags).

        if ( self::$options['enable_dynamic_titles'] === '1' ) {
            add_filter( 'document_title_parts', array( __CLASS__, 'dynamic_title' ) );
        }

        // Hydro bridge: the Hydro short URL becomes the page's shortlink (rel="shortlink").
        // og:url stays the canonical URL. Hydro's API module loads later (plugins_loaded, 25),
        // so its availability is checked when the filter runs, not here.
        if ( self::$options['enable_hydro_bridge'] === '1' ) {
            add_filter( 'pre_get_shortlink', array( __CLASS__, 'hydro_shortlink' ), 10, 2 );
        }
    }

    /**
     * Appends the configured suffix (default "Video {year}") to the title of single posts.
     */
    public static function dynamic_title( $title_parts ) {
        if ( is_singular( 'post' ) ) {
            $suffix = trim( str_replace( '{year}', wp_date( 'Y' ), (string) self::$options['title_suffix'] ) );
            if ( $suffix !== '' ) {
                $title_parts['tagline'] = $suffix;
            }
        }
        return $title_parts;
    }

    /**
     * Returns the Hydro short URL for a published post.
     *
     * hydro_create_or_get_shortlink() looks the target up by URL, which is an unindexed
     * full scan of the shortlink table. To keep that load away from Hydro:
     *  - the result is memoized per request (core asks twice: <head> tag + HTTP Link header),
     *  - the slug is remembered in post meta (_saiteki_hydro_slug) and re-checked with a
     *    lookup on Hydro's unique slug index, so the API is only called once per post
     *    (or again if the link was deleted or the permalink changed).
     * Saiteki only reads the Hydro table; new links are created exclusively through Hydro's API.
     */
    public static function hydro_shortlink( $return, $id ) {
        static $memo = array();

        if ( false !== $return || ! function_exists( 'hydro_create_or_get_shortlink' ) ) return $return;
        $post = $id ? get_post( $id ) : ( is_singular( 'post' ) ? get_queried_object() : null );
        if ( ! $post instanceof WP_Post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) return $return;

        if ( ! array_key_exists( $post->ID, $memo ) ) {
            $memo[ $post->ID ] = self::resolve_hydro_shortlink( $post );
        }
        return $memo[ $post->ID ] !== '' ? $memo[ $post->ID ] : $return;
    }

    private static function resolve_hydro_shortlink( WP_Post $post ): string {
        global $wpdb;
        $permalink = (string) get_permalink( $post );
        $slug      = (string) get_post_meta( $post->ID, '_saiteki_hydro_slug', true );

        if ( $slug !== '' ) {
            // Read-only check on the UNIQUE slug index: the slug still exists and still points here.
            $ok = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT 1 FROM {$wpdb->prefix}shortlinks WHERE slug = %s AND url = %s LIMIT 1",
                $slug, esc_url_raw( $permalink )
            ) );
            if ( $ok ) return home_url( '/' . $slug );
        }

        $short = hydro_create_or_get_shortlink( $permalink );
        if ( ! $short || ! is_string( $short ) ) return '';

        $new_slug = trim( (string) wp_parse_url( $short, PHP_URL_PATH ), '/' );
        if ( $new_slug !== '' && $new_slug !== $slug ) {
            update_post_meta( $post->ID, '_saiteki_hydro_slug', $new_slug );
        }
        return $short;
    }

    /**
     * Tag archives: noindex, follow (saves crawl budget). Uses the core robots API,
     * so there is only one robots meta tag.
     */
    public static function robots( array $robots ): array {
        if ( is_tag() ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
        }
        return $robots;
    }

    /**
     * Canonical URL of the current archive page, including pagination (/page/2/).
     */
    private static function archive_url( string $base ): string {
        $paged = max( 1, (int) get_query_var( 'paged' ) );
        if ( $paged === 1 ) return $base;
        global $wp_rewrite;
        if ( $wp_rewrite->using_permalinks() ) {
            return trailingslashit( $base ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
        }
        return add_query_arg( 'paged', $paged, $base );
    }

    private static function get_latest_grid_image(): string {
        global $wp_query;
        if ( ! empty( $wp_query->posts ) && $wp_query->posts[0] instanceof WP_Post ) {
            $first_post = $wp_query->posts[0];
            $img        = get_the_post_thumbnail_url( $first_post->ID, 'full' );
            if ( $img ) return $img;

            $video = Saiteki_Core::video( $first_post->ID );
            if ( $video['video_id'] !== '' ) {
                return Saiteki_Core::youtube_thumb( (string) $video['video_id'] );
            }
        }
        return '';
    }

    private static function json_ld( array $data ) {
        echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . PHP_EOL;
    }

    public static function render_seo_tags() {
        echo PHP_EOL;
        $site = Saiteki_Core::plain( get_bloginfo( 'name' ) );

        if ( is_singular( 'post' ) ) {
            self::render_post( get_queried_object(), $site );
        }
        elseif ( is_singular() && ! is_front_page() ) {
            // Pages and other post types: description + social tags (core prints the canonical).
            $post  = get_queried_object();
            $title = Saiteki_Core::plain( get_the_title( $post ) );

            if ( post_password_required( $post ) ) {
                $excerpt = ''; // never leak protected content into the description
            } elseif ( has_excerpt( $post ) ) {
                $excerpt = Saiteki_Core::plain( get_the_excerpt( $post ) );
            } else {
                $excerpt = wp_html_excerpt( Saiteki_Core::plain( strip_shortcodes( $post->post_content ) ), 160, '…' );
            }
            if ( $excerpt === '' ) $excerpt = $title . ' - ' . $site;

            self::print_meta_tags( $title, $excerpt, get_permalink( $post ), (string) get_the_post_thumbnail_url( $post->ID, 'full' ), 'website', '' );
        }
        elseif ( is_front_page() || is_home() || is_category() ) {
            $is_cat = is_category();

            if ( $is_cat ) {
                $cat_name = Saiteki_Core::plain( single_cat_title( '', false ) );
                $title    = $cat_name . ' - ' . $site;
                $excerpt  = Saiteki_Core::plain( category_description() );
                /* translators: 1: category name, 2: site name */
                if ( $excerpt === '' ) $excerpt = sprintf( __( 'All videos from the category %1$s on %2$s.', 'saiteki' ), $cat_name, $site );
                $base = get_category_link( get_queried_object_id() );
            } else {
                $title   = $site;
                $excerpt = Saiteki_Core::plain( get_bloginfo( 'description' ) );
                // A separate posts page ("Posts page" in Settings > Reading) has its own URL.
                $posts_page = ( is_home() && ! is_front_page() ) ? (int) get_option( 'page_for_posts' ) : 0;
                if ( $posts_page > 0 ) {
                    $title = Saiteki_Core::plain( get_the_title( $posts_page ) ) . ' - ' . $site;
                    $base  = get_permalink( $posts_page );
                } else {
                    $base = home_url( '/' );
                }
            }

            $url = self::archive_url( is_string( $base ) && $base !== '' ? $base : home_url( '/' ) );
            if ( ! is_singular() ) { // a static front page already gets its canonical from core
                echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . PHP_EOL;
            }

            self::print_meta_tags( $title, $excerpt, $url, self::get_latest_grid_image(), 'website', '' );

            if ( self::$options['enable_schema'] === '1' ) {
                global $wp_query;
                if ( ! is_singular() && ! empty( $wp_query->posts ) ) {
                    $item_list = array(
                        '@context'        => 'https://schema.org',
                        '@type'           => 'ItemList',
                        'itemListElement' => array(),
                    );
                    $position = 1;
                    foreach ( $wp_query->posts as $grid_post ) {
                        if ( ! $grid_post instanceof WP_Post ) continue;
                        $item_list['itemListElement'][] = array(
                            '@type'    => 'ListItem',
                            'position' => $position,
                            'url'      => get_permalink( $grid_post->ID ),
                            'name'     => Saiteki_Core::plain( get_the_title( $grid_post->ID ) ),
                        );
                        $position++;
                    }
                    if ( ! empty( $item_list['itemListElement'] ) ) {
                        self::json_ld( $item_list );
                    }
                }
            }
        }
        elseif ( is_tag() ) {
            // Tags are noindex by default (see robots()): canonical + social tags only.
            $tag      = get_queried_object();
            $link     = get_tag_link( $tag->term_id );
            $url      = self::archive_url( is_string( $link ) ? $link : home_url( '/' ) );
            $tag_name = Saiteki_Core::plain( single_tag_title( '', false ) );

            echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . PHP_EOL;
            /* translators: %s: tag name */
            self::print_meta_tags( $tag_name . ' - ' . $site, sprintf( __( 'Video archive for the tag %s', 'saiteki' ), $tag_name ), $url, '', 'website', '' );
        }

        echo PHP_EOL;
    }

    private static function render_post( WP_Post $post, string $site ) {
        $title     = Saiteki_Core::plain( get_the_title( $post ) );
        $canonical = get_permalink( $post );
        $protected = post_password_required( $post );
        $excerpt   = ( ! $protected && has_excerpt( $post ) )
            ? Saiteki_Core::plain( get_the_excerpt( $post ) )
            /* translators: 1: video title, 2: site name */
            : sprintf( __( 'Watch %1$s on %2$s.', 'saiteki' ), $title, $site );

        $image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
        $video     = $protected ? array( 'video_id' => '', 'views' => 0, 'duration' => '' ) : Saiteki_Core::video( $post->ID );
        $video_id  = (string) $video['video_id'];
        $views     = (int) $video['views'];
        $duration  = (string) $video['duration'];

        if ( ! $image_url && $video_id !== '' ) {
            $image_url = Saiteki_Core::youtube_thumb( $video_id );
        }

        self::print_meta_tags( $title, $excerpt, $canonical, $image_url, $video_id !== '' ? 'video.other' : 'article', $video_id );

        if ( self::$options['enable_schema'] !== '1' ) return;

        if ( $video_id !== '' ) {
            $schema = array(
                '@context'     => 'https://schema.org',
                '@type'        => 'VideoObject',
                'name'         => $title,
                'description'  => $excerpt,
                'thumbnailUrl' => array( $image_url ),
                'uploadDate'   => get_the_date( 'c', $post ),
                'embedUrl'     => 'https://www.youtube.com/embed/' . rawurlencode( $video_id ),
                'url'          => $canonical,
            );
            // Only ISO 8601 durations (PT3M21S) are valid in schema.org.
            if ( preg_match( '/^P(?!$)(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $duration ) ) {
                $schema['duration'] = $duration;
            }
            if ( $views > 0 ) {
                $schema['interactionStatistic'] = array(
                    '@type'                => 'InteractionCounter',
                    'interactionType'      => array( '@type' => 'WatchAction' ),
                    'userInteractionCount' => $views,
                );
            }
            self::json_ld( $schema );
        }

        $categories  = get_the_category( $post->ID );
        $breadcrumbs = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'saiteki' ), 'item' => home_url( '/' ) ),
            ),
        );

        $pos = 2;
        if ( ! empty( $categories ) ) {
            $breadcrumbs['itemListElement'][] = array(
                '@type' => 'ListItem', 'position' => $pos, 'name' => Saiteki_Core::plain( $categories[0]->name ), 'item' => get_category_link( $categories[0]->term_id ),
            );
            $pos++;
        }
        $breadcrumbs['itemListElement'][] = array(
            '@type' => 'ListItem', 'position' => $pos, 'name' => $title, 'item' => $canonical,
        );

        self::json_ld( $breadcrumbs );
    }

    private static function print_meta_tags( $title, $description, $url, $image_url, $type, $video_id ) {
        $description = wp_html_excerpt( (string) $description, 300, '…' );

        if ( $description !== '' ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '">' . PHP_EOL;
        }
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . PHP_EOL;
        if ( $description !== '' ) {
            echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . PHP_EOL;
        }
        echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . PHP_EOL;
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . PHP_EOL;
        echo '<meta property="og:site_name" content="' . esc_attr( Saiteki_Core::plain( get_bloginfo( 'name' ) ) ) . '">' . PHP_EOL;
        $locale = get_locale();
        if ( preg_match( '/^[a-z]{2,3}_[A-Z]{2}$/', $locale ) ) { // Open Graph expects language_TERRITORY
            echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '">' . PHP_EOL;
        }

        if ( $image_url ) {
            echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . PHP_EOL;
        }

        if ( self::$options['enable_twitter_cards'] === '1' && $video_id ) {
            echo '<meta name="twitter:card" content="player">' . PHP_EOL;
            echo '<meta name="twitter:player" content="' . esc_url( 'https://www.youtube.com/embed/' . rawurlencode( $video_id ) ) . '">' . PHP_EOL;
            echo '<meta name="twitter:player:width" content="1280">' . PHP_EOL;
            echo '<meta name="twitter:player:height" content="720">' . PHP_EOL;
        } else {
            echo '<meta name="twitter:card" content="summary_large_image">' . PHP_EOL;
        }
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . PHP_EOL;
        if ( $description !== '' ) {
            echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . PHP_EOL;
        }
        if ( $image_url ) echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '">' . PHP_EOL;
    }
}
