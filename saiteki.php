<?php
/**
 * Plugin Name:       Saiteki SEO
 * Plugin URI:        https://github.com/Suzu-chan1990/Saiteki-SEO/
 * Update URI:        https://github.com/Suzu-chan1990/Saiteki-SEO/
 * Description:       Fast and lightweight SEO plugin for video-focused WordPress sites with dynamic schema, XML sitemaps on a custom path, and optional instant indexing support.
 * Version:           2.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Author:            Saguya
 * Author URI:        https://github.com/Suzu-chan1990
 * Text Domain:       saiteki
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SAITEKI_VERSION', '2.0.0' );
define( 'SAITEKI_FILE', __FILE__ );
define( 'SAITEKI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAITEKI_URL', plugin_dir_url( __FILE__ ) );

/** Schema version of the settings table. */
const SAITEKI_DB_VERSION = 1;

/** Sitemap location used by every release before 2.0 (kept for existing installs). */
const SAITEKI_LEGACY_SITEMAP_DIR = 'appurodo/saito-mappu';

require_once SAITEKI_PATH . 'includes/class-saiteki-crypto.php';

// =================================================================
// 0. Settings storage
// =================================================================
// The custom table (wp_saiteki_settings) is the source of truth.
//
// "Warm" rows are copied into one autoloaded mirror option, so reading settings
// costs no extra query per request, even without a persistent object cache.
//
// "Cold" rows (the encrypted Google service-account keys and the rotation
// pointer) are large or change on every publish. They stay out of the mirror
// and are read straight from the table, only when they are actually needed.

/**
 * Keys that never enter the autoloaded mirror.
 */
function saiteki_cold_keys(): array {
    return array( 'saiteki_google_keys', 'saiteki_google_key_index' );
}

/**
 * Request-wide cache of the warm settings.
 *
 * @return array|null Reference to the cached settings (null = not loaded yet).
 */
function &saiteki_settings_cache() {
    static $cache = null;
    return $cache;
}

/**
 * Request-wide cache of cold rows that were already read.
 */
function &saiteki_cold_cache() {
    static $cache = array();
    return $cache;
}

function saiteki_table_ready(): bool {
    return (int) get_option( 'saiteki_db_version', 0 ) >= SAITEKI_DB_VERSION;
}

/**
 * Loads all warm rows from the settings table and refreshes the autoloaded mirror.
 */
function saiteki_load_settings_from_table(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'saiteki_settings';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results( "SELECT setting_key, setting_value FROM $table" );
    $cold = saiteki_cold_keys();
    $all  = array();
    foreach ( (array) $rows as $row ) {
        if ( in_array( $row->setting_key, $cold, true ) ) continue;
        $all[ $row->setting_key ] = maybe_unserialize( $row->setting_value );
    }
    update_option( 'saiteki_settings_mirror', $all, true );
    return $all;
}

function saiteki_get_setting( $key, $default = false ) {
    if ( ! saiteki_table_ready() ) {
        return get_option( $key, $default );
    }

    if ( in_array( $key, saiteki_cold_keys(), true ) ) {
        $cold = &saiteki_cold_cache();
        if ( ! array_key_exists( $key, $cold ) ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $value        = $wpdb->get_var( $wpdb->prepare( "SELECT setting_value FROM {$wpdb->prefix}saiteki_settings WHERE setting_key = %s", $key ) );
            $cold[ $key ] = null === $value ? null : maybe_unserialize( $value );
        }
        return null === $cold[ $key ] ? $default : $cold[ $key ];
    }

    $cache = &saiteki_settings_cache();
    if ( $cache === null ) {
        $mirror = get_option( 'saiteki_settings_mirror', null );
        $cache  = is_array( $mirror ) ? $mirror : saiteki_load_settings_from_table();
    }

    return array_key_exists( $key, $cache ) ? $cache[ $key ] : $default;
}

function saiteki_update_setting( $key, $value ) {
    global $wpdb;

    if ( ! saiteki_table_ready() ) {
        return update_option( $key, $value );
    }

    $wpdb->replace(
        $wpdb->prefix . 'saiteki_settings',
        array( 'setting_key' => $key, 'setting_value' => maybe_serialize( $value ) ),
        array( '%s', '%s' )
    );

    if ( in_array( $key, saiteki_cold_keys(), true ) ) {
        $cold         = &saiteki_cold_cache();
        $cold[ $key ] = $value;
        return true;
    }

    // Keep the request cache and the autoloaded mirror in sync.
    $cache = &saiteki_settings_cache();
    if ( ! is_array( $cache ) ) {
        $cache = saiteki_load_settings_from_table();
    } else {
        $cache[ $key ] = $value;
        update_option( 'saiteki_settings_mirror', $cache, true );
    }
    return true;
}

function saiteki_delete_setting( $key ) {
    global $wpdb;

    if ( ! saiteki_table_ready() ) {
        return delete_option( $key );
    }

    $wpdb->delete( $wpdb->prefix . 'saiteki_settings', array( 'setting_key' => $key ), array( '%s' ) );

    if ( in_array( $key, saiteki_cold_keys(), true ) ) {
        $cold         = &saiteki_cold_cache();
        $cold[ $key ] = null;
        return true;
    }

    $cache = &saiteki_settings_cache();
    if ( is_array( $cache ) && array_key_exists( $key, $cache ) ) {
        unset( $cache[ $key ] );
        update_option( 'saiteki_settings_mirror', $cache, true );
    }
    return true;
}

// =================================================================
// 1. Install / upgrade
// =================================================================
register_activation_hook( __FILE__, 'saiteki_install' );

// Updates via the GitHub updater do not run the activation hook: install on version change.
add_action( 'plugins_loaded', 'saiteki_maybe_upgrade', 5 );
function saiteki_maybe_upgrade() {
    if ( get_option( 'saiteki_version' ) === SAITEKI_VERSION ) return;
    saiteki_install();
}

/**
 * Creates the settings table and runs all pending migrations. Safe to run repeatedly.
 */
function saiteki_install() {
    global $wpdb;

    $from = (string) get_option( 'saiteki_version', '' );

    // Every release before 2.0 left at least one of these traces behind.
    $existing = $from !== ''
        || get_option( 'saiteki_db_version', false ) !== false
        || get_option( 'saiteki_settings', false ) !== false;

    $table_name      = $wpdb->prefix . 'saiteki_settings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_key (setting_key)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'saiteki_db_version', SAITEKI_DB_VERSION, true );

    $cache = &saiteki_settings_cache();
    $cache = saiteki_load_settings_from_table();

    if ( $from === '' || version_compare( $from, '2.0.0', '<' ) ) {
        saiteki_upgrade_to_2( $existing );
    }

    update_option( 'saiteki_version', SAITEKI_VERSION, true );
}

/**
 * 2.0.0 migration.
 *
 *  - copies settings that still live in wp_options (<= 1.1.x) into the table,
 *  - moves the Google keys out of the autoloaded mirror into their own cold row,
 *    repairs keys damaged by <= 1.2.0 and re-encrypts all secrets (AES-256-GCM),
 *  - pins the sitemap of existing installs to the path it always had.
 *
 * @param bool $existing True when an earlier release was installed before.
 */
function saiteki_upgrade_to_2( bool $existing ) {
    global $wpdb;
    require_once SAITEKI_PATH . 'includes/class-saiteki-indexing.php';

    $settings = saiteki_get_setting( 'saiteki_settings', null );
    if ( ! is_array( $settings ) ) {
        $legacy   = get_option( 'saiteki_settings', array() );
        $settings = is_array( $legacy ) ? $legacy : array();
    }

    // Rotation pointer: table row (1.2.x) or wp_options (<= 1.1.x). Read it directly,
    // because in 1.2.x it was a normal row and is a cold row from now on.
    $index = saiteki_get_setting( 'saiteki_google_key_index', null );
    if ( null === $index ) {
        $legacy_index = get_option( 'saiteki_google_key_index', false );
        if ( false !== $legacy_index ) {
            saiteki_update_setting( 'saiteki_google_key_index', (int) $legacy_index );
        }
    }

    // Google keys -> cold row.
    if ( ! empty( $settings['google_json_key'] ) && is_string( $settings['google_json_key'] ) ) {
        $stored = $settings['google_json_key'];
        $list   = Saiteki_Indexing::normalize_key_list( json_decode( Saiteki_Crypto::decrypt( $stored ), true ) );

        if ( ! empty( $list ) ) {
            foreach ( $list as &$k ) {
                if ( isset( $k['private_key'] ) && is_string( $k['private_key'] ) ) {
                    $k['private_key'] = Saiteki_Indexing::repair_private_key( $k['private_key'] );
                }
            }
            unset( $k );
            saiteki_update_setting( 'saiteki_google_keys', Saiteki_Crypto::encrypt( (string) wp_json_encode( $list ) ) );
            $settings['google_key_count'] = count( $list );
        } else {
            // Not readable right now (e.g. changed salts): keep the stored value untouched, nothing is thrown away.
            saiteki_update_setting( 'saiteki_google_keys', $stored );
            $settings['google_key_count'] = 0;
        }
    }
    unset( $settings['google_json_key'] );

    if ( ! empty( $settings['indexnow_key'] ) ) {
        $settings['indexnow_key'] = Saiteki_Crypto::upgrade( $settings['indexnow_key'] );
    }

    // Existing installs keep their sitemap URL. New installs start in the site root.
    if ( $existing && ! isset( $settings['sitemap_dir'] ) ) {
        $settings['sitemap_dir']    = SAITEKI_LEGACY_SITEMAP_DIR;
        $settings['sitemap_index']  = 'sitemap_index.xml';
        $settings['sitemap_prefix'] = 'sitemap';
    }

    saiteki_update_setting( 'saiteki_settings', $settings );

    // The key counter is part of the settings array now.
    saiteki_delete_setting( 'saiteki_google_key_count' );

    // The mirror may still hold the old Google keys and the pointer: rebuild it without cold rows.
    $cache = &saiteki_settings_cache();
    $cache = saiteki_load_settings_from_table();
}

// =================================================================
// 2. Core
// =================================================================
class Saiteki_Core {

    /** Switches that can be toggled from the admin screen. */
    const TOGGLES = array(
        'enable_sitemap_cleaner',
        'sitemap_pages',
        'sitemap_categories',
        'sitemap_images',
        'sitemap_videos',
        'sitemap_robots',
        'sitemap_redirect_old',
        'enable_api_indexing',
        'enable_dynamic_titles',
        'enable_twitter_cards',
        'enable_schema',
        'enable_hydro_bridge',
        'noindex_tags',
        'enable_health_thumbs',
        'enable_health_desc',
    );

    public static function defaults(): array {
        return array(
            // Sitemap
            'enable_sitemap_cleaner' => '1',
            'sitemap_dir'            => '',                  // directory below the site URL, '' = site root
            'sitemap_index'          => 'sitemap_index.xml', // file name of the sitemap index
            'sitemap_prefix'         => 'sitemap',           // {prefix}-pages.xml, {prefix}-1.xml, ...
            'sitemap_limit'          => 5000,                // posts per file
            'sitemap_pages'          => '1',
            'sitemap_categories'     => '1',
            'sitemap_images'         => '1',
            'sitemap_videos'         => '1',
            'sitemap_robots'         => '1',
            'sitemap_redirect_old'   => '1',
            'sitemap_prev_dir'       => '',
            'sitemap_prev_index'     => '',
            'sitemap_prev_prefix'    => '',
            // Frontend
            'enable_dynamic_titles'  => '1',
            'title_suffix'           => 'Video {year}',
            'enable_twitter_cards'   => '1',
            'enable_schema'          => '1',
            'noindex_tags'           => '1',
            // Indexing / integrations
            'enable_api_indexing'    => '1',
            'enable_hydro_bridge'    => '1',
            'indexnow_key'           => '',
            'google_key_count'       => 0,
            // Health audit (off by default: extra DB queries in the admin)
            'enable_health_thumbs'   => '0',
            'enable_health_desc'     => '0',
        );
    }

    public static function get_options(): array {
        $stored = saiteki_get_setting( 'saiteki_settings', array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
    }

    /**
     * Merges changed values into the stored settings and returns the full option set.
     */
    public static function update_options( array $changes ): array {
        $stored = saiteki_get_setting( 'saiteki_settings', array() );
        $stored = array_merge( is_array( $stored ) ? $stored : array(), $changes );
        saiteki_update_setting( 'saiteki_settings', $stored );
        return wp_parse_args( $stored, self::defaults() );
    }

    /**
     * Plain text for meta tags, JSON-LD and XML: decodes HTML entities that
     * get_the_title()/wptexturize add (e.g. &#038; &#8220;) and strips tags.
     */
    public static function plain( $text ): string {
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
    }

    /**
     * YouTube thumbnail URL. hqdefault exists for every video, maxresdefault does not.
     */
    public static function youtube_thumb( string $video_id ): string {
        return 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';
    }

    /**
     * Video data of a post: array( 'video_id' => string, 'views' => int, 'duration' => string ).
     *
     * Source order: theme helper jpop_get_turbo_video(), then the first YouTube URL in the
     * post content. Other sources can hook into the 'saiteki_video_data' filter.
     */
    public static function video( int $post_id ): array {
        $data = array( 'video_id' => '', 'views' => 0, 'duration' => '' );

        if ( function_exists( 'jpop_get_turbo_video' ) ) {
            $turbo = jpop_get_turbo_video( $post_id );
            if ( is_array( $turbo ) ) {
                $raw              = isset( $turbo['raw_meta'] ) && is_array( $turbo['raw_meta'] ) ? $turbo['raw_meta'] : array();
                $data['video_id'] = isset( $turbo['video_id'] ) ? (string) $turbo['video_id'] : '';
                $data['duration'] = isset( $raw['duration'][0] ) ? (string) $raw['duration'][0] : '';
                if ( isset( $raw['view_count'][0] ) ) {
                    $data['views'] = (int) $raw['view_count'][0];
                } elseif ( isset( $raw['_ayvpp_views'][0] ) ) {
                    $data['views'] = (int) $raw['_ayvpp_views'][0];
                }
            }
        }

        if ( $data['video_id'] === '' ) {
            $post = get_post( $post_id );
            if ( $post instanceof WP_Post && $post->post_password === ''
                && preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^"\'\s<>]*&(?:amp;)?)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $post->post_content, $m ) ) {
                $data['video_id'] = $m[1];
            }
        }

        $data = apply_filters( 'saiteki_video_data', $data, $post_id );
        return wp_parse_args( is_array( $data ) ? $data : array(), array( 'video_id' => '', 'views' => 0, 'duration' => '' ) );
    }

    public static function load_textdomain() {
        load_plugin_textdomain( 'saiteki', false, dirname( plugin_basename( SAITEKI_FILE ) ) . '/languages' );
    }

    public static function init() {
        add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );

        $options = self::get_options();

        if ( $options['enable_sitemap_cleaner'] === '1' ) {
            require_once SAITEKI_PATH . 'includes/class-saiteki-sitemap.php';
            Saiteki_Sitemap::init( $options );
        }

        if ( $options['enable_api_indexing'] === '1' ) {
            require_once SAITEKI_PATH . 'includes/class-saiteki-indexing.php';
            Saiteki_Indexing::init( $options );
        }

        if ( is_admin() ) {
            require_once SAITEKI_PATH . 'includes/class-saiteki-admin.php';
            Saiteki_Admin::init();
        } else {
            require_once SAITEKI_PATH . 'includes/class-saiteki-frontend.php';
            Saiteki_Frontend::init( $options );
        }
    }
}

add_action( 'plugins_loaded', array( 'Saiteki_Core', 'init' ) );

/**
 * GitHub update checker (public repository).
 *
 * Loaded only in the admin, during cron and in WP-CLI (update checks usually run via wp-cron),
 * so there is no frontend overhead.
 *
 * Runs on init, not on plugins_loaded: the library registers its cron event right away, and
 * wp_schedule_event() runs every 'cron_schedules' filter. Plugins that translate their schedule
 * names there (e.g. Tegatai Secure) would otherwise load their translations too early.
 */
add_action( 'init', function () {

    $should_load =
        is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI );

    if ( ! $should_load ) {
        return;
    }

    // Always load the bundled copy. Every class in the library is guarded, and its factory
    // hands out the newest registered version, so several plugins can ship their own copy.
    // Skipping this when another plugin already loaded the library would silently run
    // Saiteki on that plugin's (possibly older) version.
    $puc = SAITEKI_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc ) ) {
        return; // Library missing -> do nothing.
    }
    require_once $puc;

    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Saiteki-SEO/',
        SAITEKI_FILE,
        'saiteki' // must stay stable: it names the stored update state and the release folder
    );

    // Use release assets (ZIP) instead of the auto-generated "Source code.zip".
    $updateChecker->getVcsApi()->enableReleaseAssets();
}, 20 );
