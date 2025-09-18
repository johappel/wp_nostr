<?php

namespace NostrSigner\Frontend;

use NostrSigner\KeyManager;
use NostrSigner\NostrService;

class WellKnownEndpoint
{
    public function __construct( private KeyManager $key_manager, private NostrService $nostr_service, private array $relays ) {
    }

    public function boot(): void
    {
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_output_nostr_json' ] );
    }

    public function add_rewrite_rule(): void
    {
        add_rewrite_rule( '^\.well-known/nostr\.json$', 'index.php?is_nostr_json=1', 'top' );
    }

    public function register_query_vars( array $vars ): array
    {
        $vars[] = 'is_nostr_json';
        return $vars;
    }

    public function maybe_output_nostr_json(): void
    {
        if ( (int) get_query_var( 'is_nostr_json' ) !== 1 ) {
            return;
        }

        $name = isset( $_GET['name'] ) ? sanitize_title( wp_unslash( (string) $_GET['name'] ) ) : '';

        if ( $name === '' ) {
            $this->send_response( [], 400 );
        }

        $user = get_user_by( 'slug', $name );
        if ( ! $user ) {
            $this->send_response( [], 404 );
        }

        $npub = get_user_meta( $user->ID, KeyManager::META_NPUB, true );
        if ( ! is_string( $npub ) || $npub === '' ) {
            $this->send_response( [], 404 );
        }

        $hex = $this->nostr_service->convertBech32ToHex( $npub );
        if ( ! is_string( $hex ) || $hex === '' ) {
            $this->send_response( [], 500 );
        }

        $names = [
            $name => $hex,
        ];

        $payload = [
            'names' => $names,
        ];

        if ( ! empty( $this->relays ) ) {
            $payload['relays'] = [
                $hex => $this->relays,
            ];
        }

        $this->send_response( $payload, 200 );
    }

    private function send_response( array $data, int $status ): void
    {
        if ( ! headers_sent() ) {
            status_header( $status );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Access-Control-Allow-Origin: *' );
        }

        echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}

