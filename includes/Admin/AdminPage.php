<?php

namespace NostrSigner\Admin;

use NostrSigner\Crypto;
use NostrSigner\NostrService;

class AdminPage
{
    private string $hook_suffix = '';

    public function __construct( private NostrService $nostr_service ) {
    }

    public function register_admin_page(): void
    {
        $this->hook_suffix = add_menu_page(
            __( 'Nostr Signer', 'nostr-signer' ),
            __( 'Nostr Signer', 'nostr-signer' ),
            'manage_options',
            'nostr-signer',
            [ $this, 'render_page' ],
            'dashicons-shield'
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( string $hook ): void
    {
        if ( $this->hook_suffix !== $hook ) {
            return;
        }

        $handle = 'nostr-signer-admin';
        wp_enqueue_script(
            $handle,
            NOSTR_SIGNER_PLUGIN_URL . 'assets/js/nostr-signer-admin.js',
            [],
            NOSTR_SIGNER_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            $handle,
            'NostrSignerAdmin',
            [
                'restUrl'      => rest_url( 'nostr-signer/v1/sign-event' ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'masterReady'  => Crypto::is_master_key_available(),
                'libraryReady' => $this->nostr_service->is_library_available(),
            ]
        );
    }

    public function render_page(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Nostr Event Signierer', 'nostr-signer' ) . '</h1>';

        if ( ! Crypto::is_master_key_available() || ! $this->nostr_service->is_library_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Die Signierung ist deaktiviert. Bitte stellen Sie sicher, dass der Master-Schluessel gesetzt ist und die Nostr-Bibliothek geladen wurde.', 'nostr-signer' ) . '</p></div>';
        }

        echo '<p>' . esc_html__( 'Geben Sie unten ein Nostr-Event an und signieren Sie es entweder mit Ihrem Benutzerkonto oder mit dem globalen Blog-Schluessel.', 'nostr-signer' ) . '</p>';

        echo '<textarea id="nostr-event-content" rows="6" style="width:100%;"></textarea>';

        echo '<p>';
        echo '<label><input type="radio" name="key_type" value="user" checked> ' . esc_html__( 'Mit meinem Account signieren', 'nostr-signer' ) . '</label><br />';
        echo '<label><input type="radio" name="key_type" value="blog"> ' . esc_html__( 'Mit dem Blog-Account signieren', 'nostr-signer' ) . '</label>';
        echo '</p>';

        echo '<button class="button button-primary" id="sign-button">' . esc_html__( 'Event signieren', 'nostr-signer' ) . '</button>';

        echo '<h2>' . esc_html__( 'Signiertes Event', 'nostr-signer' ) . '</h2>';
        echo '<pre><code id="signed-event-output"></code></pre>';
        echo '</div>';
    }
}
