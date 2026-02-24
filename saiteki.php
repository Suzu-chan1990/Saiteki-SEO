<?php
/**
 * Plugin Name:       Saiteki SEO
 * Plugin URI:        https://github.com/Suzu-chan1990/Saiteki-SEO-/
 * Description:       Fast and lightweight SEO plugin for video-focused WordPress sites with dynamic schema, XML sitemaps, and optional instant indexing support.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            すずちゃん
 * Author URI:        https://github.com/Suzu-chan1990
 * Text Domain:       saiteki
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SAITEKI_VERSION', '1.1.1' );
define( 'SAITEKI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAITEKI_URL', plugin_dir_url( __FILE__ ) );

require_once SAITEKI_PATH . 'includes/class-saiteki-crypto.php';

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
        return wp_parse_args( get_option( 'saiteki_settings', array() ), $defaults );
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

