<?php
/**
 * Plugin Name:       Saiteki SEO
 * Plugin URI:        https://github.com/Suzu-chan1990/Saiteki-SEO-/
 * Description:       Fast and lightweight SEO plugin for video-focused WordPress sites with dynamic schema, XML sitemaps, and optional instant indexing support.
 * Version:           1.2.0
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

define( 'SAITEKI_VERSION', '1.2.0' );
define( 'SAITEKI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAITEKI_URL', plugin_dir_url( __FILE__ ) );

require_once SAITEKI_PATH . 'includes/class-saiteki-crypto.php';

// =================================================================
// 0. HIGH-SPEED DATENBANK & RAM CACHE
// =================================================================
function saiteki_get_setting( $key, $default = false ) {
    static $saiteki_cache = null;
    
    if ( $saiteki_cache === null ) {
        $cached = wp_cache_get( 'saiteki_all_settings', 'saiteki' );
        if ( $cached !== false ) {
            $saiteki_cache = $cached;
        } else {
            global $wpdb;
            $table_name = $wpdb->prefix . 'saiteki_settings';
            
            if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
                return get_option( $key, $default );
            }
            
            $results = $wpdb->get_results( "SELECT setting_key, setting_value FROM $table_name" );
            $saiteki_cache = [];
            foreach ( $results as $row ) {
                $saiteki_cache[ $row->setting_key ] = maybe_unserialize( $row->setting_value );
            }
            wp_cache_set( 'saiteki_all_settings', $saiteki_cache, 'saiteki', 3600 );
        }
    }
    
    if ( isset( $saiteki_cache[ $key ] ) ) {
        return $saiteki_cache[ $key ];
    }
    
    // Passive Migration Fallback
    $old_value = get_option( $key );
    if ( $old_value !== false ) {
        saiteki_update_setting( $key, $old_value );
        $saiteki_cache[ $key ] = $old_value;
        return $old_value;
    }
    
    return $default;
}

function saiteki_update_setting( $key, $value ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'saiteki_settings';
    
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
        return update_option( $key, $value );
    }
    
    $wpdb->replace( 
        $table_name, 
        [ 'setting_key' => $key, 'setting_value' => maybe_serialize( $value ) ], 
        [ '%s', '%s' ] 
    );
    
    wp_cache_delete( 'saiteki_all_settings', 'saiteki' );
    return true;
}

register_activation_hook( __FILE__, 'saiteki_activate_plugin' );
function saiteki_activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'saiteki_settings';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_key (setting_key)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

class Saiteki_Core {
    public static function get_options() {
        $defaults = array(
            'enable_sitemap_cleaner' => '1',
            'enable_api_indexing'    => '1',
            'enable_dynamic_titles'  => '1',
            'enable_twitter_cards'   => '1',
            'enable_schema'          => '1',
            'enable_hydro_bridge'    => '1',
            'indexnow_key'           => '',
            'google_json_key'        => '',
            'enable_health_thumbs'   => '0', // NEU: Standardmäßig AUS
            'enable_health_desc'     => '0', // NEU: Standardmäßig AUS
        );
        return wp_parse_args( saiteki_get_setting( 'saiteki_settings', array() ), $defaults );
    }

    

    public static function init() {
        $options = self::get_options();

        if ( $options['enable_sitemap_cleaner'] === '1' ) {
            require_once SAITEKI_PATH . 'includes/class-saiteki-sitemap.php';
            Saiteki_Sitemap::init();
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
 * GitHub Update Checker (Public GitHub Repo)
 *
 * Lädt nur im Admin und bei Cron (Update-Checks laufen oft via wp-cron).
 * -> Kein Frontend-Overhead.
 */
add_action( 'plugins_loaded', function () {

    $should_load =
        is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI );

    if ( ! $should_load ) {
        return;
    }

    if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
        $puc = __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
        if ( file_exists( $puc ) ) {
            require_once $puc;
        } else {
            // Library fehlt -> einfach nichts tun.
            return;
        }
    }

    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Saiteki-SEO/',
        __FILE__,
        'saiteki'
    );

    // Empfohlen: nutze Release Assets (ZIP) statt "Source code.zip"
    $updateChecker->getVcsApi()->enableReleaseAssets();


    // Optional (empfohlen): GitHub Token gegen 403/Ratelimits.
    // Token NICHT ins Repo hardcoden. Stattdessen in wp-config.php setzen:
    // define('SAITEKI_GITHUB_TOKEN', 'github_pat_...'); 
    if ( defined( 'SAITEKI_GITHUB_TOKEN' ) && SAITEKI_GITHUB_TOKEN ) {
        $updateChecker->setAuthentication( SAITEKI_GITHUB_TOKEN );
    }
}, 20 );

