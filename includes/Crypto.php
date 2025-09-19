<?php

namespace NostrSigner;

use JsonException;
use RuntimeException;

class Crypto {
    private const DATA_CIPHER = 'aes-256-gcm';
    private const WRAP_CIPHER = 'aes-256-gcm';
    private const DATA_IV_LENGTH = 12;
    private const WRAP_IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const ENVELOPE_VERSION = 1;

    private const LEGACY_CIPHER = 'aes-256-cbc';
    private const LEGACY_IV_LENGTH = 16;

    private static function ensure_key_length( string $key ): string {
        if ( strlen( $key ) !== 32 ) {
            throw new RuntimeException( 'Ungueltige Schluessellaenge fuer AES-256.' );
        }

        return $key;
    }

    public static function is_master_key_available(): bool {
        if ( ! defined( 'NOSTR_SIGNER_MASTER_KEY' ) || ! is_string( NOSTR_SIGNER_MASTER_KEY ) || NOSTR_SIGNER_MASTER_KEY === '' ) {
            return false;
        }

        try {
            $active = self::get_active_key_version();
            self::get_kek_by_version( $active );
            return true;
        } catch ( RuntimeException $exception ) {
            return false;
        }
    }

    public static function encrypt( string $plaintext ): string {
        $version = self::get_active_key_version();
        $kek     = self::get_kek_by_version( $version );

        return self::encrypt_with_explicit_kek( $plaintext, $kek, $version );
    }

    public static function decrypt( string $ciphertext ) {
        $result = self::decrypt_envelope( $ciphertext );
        if ( $result !== null ) {
            return $result;
        }

        return self::decrypt_legacy( $ciphertext );
    }

    public static function encrypt_with_custom_key( string $plaintext, string $key_binary ): string {
        return self::encrypt_with_binary_key_legacy( $plaintext, $key_binary );
    }

    public static function decrypt_with_custom_key( string $ciphertext, string $key_binary ) {
        return self::decrypt_with_binary_key_legacy( $ciphertext, $key_binary );
    }

    public static function recryptAll( string $old_master_hex, string $new_master_hex ): int {
        $old_key_bin = self::derive_binary_from_user_secret( $old_master_hex );
        $new_key_bin = self::derive_binary_from_user_secret( $new_master_hex );

        $updated = 0;

        $blog_enc = get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC );
        if ( is_string( $blog_enc ) && $blog_enc !== '' ) {
            $updated += self::recrypt_value( $blog_enc, $old_key_bin, $new_key_bin, 'option', KeyManager::OPTION_BLOG_ENCRYPTED_NSEC );
        }

        $users = get_users( [ 'fields' => [ 'ID' ] ] );
        foreach ( $users as $user ) {
            $enc = get_user_meta( $user->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            if ( is_string( $enc ) && $enc !== '' ) {
                $result = self::recrypt_value( $enc, $old_key_bin, $new_key_bin, 'user', (string) $user->ID );
                $updated += $result;
            }
        }

        return $updated;
    }

    private static function recrypt_value( string $ciphertext, string $old_kek, string $new_kek, string $type, string $target ): int {
        $plain = self::decrypt_with_binary_key_legacy( $ciphertext, $old_kek );
        if ( is_string( $plain ) ) {
            $rewrapped = self::encrypt_with_explicit_kek( $plain, $new_kek, 1 );
            self::store_recrypted_value( $rewrapped, $type, $target );
            unset( $plain );
            return 1;
        }

        $rewrapped = self::rewrap_envelope_with_custom_keys( $ciphertext, $old_kek, $new_kek, 1 );
        if ( $rewrapped !== null ) {
            self::store_recrypted_value( $rewrapped, $type, $target );
            return 1;
        }

        return 0;
    }

    private static function store_recrypted_value( string $value, string $type, string $target ): void {
        if ( $type === 'option' ) {
            update_option( $target, $value );
            return;
        }

        update_user_meta( (int) $target, KeyManager::META_ENCRYPTED_NSEC, $value );
    }

    private static function decrypt_envelope( string $ciphertext ) {
        $json = base64_decode( $ciphertext, true );
        if ( $json === false ) {
            return null;
        }

        try {
            $envelope = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            return null;
        }

        if ( ! is_array( $envelope ) ) {
            return null;
        }

        $required = [ 'v', 'kv', 'di', 'dt', 'ct', 'wi', 'wt', 'wk' ];
        foreach ( $required as $field ) {
            if ( ! array_key_exists( $field, $envelope ) ) {
                return null;
            }
        }

        if ( (int) $envelope['v'] !== self::ENVELOPE_VERSION ) {
            return false;
        }

        $key_version = (int) $envelope['kv'];

        try {
            $kek = self::get_kek_by_version( $key_version );
        } catch ( RuntimeException $exception ) {
            return false;
        }

        $wrap_iv  = base64_decode( (string) $envelope['wi'], true );
        $wrap_tag = base64_decode( (string) $envelope['wt'], true );
        $wrapped  = base64_decode( (string) $envelope['wk'], true );

        if ( $wrap_iv === false || $wrap_tag === false || $wrapped === false ) {
            return false;
        }

        $dek = openssl_decrypt(
            $wrapped,
            self::WRAP_CIPHER,
            $kek,
            OPENSSL_RAW_DATA,
            $wrap_iv,
            $wrap_tag
        );

        if ( $dek === false || strlen( $dek ) !== 32 ) {
            return false;
        }

        $data_iv  = base64_decode( (string) $envelope['di'], true );
        $data_tag = base64_decode( (string) $envelope['dt'], true );
        $ct       = base64_decode( (string) $envelope['ct'], true );

        if ( $data_iv === false || $data_tag === false || $ct === false ) {
            unset( $dek );
            return false;
        }

        $plaintext = openssl_decrypt(
            $ct,
            self::DATA_CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            $data_iv,
            $data_tag
        );

        unset( $dek );

        return $plaintext !== false ? $plaintext : false;
    }

    private static function decrypt_legacy( string $ciphertext ) {
        if ( ! defined( 'NOSTR_SIGNER_MASTER_KEY' ) || ! is_string( NOSTR_SIGNER_MASTER_KEY ) || NOSTR_SIGNER_MASTER_KEY === '' ) {
            return false;
        }

        $key = hash( 'sha256', NOSTR_SIGNER_MASTER_KEY, true );
        return self::decrypt_with_binary_key_legacy( $ciphertext, $key );
    }

    private static function encrypt_with_explicit_kek( string $plaintext, string $kek, int $version ): string {
        $dek = random_bytes( 32 );

        $data_iv  = random_bytes( self::DATA_IV_LENGTH );
        $data_tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::DATA_CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            $data_iv,
            $data_tag,
            '',
            self::TAG_LENGTH
        );

        if ( $ciphertext === false || strlen( $data_tag ) !== self::TAG_LENGTH ) {
            throw new RuntimeException( 'Datenverschluesselung fehlgeschlagen.' );
        }

        $wrap_iv  = random_bytes( self::WRAP_IV_LENGTH );
        $wrap_tag = '';
        $wrapped_dek = openssl_encrypt(
            $dek,
            self::WRAP_CIPHER,
            $kek,
            OPENSSL_RAW_DATA,
            $wrap_iv,
            $wrap_tag,
            '',
            self::TAG_LENGTH
        );

        if ( $wrapped_dek === false || strlen( $wrap_tag ) !== self::TAG_LENGTH ) {
            throw new RuntimeException( 'Wrapping des Daten-Schluessels fehlgeschlagen.' );
        }

        $envelope = [
            'v'  => self::ENVELOPE_VERSION,
            'kv' => $version,
            'di' => base64_encode( $data_iv ),
            'dt' => base64_encode( $data_tag ),
            'ct' => base64_encode( $ciphertext ),
            'wi' => base64_encode( $wrap_iv ),
            'wt' => base64_encode( $wrap_tag ),
            'wk' => base64_encode( $wrapped_dek ),
        ];

        try {
            $json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            unset( $dek );
            throw new RuntimeException( 'Envelope konnte nicht serialisiert werden.' );
        }

        unset( $dek );

        return base64_encode( $json );
    }

    private static function rewrap_envelope_with_custom_keys( string $ciphertext, string $old_kek, string $new_kek, int $new_version ): ?string {
        $json = base64_decode( $ciphertext, true );
        if ( $json === false ) {
            return null;
        }

        try {
            $envelope = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            return null;
        }

        if ( ! is_array( $envelope ) || ! isset( $envelope['wi'], $envelope['wt'], $envelope['wk'], $envelope['di'], $envelope['dt'], $envelope['ct'] ) ) {
            return null;
        }

        $wrap_iv  = base64_decode( (string) $envelope['wi'], true );
        $wrap_tag = base64_decode( (string) $envelope['wt'], true );
        $wrapped  = base64_decode( (string) $envelope['wk'], true );

        if ( $wrap_iv === false || $wrap_tag === false || $wrapped === false ) {
            return null;
        }

        $dek = openssl_decrypt(
            $wrapped,
            self::WRAP_CIPHER,
            $old_kek,
            OPENSSL_RAW_DATA,
            $wrap_iv,
            $wrap_tag
        );

        if ( $dek === false || strlen( $dek ) !== 32 ) {
            return null;
        }

        $wrap_iv_new  = random_bytes( self::WRAP_IV_LENGTH );
        $wrap_tag_new = '';
        $wrapped_new = openssl_encrypt(
            $dek,
            self::WRAP_CIPHER,
            $new_kek,
            OPENSSL_RAW_DATA,
            $wrap_iv_new,
            $wrap_tag_new,
            '',
            self::TAG_LENGTH
        );

        if ( $wrapped_new === false || strlen( $wrap_tag_new ) !== self::TAG_LENGTH ) {
            unset( $dek );
            return null;
        }

        $envelope['kv'] = $new_version;
        $envelope['wi'] = base64_encode( $wrap_iv_new );
        $envelope['wt'] = base64_encode( $wrap_tag_new );
        $envelope['wk'] = base64_encode( $wrapped_new );

        try {
            $json_new = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            unset( $dek );
            return null;
        }

        unset( $dek );

        return base64_encode( $json_new );
    }

    private static function get_active_key_version(): int {
        $raw = defined( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION' ) ? constant( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION' ) : 1;

        if ( ! is_numeric( $raw ) ) {
            throw new RuntimeException( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION ist ungueltig.' );
        }

        $version = (int) $raw;
        if ( $version < 1 ) {
            throw new RuntimeException( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION muss >= 1 sein.' );
        }

        return $version;
    }

    private static function get_kek_by_version( int $version ): string {
        if ( $version < 1 ) {
            throw new RuntimeException( 'Key-Version muss >= 1 sein.' );
        }

        $material = self::lookup_key_material( $version );
        if ( $material === null ) {
            throw new RuntimeException( sprintf( 'Kein Key-Material fuer Version %d gefunden.', $version ) );
        }

        if ( $material['legacy'] ) {
            return hash( 'sha256', $material['value'], true );
        }

        return self::decode_key_material( $material['value'] );
    }

    private static function lookup_key_material( int $version ): ?array {
        $candidates = [
            'NOSTR_SIGNER_KEY_V' . $version,
            'APP_KEY_V' . $version,
        ];

        foreach ( $candidates as $name ) {
            $env = getenv( $name );
            if ( is_string( $env ) && $env !== '' ) {
                return [ 'value' => $env, 'legacy' => false ];
            }

            if ( defined( $name ) ) {
                $value = constant( $name );
                if ( is_string( $value ) && $value !== '' ) {
                    return [ 'value' => $value, 'legacy' => false ];
                }
            }
        }

        if ( $version === 1 && defined( 'NOSTR_SIGNER_MASTER_KEY' ) && is_string( NOSTR_SIGNER_MASTER_KEY ) && NOSTR_SIGNER_MASTER_KEY !== '' ) {
            return [ 'value' => NOSTR_SIGNER_MASTER_KEY, 'legacy' => true ];
        }

        return null;
    }

    private static function decode_key_material( string $value ): string {
        $trimmed = trim( $value );
        if ( $trimmed === '' ) {
            throw new RuntimeException( 'Key-Material darf nicht leer sein.' );
        }

        if ( strpos( $trimmed, 'base64:' ) === 0 ) {
            $trimmed = substr( $trimmed, 7 );
        }

        $decoded = base64_decode( $trimmed, true );
        if ( $decoded !== false ) {
            if ( strlen( $decoded ) !== 32 ) {
                throw new RuntimeException( 'Base64-Key muss exakt 32 Byte liefern.' );
            }
            return $decoded;
        }

        if ( preg_match( '/^([0-9a-f]{2}){32}$/i', $trimmed ) === 1 ) {
            $binary = hex2bin( $trimmed );
            if ( $binary === false ) {
                throw new RuntimeException( 'Hex-Key konnte nicht dekodiert werden.' );
            }
            return $binary;
        }

        if ( strlen( $trimmed ) === 32 ) {
            return $trimmed;
        }

        throw new RuntimeException( 'Key-Material muss 32 Byte (Base64, Hex oder Raw) liefern.' );
    }

    private static function encrypt_with_binary_key_legacy( string $plaintext, string $key ): string {
        $key = self::ensure_key_length( $key );
        $iv  = random_bytes( self::LEGACY_IV_LENGTH );

        $ciphertext = openssl_encrypt( $plaintext, self::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( $ciphertext === false ) {
            throw new RuntimeException( 'Legacy-Verschluesselung fehlgeschlagen.' );
        }

        return base64_encode( $iv . $ciphertext );
    }

    private static function decrypt_with_binary_key_legacy( string $ciphertext, string $key ) {
        $key = self::ensure_key_length( $key );
        $data = base64_decode( $ciphertext, true );

        if ( $data === false ) {
            return false;
        }

        if ( strlen( $data ) <= self::LEGACY_IV_LENGTH ) {
            return false;
        }

        $iv        = substr( $data, 0, self::LEGACY_IV_LENGTH );
        $encrypted = substr( $data, self::LEGACY_IV_LENGTH );
        $plaintext = openssl_decrypt( $encrypted, self::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return $plaintext !== false ? $plaintext : false;
    }

    private static function derive_binary_from_user_secret( string $secret ): string {
        $trimmed = trim( $secret );
        if ( $trimmed === '' ) {
            throw new RuntimeException( 'Leere Geheimnisse sind unzulaessig.' );
        }

        if ( preg_match( '/^([0-9a-f]{2}){32}$/i', $trimmed ) === 1 ) {
            $bin = hex2bin( $trimmed );
            if ( $bin === false ) {
                throw new RuntimeException( 'Hex-Secret konnte nicht dekodiert werden.' );
            }
            return $bin;
        }

        if ( strpos( $trimmed, 'base64:' ) === 0 ) {
            $decoded = base64_decode( substr( $trimmed, 7 ), true );
            if ( $decoded === false || strlen( $decoded ) !== 32 ) {
                throw new RuntimeException( 'Base64-Secret ist ungueltig.' );
            }
            return $decoded;
        }

        return hash( 'sha256', $trimmed, true );
    }
}
