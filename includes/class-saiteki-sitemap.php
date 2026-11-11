<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * XML sitemap with image and video extensions on a configurable (virtual) path.
 *
 *   {dir}/{index}            index, e.g. /sitemap_index.xml
 *   {dir}/{prefix}-pages.xml home page, pages, categories
 *   {dir}/{prefix}-{n}.xml   posts, oldest first
 *
 * Nothing is written to disk: the URLs are answered by WordPress before the main query runs.
 * Posts are ordered by ID ascending, so a new post only ever changes the last file;
 * every file reports its real last modification date and supports conditional requests (ETag / 304).
 */
class Saiteki_Sitemap {
    const INDEX_CACHE = 'saiteki_sm_index';
    const BATCH       = 1000;

    private static $o = array();

    public static function init( $options ) {
        self::$o = $options;

        add_filter( 'wp_sitemaps_enabled', '__return_false' );
        // parse_request runs before the main query: a sitemap request never triggers a 404 query.
        add_action( 'parse_request', array( __CLASS__, 'intercept_request' ), 1 );

        if ( $options['sitemap_robots'] === '1' ) {
            add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
        }

        // The cached index (file count + lastmod per file) is rebuilt after content changes.
        add_action( 'save_post', array( __CLASS__, 'flush_on_save' ), 99, 2 );
        add_action( 'deleted_post', array( __CLASS__, 'flush_index_cache' ) );
    }

    // -----------------------------------------------------------------
    // Path handling
    // -----------------------------------------------------------------

    /**
     * Directory below the site URL: "a/b" (no leading or trailing slash), '' = site root.
     */
    public static function sanitize_dir( $raw ): string {
        $parts = array();
        foreach ( preg_split( '#[/\\\\]+#', (string) $raw ) as $segment ) {
            $segment = preg_replace( '/[^A-Za-z0-9._-]/', '', $segment );
            $segment = trim( (string) $segment, '.' );
            if ( $segment !== '' ) $parts[] = $segment;
        }
        return implode( '/', array_slice( $parts, 0, 6 ) );
    }

    /**
     * File name of the sitemap index, always ending in .xml.
     */
    public static function sanitize_index( $raw ): string {
        $name = preg_replace( '/[^A-Za-z0-9._-]/', '', basename( str_replace( '\\', '/', (string) $raw ) ) );
        $name = trim( (string) preg_replace( '/\.xml$/i', '', (string) $name ), '.' );
        return $name === '' ? 'sitemap_index.xml' : $name . '.xml';
    }

    /**
     * Prefix of the sub-sitemaps: {prefix}-pages.xml, {prefix}-1.xml, ...
     */
    public static function sanitize_prefix( $raw ): string {
        $prefix = trim( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $raw ), '-' );
        return $prefix === '' ? 'sitemap' : $prefix;
    }

    /**
     * True when the index file name would collide with one of the sub-sitemap names.
     */
    public static function names_collide( string $index, string $prefix ): bool {
        return (bool) preg_match( '/^' . preg_quote( $prefix, '/' ) . '-(pages|[0-9]+)\.xml$/i', $index );
    }

    private static function opts( ?array $o ): array {
        if ( $o !== null ) return $o;
        if ( empty( self::$o ) ) self::$o = Saiteki_Core::get_options();
        return self::$o;
    }

    /** Relative directory prefix, '' or "a/b/". */
    private static function base( array $o ): string {
        return $o['sitemap_dir'] !== '' ? $o['sitemap_dir'] . '/' : '';
    }

    public static function index_url( ?array $o = null ): string {
        $o = self::opts( $o );
        return home_url( '/' . self::base( $o ) . $o['sitemap_index'] );
    }

    public static function pages_url( ?array $o = null ): string {
        $o = self::opts( $o );
        return home_url( '/' . self::base( $o ) . $o['sitemap_prefix'] . '-pages.xml' );
    }

    public static function chunk_url( int $page, ?array $o = null ): string {
        $o = self::opts( $o );
        return home_url( '/' . self::base( $o ) . $o['sitemap_prefix'] . '-' . $page . '.xml' );
    }

    private static function limit(): int {
        return min( 20000, max( 100, (int) self::$o['sitemap_limit'] ) );
    }

    /**
     * Requested path relative to the site URL (without leading slash), or null if this is no .xml request.
     */
    private static function request_rel(): ?string {
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! str_ends_with( $path, '.xml' ) ) return null;

        $path = ltrim( rawurldecode( $path ), '/' );
        $home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home !== '' ) {
            if ( ! str_starts_with( $path, $home . '/' ) ) return null;
            $path = substr( $path, strlen( $home ) + 1 );
        }
        return $path;
    }

    /**
     * Matches a relative path against one sitemap layout.
     *
     * @return array|null array( 'index' ) | array( 'pages' ) | array( 'chunk', n )
     */
    private static function match( string $rel, string $dir, string $index, string $prefix ): ?array {
        $base = $dir !== '' ? $dir . '/' : '';
        if ( $rel === $base . $index ) return array( 'index' );
        if ( $rel === $base . $prefix . '-pages.xml' ) return array( 'pages' );
        if ( preg_match( '#^' . preg_quote( $base . $prefix, '#' ) . '-([1-9][0-9]{0,5})\.xml$#', $rel, $m ) ) {
            return array( 'chunk', (int) $m[1] );
        }
        return null;
    }

    public static function intercept_request() {
        $rel = self::request_rel();
        if ( $rel === null ) return;

        $o   = self::$o;
        $hit = self::match( $rel, $o['sitemap_dir'], $o['sitemap_index'], $o['sitemap_prefix'] );

        if ( $hit ) {
            if ( $hit[0] === 'index' ) self::serve_index();
            if ( $hit[0] === 'pages' ) self::serve_pages();
            if ( $hit[0] === 'chunk' ) self::serve_chunk( $hit[1] ); // returns for unknown files: WordPress answers with its 404
            return;
        }

        // Previous location: permanent redirect, so search engines follow a changed path.
        if ( $o['sitemap_redirect_old'] !== '1' || $o['sitemap_prev_index'] === '' ) return;
        $old = self::match( $rel, $o['sitemap_prev_dir'], $o['sitemap_prev_index'], $o['sitemap_prev_prefix'] );
        if ( ! $old ) return;

        $target = $old[0] === 'index' ? self::index_url() : ( $old[0] === 'pages' ? self::pages_url() : self::chunk_url( $old[1] ) );
        header( 'X-Robots-Tag: noindex, follow' );
        wp_safe_redirect( $target, 301, 'Saiteki SEO' );
        exit;
    }

    /**
     * Announces the sitemap in robots.txt (the core sitemap line is gone because core sitemaps are disabled).
     */
    public static function robots_txt( $output, $public ) {
        if ( ! $public ) return $output;
        return rtrim( (string) $output ) . "\n\nSitemap: " . self::index_url() . "\n";
    }

    // -----------------------------------------------------------------
    // Cache handling
    // -----------------------------------------------------------------

    public static function flush_on_save( $post_id, $post ) {
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;
        self::flush_index_cache();
    }

    public static function flush_index_cache() {
        static $done = false;
        if ( $done ) return; // once per request is enough (bulk imports)
        $done = true;
        delete_transient( self::INDEX_CACHE );
    }

    /**
     * Sends the headers and answers conditional requests. Ends the request with 304 when nothing changed.
     */
    private static function send_headers( string $seed ) {
        $o    = self::$o;
        $etag = '"' . md5( implode( '|', array(
            SAITEKI_VERSION, home_url(), (string) get_option( 'permalink_structure' ),
            $o['sitemap_dir'], $o['sitemap_index'], $o['sitemap_prefix'], $o['sitemap_limit'],
            $o['sitemap_pages'], $o['sitemap_categories'], $o['sitemap_images'], $o['sitemap_videos'],
            $seed,
        ) ) ) . '"';

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow' );
        header( 'Cache-Control: no-cache' );
        header( 'ETag: ' . $etag );

        $client = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
        if ( str_starts_with( $client, 'W/' ) ) $client = substr( $client, 2 ); // weak validator added by gzip
        if ( $client !== '' && $client === $etag ) {
            status_header( 304 );
            exit;
        }
        status_header( 200 );
    }

    /**
     * Escapes text for XML (titles in the database may contain HTML entities).
     */
    private static function x( $text ): string {
        return htmlspecialchars( Saiteki_Core::plain( $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    private static function w3c( ?string $gmt ): string {
        if ( ! $gmt || str_starts_with( $gmt, '0000' ) ) return '';
        return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $gmt . ' UTC' ) );
    }

    // -----------------------------------------------------------------
    // Index
    // -----------------------------------------------------------------

    public static function total_posts(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
    }

    public static function page_count( ?int $total = null ): int {
        if ( empty( self::$o ) ) self::$o = Saiteki_Core::get_options();
        return max( 1, (int) ceil( ( $total ?? self::total_posts() ) / self::limit() ) );
    }

    /**
     * File list of the index: last modification date of the pages file and of every post file.
     * Cached until content changes (at most 12 hours).
     */
    private static function index_data(): array {
        global $wpdb;
        $limit  = self::limit();
        $cached = get_transient( self::INDEX_CACHE );
        if ( is_array( $cached ) && isset( $cached['limit'], $cached['chunks'] ) && (int) $cached['limit'] === $limit ) {
            return $cached;
        }

        $pages = self::page_count();
        $data  = array( 'limit' => $limit, 'pages_mod' => '', 'chunks' => array() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $data['pages_mod'] = self::w3c( $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page'" ) );

        for ( $i = 1; $i <= $pages; $i++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $mod = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(post_modified_gmt) FROM (SELECT post_modified_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT %d OFFSET %d) AS chunk",
                $limit, ( $i - 1 ) * $limit
            ) );
            $data['chunks'][ $i ] = self::w3c( $mod );
        }

        set_transient( self::INDEX_CACHE, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    private static function serve_index() {
        $data = self::index_data();
        self::send_headers( 'index|' . md5( (string) wp_json_encode( $data ) ) );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        echo "<sitemap>\n<loc>" . esc_url( self::pages_url() ) . "</loc>\n";
        if ( $data['pages_mod'] !== '' ) echo '<lastmod>' . esc_html( $data['pages_mod'] ) . "</lastmod>\n";
        echo "</sitemap>\n";

        foreach ( $data['chunks'] as $i => $lastmod ) {
            echo "<sitemap>\n<loc>" . esc_url( self::chunk_url( (int) $i ) ) . "</loc>\n";
            if ( $lastmod !== '' ) echo '<lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
            echo "</sitemap>\n";
        }
        echo "</sitemapindex>\n";
        exit;
    }

    // -----------------------------------------------------------------
    // Pages file
    // -----------------------------------------------------------------

    /**
     * Home page, static pages and non-empty categories (tag archives are noindex and left out).
     */
    private static function serve_pages() {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= '<url><loc>' . esc_url( home_url( '/' ) ) . "</loc></url>\n";

        if ( self::$o['sitemap_pages'] === '1' ) {
            $front_id = (int) get_option( 'page_on_front' );
            $pages    = get_posts( array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 2000,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'has_password'   => false,
                'no_found_rows'  => true,
            ) );
            foreach ( $pages as $p ) {
                if ( $p->ID === $front_id ) continue; // already listed as home URL
                $xml .= '<url><loc>' . esc_url( get_permalink( $p ) ) . '</loc>';
                $lm   = self::w3c( $p->post_modified_gmt );
                if ( $lm !== '' ) $xml .= '<lastmod>' . esc_html( $lm ) . '</lastmod>';
                $xml .= "</url>\n";
            }
        }

        if ( self::$o['sitemap_categories'] === '1' ) {
            foreach ( get_categories( array( 'hide_empty' => true ) ) as $cat ) {
                $link = get_category_link( $cat->term_id );
                if ( is_string( $link ) && $link !== '' ) {
                    $xml .= '<url><loc>' . esc_url( $link ) . "</loc></url>\n";
                }
            }
        }

        $xml .= "</urlset>\n";

        self::send_headers( 'pages|' . md5( $xml ) );
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value was escaped while the XML was built
        exit;
    }

    // -----------------------------------------------------------------
    // Post files
    // -----------------------------------------------------------------

    private static function serve_chunk( int $page ) {
        global $wpdb;
        $limit  = self::limit();
        $offset = ( $page - 1 ) * $limit;

        // One cheap query describes the whole file: used for the 404 decision and the ETag.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stat = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS c, MAX(post_modified_gmt) AS m, MIN(ID) AS lo, MAX(ID) AS hi FROM (SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT %d OFFSET %d) AS chunk",
            $limit, $offset
        ) );
        $count = $stat ? (int) $stat->c : 0;
        if ( $count === 0 && $page > 1 ) {
            return; // file does not exist: let WordPress answer with its normal 404
        }

        self::send_headers( 'chunk|' . $page . '|' . $count . '|' . ( $stat->m ?? '' ) . '|' . ( $stat->lo ?? '' ) . '|' . ( $stat->hi ?? '' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT %d OFFSET %d",
            $limit, $offset
        ) ) );

        $with_images = self::$o['sitemap_images'] === '1';
        $with_videos = self::$o['sitemap_videos'] === '1';
        // Term caches are only needed when the permalink contains the category.
        $need_terms  = str_contains( (string) get_option( 'permalink_structure' ), '%category%' );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ( $with_images ) echo ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        if ( $with_videos ) echo ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
        echo ">\n";

        $batches = array_chunk( $ids, self::BATCH );
        $last    = count( $batches ) - 1;

        foreach ( $batches as $n => $batch ) {
            // Load posts, their meta and the featured images of the whole batch with a handful of
            // queries, instead of several queries per post.
            _prime_post_caches( $batch, $need_terms, true );

            $thumb_ids = array();
            foreach ( $batch as $id ) {
                $thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
                if ( $thumb > 0 ) $thumb_ids[] = $thumb;
            }
            if ( $thumb_ids ) _prime_post_caches( array_unique( $thumb_ids ), false, true );

            foreach ( $batch as $id ) {
                $p = get_post( $id );
                if ( ! $p instanceof WP_Post || $p->post_password !== '' ) continue;
                self::render_post( $p, $with_images, $with_videos );
            }

            // Keep memory flat for large files.
            if ( $n < $last && function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }
        }

        echo "</urlset>\n";
        exit;
    }

    private static function render_post( WP_Post $p, bool $with_images, bool $with_videos ) {
        $image_url = ( $with_images || $with_videos ) ? get_the_post_thumbnail_url( $p->ID, 'full' ) : false;
        $video_id  = '';
        if ( $with_videos ) {
            $video    = Saiteki_Core::video( $p->ID );
            $video_id = (string) $video['video_id'];
        }

        echo "<url>\n";
        echo '<loc>' . esc_url( get_permalink( $p ) ) . "</loc>\n";
        $lm = self::w3c( $p->post_modified_gmt );
        if ( $lm !== '' ) echo '<lastmod>' . esc_html( $lm ) . "</lastmod>\n";

        if ( $with_images && $image_url ) {
            echo "  <image:image>\n";
            echo '    <image:loc>' . esc_url( $image_url ) . "</image:loc>\n";
            echo "  </image:image>\n";
        }

        if ( $video_id !== '' ) {
            $desc = $p->post_excerpt !== '' ? $p->post_excerpt : $p->post_title;
            echo "  <video:video>\n";
            echo '    <video:thumbnail_loc>' . esc_url( $image_url ?: Saiteki_Core::youtube_thumb( $video_id ) ) . "</video:thumbnail_loc>\n";
            echo '    <video:title>' . self::x( $p->post_title ) . "</video:title>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '    <video:description>' . self::x( wp_html_excerpt( Saiteki_Core::plain( $desc ), 2000 ) ) . "</video:description>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '    <video:player_loc>' . esc_url( 'https://www.youtube.com/embed/' . rawurlencode( $video_id ) ) . "</video:player_loc>\n";
            echo "  </video:video>\n";
        }

        echo "</url>\n";
    }
}
