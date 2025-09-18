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
            $encrypted_nsec = $this->key_manager->get_encrypted_user_nsec( $current_user_id );
        } elseif ( $key_type === 'blog' ) {
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
}