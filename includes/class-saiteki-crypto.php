<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Saiteki_Crypto {
    private static function get_keys() {
        // Nutzt die sicheren wp-config.php Salts für Server-gebundene Verschlüsselung
        $key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
        return array( $key, $iv );
    }

    public static function encrypt( $data ) {
        if ( empty( $data ) ) return $data;
        if ( strpos( $data, 'SAITEKI_ENC:' ) === 0 ) return $data; // Bereits verschlüsselt
        
        list( $key, $iv ) = self::get_keys();
        $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
        return 'SAITEKI_ENC:' . base64_encode( $encrypted );
    }

    public static function decrypt( $data ) {
        if ( empty( $data ) || strpos( $data, 'SAITEKI_ENC:' ) !== 0 ) return $data;
        
        list( $key, $iv ) = self::get_keys();
        $raw_data = base64_decode( substr( $data, 12 ) ); // Entfernt 'SAITEKI_ENC:'
        return openssl_decrypt( $raw_data, 'AES-256-CBC', $key, 0, $iv );
    }
}
