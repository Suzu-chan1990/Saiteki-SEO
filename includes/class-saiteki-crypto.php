<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Encrypts API credentials before they are stored in the database.
 *
 * Format v3: "SAITEKI_ENC3:" + base64( 12-byte IV . 16-byte tag . AES-256-GCM ciphertext ), authenticated.
 * Format v2 (read only): "SAITEKI_ENC2:" + base64( 16-byte IV . AES-256-CBC ciphertext ).
 * Format v1 (read only): "SAITEKI_ENC:"  + base64( ciphertext ) with a fixed, salt-derived IV.
 * Keys are bound to this server via the wp-config.php salts.
 */
class Saiteki_Crypto {
    const PREFIX_V1 = 'SAITEKI_ENC:';
    const PREFIX_V2 = 'SAITEKI_ENC2:';
    const PREFIX_V3 = 'SAITEKI_ENC3:';
    const CIPHER_CBC = 'AES-256-CBC';
    const CIPHER_GCM = 'aes-256-gcm';

    /** Legacy key material (v1). */
    private static function get_keys_v1() {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
        return array( $key, $iv );
    }

    /** 256-bit raw key (v2 and v3). */
    private static function key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|saiteki', true );
    }

    public static function is_encrypted( $data ): bool {
        return is_string( $data ) && (
            str_starts_with( $data, self::PREFIX_V3 )
            || str_starts_with( $data, self::PREFIX_V2 )
            || str_starts_with( $data, self::PREFIX_V1 )
        );
    }

    public static function encrypt( $data ) {
        if ( empty( $data ) || ! is_string( $data ) ) return $data;
        if ( self::is_encrypted( $data ) ) return $data; // already encrypted

        $iv     = random_bytes( 12 );
        $tag    = '';
        $cipher = openssl_encrypt( $data, self::CIPHER_GCM, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( false === $cipher ) return '';
        return self::PREFIX_V3 . base64_encode( $iv . $tag . $cipher );
    }

    /**
     * @return string Plain text, or '' if the value cannot be decrypted (e.g. salts were changed).
     */
    public static function decrypt( $data ) {
        if ( empty( $data ) || ! is_string( $data ) ) return '';
        if ( ! self::is_encrypted( $data ) ) return $data;

        if ( str_starts_with( $data, self::PREFIX_V3 ) ) {
            $raw = base64_decode( substr( $data, strlen( self::PREFIX_V3 ) ), true );
            if ( false === $raw || strlen( $raw ) <= 28 ) return '';
            $plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER_GCM, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
            return false === $plain ? '' : $plain;
        }

        if ( str_starts_with( $data, self::PREFIX_V2 ) ) {
            $raw = base64_decode( substr( $data, strlen( self::PREFIX_V2 ) ), true );
            if ( false === $raw || strlen( $raw ) <= 16 ) return '';
            $plain = openssl_decrypt( substr( $raw, 16 ), self::CIPHER_CBC, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
            return false === $plain ? '' : $plain;
        }

        list( $key, $iv ) = self::get_keys_v1();
        $raw   = base64_decode( substr( $data, strlen( self::PREFIX_V1 ) ) );
        $plain = openssl_decrypt( $raw, self::CIPHER_CBC, $key, 0, $iv );
        return false === $plain ? '' : $plain;
    }

    /**
     * Re-encrypts a legacy v1/v2 value in the v3 format (returns the input unchanged otherwise,
     * or when it cannot be decrypted).
     */
    public static function upgrade( $data ) {
        if ( is_string( $data ) && ( str_starts_with( $data, self::PREFIX_V1 ) || str_starts_with( $data, self::PREFIX_V2 ) ) ) {
            $plain = self::decrypt( $data );
            return $plain === '' ? $data : self::encrypt( $plain );
        }
        return $data;
    }
}
