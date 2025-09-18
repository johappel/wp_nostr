<?php

namespace NostrSigner;

use Exception;

class KeyManager
{
    public const META_NPUB = 'nostr_npub';
    public const META_ENCRYPTED_NSEC = 'nostr_encrypted_nsec';
    public const OPTION_BLOG_NPUB = 'nostr_blog_npub';
    public const OPTION_BLOG_ENCRYPTED_NSEC = 'nostr_blog_encrypted_nsec';

    public function __construct( private NostrService $nostr_service ) {
    }

    public function ensure_blog_key_exists(): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            return;
        }

        if ( get_option( self::OPTION_BLOG_ENCRYPTED_NSEC ) && get_option( self::OPTION_BLOG_NPUB ) ) {
            return;
        }

        try {
            $keypair   = $this->nostr_service->generateKeyPair();
            $encrypted = Crypto::encrypt( $keypair['nsec'] );

            update_option( self::OPTION_BLOG_NPUB, $keypair['npub'], false );
            update_option( self::OPTION_BLOG_ENCRYPTED_NSEC, $encrypted, false );
        } catch ( Exception $exception ) {
            // Store error for debugging while avoiding fatal errors.
            error_log( '[nostr-signer] Failed to create blog key: ' . $exception->getMessage() );
        }
    }

    public function create_keys_for_user( int $user_id ): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            return;
        }

        $existing = get_user_meta( $user_id, self::META_ENCRYPTED_NSEC, true );
        if ( ! empty( $existing ) ) {
            return;
        }

        try {
            $keypair   = $this->nostr_service->generateKeyPair();
            $encrypted = Crypto::encrypt( $keypair['nsec'] );

            update_user_meta( $user_id, self::META_NPUB, $keypair['npub'] );
            update_user_meta( $user_id, self::META_ENCRYPTED_NSEC, $encrypted );
        } catch ( Exception $exception ) {
            error_log( '[nostr-signer] Failed to provision user key: ' . $exception->getMessage() );
        }
    }

    public function get_encrypted_user_nsec( int $user_id ): ?string
    {
        $value = get_user_meta( $user_id, self::META_ENCRYPTED_NSEC, true );
        return is_string( $value ) && $value !== '' ? $value : null;
    }

    public function get_user_npub( int $user_id ): ?string
    {
        $value = get_user_meta( $user_id, self::META_NPUB, true );
        return is_string( $value ) && $value !== '' ? $value : null;
    }

    public function get_encrypted_blog_nsec(): ?string
    {
        $value = get_option( self::OPTION_BLOG_ENCRYPTED_NSEC );
        return is_string( $value ) && $value !== '' ? $value : null;
    }
}
