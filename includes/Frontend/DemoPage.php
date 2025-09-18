<?php

namespace NostrSigner\Frontend;

class DemoPage
{
    private const QUERY_VAR = 'nostr_signer_demo';

    public function boot(): void
    {
        add_action( 'init', [ $this, 'register_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render_demo' ] );
    }

    public function register_rewrite(): void
    {
        add_rewrite_rule( '^nostr-signer/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
        add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
    }

    public function register_query_var( array $vars ): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_render_demo(): void
    {
        if ( (int) get_query_var( self::QUERY_VAR ) !== 1 ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        $config = [
            'meUrl'   => rest_url( 'nostr-signer/v1/me' ),
            'signUrl' => rest_url( 'nostr-signer/v1/sign-event' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ];

        $html_path = NOSTR_SIGNER_PLUGIN_DIR . 'assets/test.html';
        $html      = file_get_contents( $html_path );

        if ( $html === false ) {
            wp_die( esc_html__( 'Die Demo-Datei test.html wurde nicht gefunden.', 'nostr-signer' ) );
        }

        $placeholders = [
            '__CONFIG_JSON__' => wp_json_encode( $config, JSON_UNESCAPED_SLASHES ),
            '__PLUGIN_URL__'  => esc_url( NOSTR_SIGNER_PLUGIN_URL ),
        ];

        $html = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $html );

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete HTML document.
        exit;
    }
}

