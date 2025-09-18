<?php

namespace NostrSigner;

use RuntimeException;

class Crypto {
    private const CIPHER = 'aes-256-cbc';
    private const IV_LENGTH = 16;

    public static function is_master_key_available(): bool {
        return defined( 'NOSTR_SIGNER_MASTER_KEY' ) && is_string( NOSTR_SIGNER_MASTER_KEY ) && NOSTR_SIGNER_MASTER_KEY !== '';
    }

    private static function get_encryption_key(): string {
        if ( ! self::is_master_key_available() ) {
            throw new RuntimeException( 'Nostr Signer master key is not defined.' );
        }

        return hash( 'sha256', NOSTR_SIGNER_MASTER_KEY, true );
    }

    public static function encrypt( string $plaintext ): string {
        $key = self::get_encryption_key();
        $iv  = openssl_random_pseudo_bytes( self::IV_LENGTH );

        if ( $iv === false || strlen( $iv ) !== self::IV_LENGTH ) {
            throw new RuntimeException( 'Unable to generate a secure IV for encryption.' );
        }

        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( $ciphertext === false ) {
            throw new RuntimeException( 'Encryption failed.' );
        }

        return base64_encode( $iv . $ciphertext );
    }

    public static function decrypt( string $ciphertext ) {
        $key = self::get_encryption_key();
        $data = base64_decode( $ciphertext, true );

        if ( $data === false ) {
            return false;
        }

        if ( strlen( $data ) <= self::IV_LENGTH ) {
            return false;
        }

        $iv         = substr( $data, 0, self::IV_LENGTH );
        $encrypted  = substr( $data, self::IV_LENGTH );
        $plaintext  = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return $plaintext !== false ? $plaintext : false;
    }
}