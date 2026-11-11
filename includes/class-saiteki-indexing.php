<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Instant indexing: IndexNow and the Google Indexing API (multi-key rotation).
 */
class Saiteki_Indexing {
    /** URLs per IndexNow request while a long-running process (bulk import) keeps publishing. */
    const INDEXNOW_FLUSH_AT = 500;

    private static $options = array();

    /** URLs published during this request, sent to IndexNow in one batch. */
    private static $indexnow_queue = array();

    public static function init( $options ) {
        self::$options = $options;

        // Auto-generate an (encrypted) IndexNow key on first run, or when the stored one
        // can no longer be decrypted (e.g. the wp-config.php salts were changed).
        if ( self::indexnow_key() === '' ) {
            self::regenerate_indexnow_key();
        }

        // parse_request runs before the main query: no 404 query for the key file.
        add_action( 'parse_request', array( __CLASS__, 'serve_indexnow_txt' ), 1 );
        add_action( 'transition_post_status', array( __CLASS__, 'on_post_publish' ), 10, 3 );
    }

    public static function indexnow_key(): string {
        if ( empty( self::$options ) ) self::$options = Saiteki_Core::get_options();
        return (string) Saiteki_Crypto::decrypt( self::$options['indexnow_key'] ?? '' );
    }

    public static function regenerate_indexnow_key(): string {
        $new_key       = bin2hex( random_bytes( 16 ) );
        self::$options = Saiteki_Core::update_options( array( 'indexnow_key' => Saiteki_Crypto::encrypt( $new_key ) ) );
        return $new_key;
    }

    public static function indexnow_key_url(): string {
        $key = self::indexnow_key();
        return $key === '' ? '' : home_url( '/' . $key . '.txt' );
    }

    /**
     * Serves /{key}.txt so IndexNow can verify ownership.
     */
    public static function serve_indexnow_txt() {
        $key = self::indexnow_key();
        if ( $key === '' || ! isset( $_SERVER['REQUEST_URI'] ) ) return;

        $path     = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
        $expected = (string) wp_parse_url( home_url( '/' . $key . '.txt' ), PHP_URL_PATH );
        if ( $path === $expected ) {
            status_header( 200 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            echo esc_html( $key );
            exit;
        }
    }

    public static function on_post_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' || $post->post_type !== 'post' ) return;
        if ( $post->post_password !== '' ) return; // nothing to index behind a password

        $url = get_permalink( $post->ID );
        if ( ! $url || ! apply_filters( 'saiteki_should_index', true, $post, $url ) ) return;

        self::queue_indexnow( $url );

        if ( (int) ( self::$options['google_key_count'] ?? 0 ) > 0 ) {
            self::ping_google_api( $url );
        }
    }

    /**
     * Collects a published URL for IndexNow.
     *
     * IndexNow answers with HTTP 429 when a site sends many single-URL requests in a row
     * (bulk imports). All URLs published in one request or CLI run are therefore sent together:
     * at shutdown, or earlier whenever INDEXNOW_FLUSH_AT URLs have piled up.
     */
    private static function queue_indexnow( string $url ) {
        if ( empty( self::$indexnow_queue ) && ! has_action( 'shutdown', array( __CLASS__, 'flush_indexnow' ) ) ) {
            add_action( 'shutdown', array( __CLASS__, 'flush_indexnow' ), 5 );
        }
        self::$indexnow_queue[ $url ] = true;

        if ( count( self::$indexnow_queue ) >= self::INDEXNOW_FLUSH_AT ) {
            self::flush_indexnow();
        }
    }

    public static function flush_indexnow() {
        if ( empty( self::$indexnow_queue ) ) return;
        $urls                 = array_keys( self::$indexnow_queue );
        self::$indexnow_queue = array();
        self::ping_indexnow( $urls );
    }

    /**
     * Sends one or more URLs to IndexNow (up to 10,000 per request).
     *
     * @param string|string[] $urls
     * @return array|WP_Error|null Response when $blocking is true.
     */
    public static function ping_indexnow( $urls, bool $blocking = false ) {
        $key  = self::indexnow_key();
        $urls = array_values( array_filter( (array) $urls ) );
        if ( $key === '' || empty( $urls ) ) return null;
        $host = wp_parse_url( home_url(), PHP_URL_HOST );

        $response = null;
        foreach ( array_chunk( $urls, 10000 ) as $chunk ) {
            $response = wp_remote_post( 'https://api.indexnow.org/indexnow', array(
                'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'     => wp_json_encode( array(
                    'host'        => $host,
                    'key'         => $key,
                    'keyLocation' => home_url( '/' . $key . '.txt' ),
                    'urlList'     => $chunk,
                ) ),
                'blocking' => $blocking,
                'timeout'  => $blocking ? 10 : 5,
            ) );
        }
        return $response;
    }

    /**
     * Turns decoded JSON into a list of service-account arrays (accepts a single key or a list).
     */
    public static function normalize_key_list( $json ): array {
        if ( ! is_array( $json ) || empty( $json ) ) return array();
        $list = isset( $json[0] ) && is_array( $json[0] ) ? $json : array( $json );
        return array_values( array_filter( $list, 'is_array' ) );
    }

    /**
     * Returns the decoded service-account key list (always a list, even for a single key).
     */
    public static function get_google_keys(): array {
        $stored = saiteki_get_setting( 'saiteki_google_keys', '' );
        if ( ! is_string( $stored ) || $stored === '' ) return array();
        return self::normalize_key_list( json_decode( Saiteki_Crypto::decrypt( $stored ), true ) );
    }

    /**
     * True when keys are stored but cannot be decrypted any more (salts changed).
     */
    public static function google_keys_unreadable(): bool {
        $stored = saiteki_get_setting( 'saiteki_google_keys', '' );
        return is_string( $stored ) && $stored !== '' && empty( self::get_google_keys() );
    }

    /**
     * Stores the key list encrypted and updates the key counter.
     */
    public static function save_google_keys( array $keys, bool $reset_rotation = true ): void {
        $keys = array_values( $keys );
        if ( empty( $keys ) ) {
            saiteki_delete_setting( 'saiteki_google_keys' );
            saiteki_delete_setting( 'saiteki_google_key_index' );
        } else {
            saiteki_update_setting( 'saiteki_google_keys', Saiteki_Crypto::encrypt( (string) wp_json_encode( $keys ) ) );
            if ( $reset_rotation ) {
                saiteki_update_setting( 'saiteki_google_key_index', 0 );
            }
        }
        self::$options = Saiteki_Core::update_options( array( 'google_key_count' => count( $keys ) ) );
    }

    /**
     * Parses pasted service-account JSON. Several files may be pasted one after another.
     *
     * @return array{0: array, 1: int} Valid keys and the number of skipped entries.
     */
    public static function parse_google_input( string $input ): array {
        $input = trim( $input );
        if ( $input === '' ) return array( array(), 0 );

        $fixed  = '[' . preg_replace( '/\}\s*,?\s*\{/', '},{', trim( $input, "[] \t\n\r\0\x0B," ) ) . ']';
        $parsed = json_decode( $fixed, true );
        if ( ! is_array( $parsed ) ) return array( array(), 1 );

        $valid = array();
        foreach ( $parsed as $sa ) {
            if ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] )
                && is_string( $sa['private_key'] ) && openssl_pkey_get_private( $sa['private_key'] ) ) {
                $valid[ strtolower( (string) $sa['client_email'] ) ] = $sa; // the same account pasted twice counts once
            }
        }
        return array( array_values( $valid ), count( $parsed ) - count( $valid ) );
    }

    private static function ping_google_api( $url ) {
        $keys = self::get_google_keys();
        if ( empty( $keys ) ) return;

        // Multi-key rotation: one key per publish, round robin.
        $total         = count( $keys );
        $current_index = (int) saiteki_get_setting( 'saiteki_google_key_index', 0 );
        $active_key    = $keys[ $current_index % $total ];
        if ( $total > 1 ) {
            saiteki_update_setting( 'saiteki_google_key_index', $current_index + 1 );
        }

        if ( empty( $active_key['client_email'] ) || empty( $active_key['private_key'] ) ) return;

        $token = self::get_access_token( $active_key );
        if ( $token === '' ) return;

        wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', array(
            'headers'  => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'     => wp_json_encode( array( 'url' => $url, 'type' => 'URL_UPDATED' ) ),
            'blocking' => false,
        ) );
    }

    /**
     * Checks that a stored key can still obtain an access token. Uses no indexing quota.
     *
     * @return true|WP_Error
     */
    public static function test_google_key( int $position ) {
        $keys = self::get_google_keys();
        if ( ! isset( $keys[ $position ] ) ) {
            return new WP_Error( 'saiteki_no_key', __( 'This key does not exist any more.', 'saiteki' ) );
        }
        $error = '';
        $token = self::get_access_token( $keys[ $position ], true, $error );
        if ( $token === '' ) {
            return new WP_Error( 'saiteki_token', $error !== '' ? $error : __( 'Google did not return an access token.', 'saiteki' ) );
        }
        return true;
    }

    /**
     * OAuth access token for a service account. Tokens are valid for 60 minutes and are
     * cached (encrypted) for 55, so only the first publish per hour and key waits for Google.
     */
    private static function get_access_token( array $sa, bool $fresh = false, string &$error = '' ): string {
        $cache_key = 'saiteki_gtoken_' . md5( (string) $sa['client_email'] );
        if ( ! $fresh ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && $cached !== '' ) {
                $token = Saiteki_Crypto::decrypt( $cached );
                if ( $token !== '' ) return $token;
            }
        }

        $b64url = static fn( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
        $now    = time();
        $input  = $b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . $b64url( wp_json_encode( array(
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ) ) );

        $signature = '';
        if ( ! openssl_sign( $input, $signature, (string) $sa['private_key'], 'SHA256' ) ) {
            $error = __( 'The private key cannot be used for signing.', 'saiteki' );
            return '';
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 8,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $input . '.' . $b64url( $signature ),
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $error = is_array( $body ) && ! empty( $body['error_description'] )
                ? (string) $body['error_description']
                : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
            return '';
        }

        $ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
        set_transient( $cache_key, Saiteki_Crypto::encrypt( (string) $body['access_token'] ), $ttl );
        return (string) $body['access_token'];
    }

    /**
     * Repairs private keys that lost their "\n" escapes in versions <= 1.2.0
     * ("-----BEGIN PRIVATE KEY-----nMIIE...n-----END PRIVATE KEY-----n").
     * PEM bodies are split into 64-character lines, so every 65th character is the lost newline.
     */
    public static function repair_private_key( string $pem ): string {
        if ( str_contains( $pem, "\n" ) ) return $pem;
        if ( ! preg_match( '/^-----BEGIN ([A-Z ]+)-----n(.+)n-----END \1-----n?$/s', $pem, $m ) ) return $pem;

        $body  = $m[2];
        $lines = array();
        while ( strlen( $body ) > 64 ) {
            if ( $body[64] !== 'n' ) return $pem; // not the expected layout, leave untouched
            $lines[] = substr( $body, 0, 64 );
            $body    = substr( $body, 65 );
        }
        $lines[] = $body;

        return "-----BEGIN {$m[1]}-----\n" . implode( "\n", $lines ) . "\n-----END {$m[1]}-----\n";
    }
}
