<?php

namespace NostrSigner\Rest;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\NostrService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class ImportKeyController
{
    public function __construct( private KeyManager $key_manager, private NostrService $nostr_service ) {
    }

    public function register_routes(): void
    {
        register_rest_route(
            'nostr-signer/v1',
            '/import-key',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_import' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'encrypted_nsec' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'npub' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'target' => [
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

    public function handle_import( WP_REST_Request $request )
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

        $target         = $request->get_param( 'target' );
        $encrypted_nsec = $request->get_param( 'encrypted_nsec' );
        $npub_provided  = $request->get_param( 'npub' );

        if ( ! is_string( $encrypted_nsec ) || $encrypted_nsec === '' ) {
            return new WP_Error( 'nostr_signer_invalid_payload', __( 'Das verschluesselte Material fehlt.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        if ( ! is_string( $npub_provided ) || $npub_provided === '' ) {
            return new WP_Error( 'nostr_signer_invalid_npub', __( 'Der angegebene npub ist ungueltig.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        if ( $target === 'blog' && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'nostr_signer_no_permission', __( 'Sie besitzen keine Berechtigungen fuer diesen Vorgang.', 'nostr-signer' ), [ 'status' => 403 ] );
        }

        $session_token = wp_get_session_token();
        if ( ! is_string( $session_token ) || $session_token === '' ) {
            return new WP_Error( 'nostr_signer_no_session', __( 'Es konnte kein Session-Token ermittelt werden.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        $temp_key_hex = hash_hmac( 'sha256', $session_token, NOSTR_SIGNER_MASTER_KEY );
        $temp_key     = @hex2bin( $temp_key_hex );
        if ( $temp_key === false ) {
            return new WP_Error( 'nostr_signer_temp_key_failed', __( 'Der temporaere Schluessel konnte nicht erzeugt werden.', 'nostr-signer' ), [ 'status' => 500 ] );
        }

        $nsec_plain = Crypto::decrypt_with_custom_key( $encrypted_nsec, $temp_key );
        if ( ! is_string( $nsec_plain ) || $nsec_plain === '' ) {
            return new WP_Error( 'nostr_signer_decrypt_failed', __( 'Der private Schluessel konnte nicht entschluesselt werden.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        if ( strncmp( $nsec_plain, 'nsec', 4 ) !== 0 ) {
            unset( $nsec_plain );
            return new WP_Error( 'nostr_signer_invalid_nsec', __( 'Das Format des privaten Schluessels ist ungueltig.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        $derived_npub = $this->nostr_service->deriveNpubFromNsec( $nsec_plain );
        if ( ! is_string( $derived_npub ) || $derived_npub === '' ) {
            unset( $nsec_plain );
            return new WP_Error( 'nostr_signer_npub_derivation_failed', __( 'Der oeffentliche Schluessel konnte nicht abgeleitet werden.', 'nostr-signer' ), [ 'status' => 500 ] );
        }

        if ( $derived_npub !== $npub_provided ) {
            unset( $nsec_plain );
            return new WP_Error( 'nostr_signer_npub_mismatch', __( 'Der berechnete oeffentliche Schluessel stimmt nicht mit dem angegebenen ueberein.', 'nostr-signer' ), [ 'status' => 400 ] );
        }

        $encrypted_for_storage = Crypto::encrypt( $nsec_plain );
        unset( $nsec_plain );

        if ( $target === 'user' ) {
            $current_user_id = get_current_user_id();
            if ( ! $current_user_id ) {
                return new WP_Error( 'nostr_signer_no_user', __( 'Kein angemeldeter Benutzer gefunden.', 'nostr-signer' ), [ 'status' => 403 ] );
            }

            update_user_meta( $current_user_id, KeyManager::META_NPUB, $derived_npub );
            update_user_meta( $current_user_id, KeyManager::META_ENCRYPTED_NSEC, $encrypted_for_storage );
        } else {
            update_option( KeyManager::OPTION_BLOG_NPUB, $derived_npub, false );
            update_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC, $encrypted_for_storage, false );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'npub'    => $derived_npub,
                'target'  => $target,
            ],
            200
        );
    }
}
