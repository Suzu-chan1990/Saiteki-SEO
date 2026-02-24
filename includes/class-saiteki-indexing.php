<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Saiteki_Indexing {
    private static $options;

    public static function init( $options ) {
        self::$options = $options;
        
        // Auto-Generate verschlüsselten IndexNow Key
        if ( empty( self::$options['indexnow_key'] ) ) {
            $new_key = md5( uniqid( wp_rand(), true ) );
            self::$options['indexnow_key'] = Saiteki_Crypto::encrypt( $new_key );
            update_option( 'saiteki_settings', self::$options );
        }

        add_action( 'template_redirect', array( __CLASS__, 'serve_indexnow_txt' ), 1 );
        add_action( 'transition_post_status', array( __CLASS__, 'on_post_publish' ), 10, 3 );
    }

    public static function serve_indexnow_txt() {
        $key = Saiteki_Crypto::decrypt( self::$options['indexnow_key'] );
        if ( !empty($key) && isset( $_SERVER['REQUEST_URI'] ) && sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) === '/' . $key . '.txt' ) {
            header('Content-Type: text/plain');
            echo esc_html( $key );
            exit;
        }
    }

    public static function on_post_publish( $new_status, $old_status, $post ) {
        if ( $new_status === 'publish' && $old_status !== 'publish' && $post->post_type === 'post' ) {
            $url = get_permalink( $post->ID );
            self::ping_indexnow( $url );
            
            if ( !empty(self::$options['google_json_key']) ) {
                self::ping_google_api( $url );
            }
        }
    }

    private static function ping_indexnow( $url ) {
        $key = Saiteki_Crypto::decrypt( self::$options['indexnow_key'] );
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        
        $body = wp_json_encode( array(
            'host' => $host,
            'key'  => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList' => array( $url )
        ) );

        wp_remote_post( 'https://api.indexnow.org/indexnow', array(
            'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'    => $body,
            'blocking'=> false
        ) );
    }

    private static function ping_google_api( $url ) {
        $raw_json = Saiteki_Crypto::decrypt( self::$options['google_json_key'] );
        $json_data = json_decode( $raw_json, true );
        
        if ( !$json_data ) return;

        // Auto-Rotation für Multi-Keys
        if ( isset($json_data[0]) && is_array($json_data[0]) ) {
            $total_keys = count($json_data);
            $current_index = (int) get_option('saiteki_google_key_index', 0);
            $active_key = $json_data[ $current_index % $total_keys ];
            update_option('saiteki_google_key_index', $current_index + 1);
        } else {
            $active_key = $json_data;
        }

        if ( !isset($active_key['client_email']) || !isset($active_key['private_key']) ) return;

        $header = wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claim = wp_json_encode([
            'iss' => $active_key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlClaim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));
        $signatureInput = $base64UrlHeader . "." . $base64UrlClaim;

        $signature = '';
        openssl_sign($signatureInput, $signature, $active_key['private_key'], 'SHA256');
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwt = $signatureInput . "." . $base64UrlSignature;

        $token_response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt
            )
        ));

        if ( is_wp_error($token_response) ) return;
        
        $token_body = json_decode( wp_remote_retrieve_body($token_response), true );
        if ( !isset($token_body['access_token']) ) return;

        wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token_body['access_token']
            ),
            'body' => wp_json_encode( array( 'url' => $url, 'type'=> 'URL_UPDATED' ) ),
            'blocking' => false
        ));
    }
}
