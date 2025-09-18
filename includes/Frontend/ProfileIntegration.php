<?php

namespace NostrSigner\Frontend;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\NostrService;

class ProfileIntegration
{
    public function __construct( private KeyManager $key_manager, private NostrService $nostr_service ) {
    }

    public function boot(): void
    {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_profile_assets' ] );
        add_action( 'show_user_profile', [ $this, 'render_profile_section' ] );
    }

    public function enqueue_profile_assets(): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'profile' ) {
            return;
        }

        wp_enqueue_script( 'nostr-tools' );
        wp_enqueue_script( 'nostr-signer-import' );

        $temp_key_hex = hash_hmac( 'sha256', wp_get_session_token(), NOSTR_SIGNER_MASTER_KEY );
        $current_user_id = get_current_user_id();
        $npub = $this->key_manager->get_user_npub( $current_user_id );

        wp_localize_script(
            'nostr-signer-import',
            'NostrSignerImportData',
            [
                'enabled'     => Crypto::is_master_key_available() && $this->nostr_service->is_library_available(),
                'target'      => 'user',
                'restUrl'     => rest_url( 'nostr-signer/v1/import-key' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'tempKeyHex'  => $temp_key_hex,
                'formId'      => 'nostr-signer-user-import-form',
                'inputId'     => 'nostr-signer-user-import-input',
                'statusId'    => 'nostr-signer-user-import-status',
                'npubDisplay' => 'nostr-signer-user-npub',
                'currentNpub' => $npub,
            ]
        );
    }

    public function render_profile_section( \WP_User $user ): void
    {
        echo '<h2>' . esc_html__( 'Nostr Signer', 'nostr-signer' ) . '</h2>';

        if ( ! Crypto::is_master_key_available() || ! $this->nostr_service->is_library_available() ) {
            echo '<p>' . esc_html__( 'Der Import ist derzeit deaktiviert. Stellen Sie sicher, dass der Master-Schluessel gesetzt ist und die Nostr-Bibliothek geladen wurde.', 'nostr-signer' ) . '</p>';
            return;
        }

        $npub = $this->key_manager->get_user_npub( $user->ID );
        echo '<p>' . esc_html__( 'Aktueller oeffentlicher Schluessel (npub):', 'nostr-signer' ) . ' <code id="nostr-signer-user-npub">' . ( $npub ? esc_html( $npub ) : esc_html__( 'Noch nicht hinterlegt', 'nostr-signer' ) ) . '</code></p>';
        echo '<p>' . esc_html__( 'Importieren Sie einen vorhandenen privaten Schluessel (nsec). Der Schluessel wird nur clientseitig verschluesselt uebertragen.', 'nostr-signer' ) . '</p>';
        echo '<form id="nostr-signer-user-import-form">';
        echo '<label for="nostr-signer-user-import-input">' . esc_html__( 'Neuer nsec', 'nostr-signer' ) . '</label><br />';
        echo '<input type="text" id="nostr-signer-user-import-input" class="regular-text" autocomplete="off" />';
        echo '<p class="description" id="nostr-signer-user-import-status">' . esc_html__( 'Geben Sie Ihren nsec ein und klicken Sie auf Speichern.', 'nostr-signer' ) . '</p>';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'nsec speichern', 'nostr-signer' ) . '</button>';
        echo '</form>';
    }
}
