<?php

namespace NostrSigner\Rest;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\NostrService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class SignEventController
{
    public function __construct( private KeyManager $key_manager, private NostrService $nostr_service ) {
    }

    public function register_routes(): void
    {
        register_rest_route(
            'nostr-signer/v1',
            '/sign-event',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_sign_event' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'event_data' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'key_type' => [
                        'required' => true,
                        'type'     => 'string',
                        'enum'     => [ 'user', 'blog' ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'nostr-signer/v1',
            '/me',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_get_me' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ]
        );
    }

    public function permission_check(): bool
    {
        return is_user_logged_in();
    }

    public function handle_sign_event( WP_REST_Request $request )
    {
        if ( ! Crypto::is_master_key_available() ) {
            return new WP_Error( 'nostr_signer_master_key_missing', __( 'Nostr Signer master key is not configured.', 'nostr-signer' ), [ 'status' => 500 ] );
        }

        if ( ! $this->nostr_service->is_library_available() ) {
            return new WP_Error( 'nostr_signer_library_missing', __( 'Nostr PHP library is unavailable.', 'nostr-signer' ), [ 'status' => 500 ] );
        }

        $nonce_header = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce_header || ! wp_verify_nonce( $nonce_header, 'wp_rest' ) ) {
            return new WP_Error( 'nostr_signer_nonce_invalid', __( 'Ungueltiger Sicherheits-Nonce.', 'nostr-signer' ), [ 'status' => 403 ] );
        }

        $event_json = $request->get_param( 'event_data' );
        $key_type   = $request->get_param( 'key_type' );

        $event_payload = json_decode( $event_json, true );
        if ( ! is_array( $event_payload ) ) {
            return new WP_Error( 'nostr_signer_invalid_event', __( 'Das bereitgestellte Event ist kein gueltiges JSON.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            return new WP_Error( 'nostr_signer_no_user', __( 'Kein angemeldeter Benutzer gefunden.', 'nostr-signer' ), [ 'status' => 403 ] );
        }

        $encrypted_nsec = null;
        if ( $key_type === 'user' ) {
            $this->key_manager->ensure_user_key_exists( $current_user_id );
            $encrypted_nsec = $this->key_manager->get_encrypted_user_nsec( $current_user_id );
        } elseif ( $key_type === 'blog' ) {
            $this->key_manager->ensure_blog_key_exists();
            $encrypted_nsec = $this->key_manager->get_encrypted_blog_nsec();
        }

        if ( empty( $encrypted_nsec ) ) {
            return new WP_Error( 'nostr_signer_missing_key', __( 'Es ist kein Schluessel fuer die gewaehlte Option hinterlegt.', 'nostr-signer' ), [ 'status' => 404 ] );
        }

        $nsec_plain = Crypto::decrypt( $encrypted_nsec );
        if ( ! is_string( $nsec_plain ) || $nsec_plain === '' ) {
            return new WP_Error( 'nostr_signer_decrypt_failed', __( 'Der Schluessel konnte nicht entschluesselt werden.', 'nostr-signer' ), [ 'status' => 500 ] );
        }

        try {
            $signed_event = $this->nostr_service->signEvent( $event_payload, $nsec_plain );
        } catch ( RuntimeException $exception ) {
            unset( $nsec_plain );
            return new WP_Error( 'nostr_signer_sign_failed', $exception->getMessage(), [ 'status' => 500 ] );
        }

        unset( $nsec_plain );

        return new WP_REST_Response( $signed_event, 200 );
    }

    public function handle_get_me( WP_REST_Request $request )
    {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            return new WP_Error( 'nostr_signer_no_user', __( 'Kein angemeldeter Benutzer gefunden.', 'nostr-signer' ), [ 'status' => 403 ] );
        }

        $user = wp_get_current_user();

        $this->key_manager->ensure_user_key_exists( $current_user_id );
        $user_npub = $this->key_manager->get_user_npub( $current_user_id );
        $user_hex  = $this->nostr_service->convertBech32ToHex( $user_npub );

        $avatar_url = get_avatar_url( $current_user_id );
        $avatar_url = is_string( $avatar_url ) && $avatar_url !== '' ? $avatar_url : null;

        $nip05 = get_user_meta( $current_user_id, 'nostr_nip05', true );
        $nip05 = is_string( $nip05 ) && $nip05 !== '' ? $nip05 : null;

        $this->key_manager->ensure_blog_key_exists();
        $blog_npub = get_option( KeyManager::OPTION_BLOG_NPUB );
        $blog_npub = is_string( $blog_npub ) && $blog_npub !== '' ? $blog_npub : null;
        $blog_hex  = $this->nostr_service->convertBech32ToHex( $blog_npub );

        $logo_id  = get_theme_mod( 'custom_logo' );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : null;
        $logo_url = is_string( $logo_url ) && $logo_url !== '' ? $logo_url : null;

        $response = [
            'user' => [
                'pubkey'       => [
                    'npub' => $user_npub,
                    'hex'  => $user_hex,
                ],
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'avatar_url'   => $avatar_url,
                'nip05'        => $nip05,
            ],
            'blog' => [
                'pubkey'    => [
                    'npub' => $blog_npub,
                    'hex'  => $blog_hex,
                ],
                'home_url'  => home_url(),
                'blog_name' => get_bloginfo( 'name' ),
                'logo_url'  => $logo_url,
            ],
        ];

        return new WP_REST_Response( $response, 200 );
    }
}
