<?php

namespace NostrSigner\Admin;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
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

        // Try to enqueue the bundled SPA if present (built by Vite -> assets/js/spa-nostr-app.bundle.js)
        $bundle_path = 'assets/js/spa-nostr-app.bundle.js';
        $bundle_file = NOSTR_SIGNER_PLUGIN_DIR . $bundle_path;
        if ( file_exists( $bundle_file ) ) {
            wp_enqueue_script(
                'nostr-signer-spa',
                NOSTR_SIGNER_PLUGIN_URL . $bundle_path,
                [],
                NOSTR_SIGNER_PLUGIN_VERSION,
                true
            );
            $spa_handle = 'nostr-signer-spa';
        } else {
            // fallback to unbundled module for development
            wp_enqueue_script(
                'nostr-signer-spa',
                NOSTR_SIGNER_PLUGIN_URL . 'assets/js/spa-demo-app.js',
                [],
                NOSTR_SIGNER_PLUGIN_VERSION,
                true
            );
            $spa_handle = 'nostr-signer-spa';
        }

        wp_enqueue_script( 'nostr-tools' );
        wp_enqueue_script( 'nostr-signer-import' );

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

        // Inject global config for SPA bundle as well
        wp_localize_script(
            $spa_handle,
            'NostrSignerConfig',
            [
                'apiBase' => rest_url(),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'meUrl'   => rest_url( 'nostr-signer/v1/me' ),
                'signUrl' => rest_url( 'nostr-signer/v1/sign-event' ),
            ]
        );

    $temp_key_hex = hash_hmac( 'sha256', wp_get_session_token(), \NOSTR_SIGNER_MASTER_KEY );

        wp_localize_script(
            'nostr-signer-import',
            'NostrSignerImportData',
            [
                'enabled'     => Crypto::is_master_key_available() && $this->nostr_service->is_library_available(),
                'target'      => 'blog',
                'restUrl'     => rest_url( 'nostr-signer/v1/import-key' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'tempKeyHex'  => $temp_key_hex,
                'formId'      => 'nostr-signer-blog-import-form',
                'inputId'     => 'nostr-signer-blog-import-input',
                'statusId'    => 'nostr-signer-blog-import-status',
                'npubDisplay' => 'nostr-signer-blog-npub',
                'currentNpub' => get_option( KeyManager::OPTION_BLOG_NPUB ),
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

        echo '<hr />';
        echo '<h2>' . esc_html__( 'Blog-Schluessel importieren', 'nostr-signer' ) . '</h2>';
        $current_npub = get_option( KeyManager::OPTION_BLOG_NPUB );
        echo '<p>' . esc_html__( 'Aktueller Blog-npub:', 'nostr-signer' ) . ' <code id="nostr-signer-blog-npub">' . esc_html( $current_npub ? $current_npub : __( 'Noch nicht hinterlegt', 'nostr-signer' ) ) . '</code></p>';

        if ( ! Crypto::is_master_key_available() || ! $this->nostr_service->is_library_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Import deaktiviert: Bitte stellen Sie sicher, dass der Master-Schluessel gesetzt ist und die Nostr-Bibliothek verfuegbar ist.', 'nostr-signer' ) . '</p></div>';
        } else {
            echo '<p>' . esc_html__( 'Importieren Sie einen vorhandenen Blog-nsec. Der Schluessel wird clientseitig verschluesselt uebertragen.', 'nostr-signer' ) . '</p>';
            echo '<form id="nostr-signer-blog-import-form" style="max-width:480px;">';
            echo '<label for="nostr-signer-blog-import-input">' . esc_html__( 'Blog nsec', 'nostr-signer' ) . '</label><br />';
            echo '<input type="text" id="nostr-signer-blog-import-input" class="regular-text" autocomplete="off" />';
            echo '<p class="description" id="nostr-signer-blog-import-status">' . esc_html__( 'Geben Sie den Blog-nsec ein und klicken Sie auf Speichern.', 'nostr-signer' ) . '</p>';
            echo '<button type="submit" class="button button-primary">' . esc_html__( 'Blog-nsec speichern', 'nostr-signer' ) . '</button>';
            echo '</form>';
        }

        echo '</div>';
    }
}


