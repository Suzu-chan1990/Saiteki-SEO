<?php
/**
 * Saiteki SEO – Uninstall
 *
 * Removes the settings table, all saiteki_* options, cached Google tokens
 * and the remembered Hydro slugs.
 *
 * @package Saiteki_SEO
 * @since   1.2.1
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

function saiteki_uninstall_site(): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}saiteki_settings`" );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'saiteki\\_%'
            OR option_name LIKE '\\_transient\\_saiteki\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_saiteki\\_%'"
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_saiteki_hydro_slug'" );

    wp_cache_flush();
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $saiteki_site_id ) {
        switch_to_blog( (int) $saiteki_site_id );
        saiteki_uninstall_site();
        restore_current_blog();
    }
} else {
    saiteki_uninstall_site();
}
