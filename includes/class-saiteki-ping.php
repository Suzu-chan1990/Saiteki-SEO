<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Saiteki_Ping {
    public static function init() {
        // Feuert nur, wenn ein Beitrag VERÖFFENTLICHT wird
        add_action( 'transition_post_status', array( __CLASS__, 'ping_google' ), 10, 3 );
    }

    public static function ping_google( $new_status, $old_status, $post ) {
        // Nur wenn Status auf "publish" wechselt und es ein normaler Post (Video) ist
        if ( 'publish' === $new_status && 'publish' !== $old_status && 'post' === $post->post_type ) {
            $sitemap_url = home_url( '/wp-sitemap.xml' );
            $ping_url = 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url );
            // Non-blocking Request (Server wartet NICHT auf Googles Antwort, Import läuft schnell weiter)
            wp_remote_get( $ping_url, array( 'blocking' => false, 'timeout' => 2 ) );
        }
    }
}
