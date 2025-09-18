<?php

namespace NostrSigner;

use RuntimeException;

class Crypto {
    private const CIPHER = 'aes-256-cbc';
    private const IV_LENGTH = 16;

    private static function ensure_key_length( string $key ): string {
        if ( strlen( $key ) !== 32 ) {
            throw new RuntimeException( 'Invalid encryption key length.' );
        }

        return $key;
    }

    public static function is_master_key_available(): bool {
        return defined( 'NOSTR_SIGNER_MASTER_KEY' ) && is_string( NOSTR_SIGNER_MASTER_KEY ) && NOSTR_SIGNER_MASTER_KEY !== '';
    }

    private static function get_encryption_key(): string {
        if ( ! self::is_master_key_available() ) {
            throw new RuntimeException( 'Nostr Signer master key is not defined.' );
        }

        return hash( 'sha256', NOSTR_SIGNER_MASTER_KEY, true );
    }

    private static function encrypt_with_binary_key( string $plaintext, string $key ): string {
        $key = self::ensure_key_length( $key );
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

    private static function decrypt_with_binary_key_internal( string $ciphertext, string $key ) {
        $key = self::ensure_key_length( $key );
        $data = base64_decode( $ciphertext, true );

        if ( $data === false ) {
            return false;
        }

        if ( strlen( $data ) <= self::IV_LENGTH ) {
            return false;
        }

        $iv        = substr( $data, 0, self::IV_LENGTH );
        $encrypted = substr( $data, self::IV_LENGTH );
        $plaintext = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return $plaintext !== false ? $plaintext : false;
    }

    public static function encrypt( string $plaintext ): string {
        $key = self::get_encryption_key();
        return self::encrypt_with_binary_key( $plaintext, $key );
    }

    public static function decrypt( string $ciphertext ) {
        $key = self::get_encryption_key();
        return self::decrypt_with_binary_key_internal( $ciphertext, $key );
    }

    public static function encrypt_with_custom_key( string $plaintext, string $key_binary ): string {
        return self::encrypt_with_binary_key( $plaintext, $key_binary );
    }

    public static function decrypt_with_custom_key( string $ciphertext, string $key_binary ) {
        return self::decrypt_with_binary_key_internal( $ciphertext, $key_binary );
    }

    /**
     * Re-encrypt all stored encrypted nsec values using provided old/new master keys.
     * Returns number of updated entries.
     *
     * @param string $old_master_hex Old master key (hex string)
     * @param string $new_master_hex New master key (hex string)
     * @return int
     */
    public static function recryptAll( string $old_master_hex, string $new_master_hex ): int {
        // derive binary keys (sha256 raw)
        $old_key_bin = hash( 'sha256', hex2bin( $old_master_hex ) ?: $old_master_hex, true );
        $new_key_bin = hash( 'sha256', hex2bin( $new_master_hex ) ?: $new_master_hex, true );

        if ( strlen( $old_key_bin ) !== 32 || strlen( $new_key_bin ) !== 32 ) {
            throw new \RuntimeException( 'Invalid master key length for recrypt.' );
        }

        $updated = 0;

        // Blog key
        $blog_enc = get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC );
        if ( is_string( $blog_enc ) && $blog_enc !== '' ) {
            $plain = self::decrypt_with_binary_key_internal( $blog_enc, $old_key_bin );
            if ( $plain !== false ) {
                $new_enc = self::encrypt_with_binary_key( $plain, $new_key_bin );
                update_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC, $new_enc );
                $updated++;
            }
        }

        // User keys
        $users = get_users( [ 'fields' => [ 'ID' ] ] );
        foreach ( $users as $u ) {
            $enc = get_user_meta( $u->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            if ( is_string( $enc ) && $enc !== '' ) {
                $plain = self::decrypt_with_binary_key_internal( $enc, $old_key_bin );
                if ( $plain !== false ) {
                    $new_enc = self::encrypt_with_binary_key( $plain, $new_key_bin );
                    update_user_meta( $u->ID, KeyManager::META_ENCRYPTED_NSEC, $new_enc );
                    $updated++;
                }
            }
        }

        return $updated;
    }
}
