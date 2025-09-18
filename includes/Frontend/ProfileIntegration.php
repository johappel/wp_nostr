<?php

namespace NostrSigner\Frontend;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\NostrService;

class ProfileIntegration
{
    public function __construct( private KeyManager $key_manager, private NostrService $nostr_service, private array $relays ) {
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
        wp_enqueue_script( 'nostr-signer-profile' );

        $temp_key_hex     = hash_hmac( 'sha256', wp_get_session_token(), NOSTR_SIGNER_MASTER_KEY );
        $current_user_id  = get_current_user_id();
        $npub             = $this->key_manager->get_user_npub( $current_user_id );
        $author_url       = get_author_posts_url( $current_user_id );
        $profile_user     = get_userdata( $current_user_id );
        $display_name     = $profile_user ? (string) $profile_user->display_name : '';
        $name             = $profile_user ? (string) $profile_user->user_login : '';
        $about            = $profile_user ? (string) $profile_user->description : '';
        $website          = $profile_user ? (string) $profile_user->user_url : '';
        $picture          = get_avatar_url( $current_user_id ) ?: '';
        $nip05            = get_user_meta( $current_user_id, 'nostr_nip05', true );

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

        wp_localize_script(
            'nostr-signer-profile',
            'NostrSignerProfileData',
            [
                'enabled'     => Crypto::is_master_key_available() && $this->nostr_service->is_library_available(),
                'formId'      => 'nostr-signer-profile-form',
                'buttonId'    => 'nostr-signer-profile-publish',
                'statusId'    => 'nostr-signer-profile-status',
                'fields'      => [
                    'name'         => 'nostr-profile-name',
                    'display_name' => 'nostr-profile-display-name',
                    'about'        => 'nostr-profile-about',
                    'website'      => 'nostr-profile-website',
                    'picture'      => 'nostr-profile-picture',
                    'nip05'        => 'nostr-profile-nip05',
                ],
                'initial'    => [
                    'name'         => $name,
                    'display_name' => $display_name,
                    'about'        => $about,
                    'website'      => $website,
                    'picture'      => $picture,
                    'nip05'        => is_string( $nip05 ) ? $nip05 : '',
                ],
                'signUrl'     => rest_url( 'nostr-signer/v1/sign-event' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'authorUrl'   => $author_url,
                'relays'      => $this->relays,
                'keyType'     => 'user',
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

        $npub        = $this->key_manager->get_user_npub( $user->ID );
        $author_url  = get_author_posts_url( $user->ID );
        $picture_url = get_avatar_url( $user->ID );
        $picture_url = is_string( $picture_url ) ? $picture_url : '';
        $nip05       = get_user_meta( $user->ID, 'nostr_nip05', true );
        $nip05       = is_string( $nip05 ) ? $nip05 : '';

        echo '<p>' . esc_html__( 'Aktueller oeffentlicher Schluessel (npub):', 'nostr-signer' ) . ' <code id="nostr-signer-user-npub">' . ( $npub ? esc_html( $npub ) : esc_html__( 'Noch nicht hinterlegt', 'nostr-signer' ) ) . '</code></p>';

        echo '<h3>' . esc_html__( 'Nostr-Profil', 'nostr-signer' ) . '</h3>';
        echo '<form id="nostr-signer-profile-form">';
        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-name">' . esc_html__( 'Name (name)', 'nostr-signer' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="nostr-profile-name" value="' . esc_attr( $user->user_login ) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-display-name">' . esc_html__( 'Anzeigename (display_name)', 'nostr-signer' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="nostr-profile-display-name" value="' . esc_attr( $user->display_name ) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-about">' . esc_html__( 'Beschreibung (about)', 'nostr-signer' ) . '</label></th>';
        echo '<td><textarea class="large-text" rows="4" id="nostr-profile-about">' . esc_textarea( $user->description ) . '</textarea></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-website">' . esc_html__( 'Website', 'nostr-signer' ) . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="nostr-profile-website" value="' . esc_attr( $user->user_url ) . '" /><p class="description">' . esc_html__( 'Autoren-URL:', 'nostr-signer' ) . ' <code>' . esc_html( $author_url ) . '</code></p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-picture">' . esc_html__( 'Profilbild-URL (picture)', 'nostr-signer' ) . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="nostr-profile-picture" value="' . esc_attr( $picture_url ) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="nostr-profile-nip05">' . esc_html__( 'NIP-05', 'nostr-signer' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="nostr-profile-nip05" value="' . esc_attr( $nip05 ) . '" /></td>';
        echo '</tr>';

        echo '</table>';
        echo '<p class="description">' . esc_html__( 'Relays fuer die Veroeffentlichung:', 'nostr-signer' ) . ' ' . esc_html( implode( ', ', $this->relays ) ) . '</p>';
        echo '<p class="description" id="nostr-signer-profile-status"></p>';
        echo '<p><button type="submit" class="button button-primary" id="nostr-signer-profile-publish">' . esc_html__( 'Nostr-Profil auf Relays veroeffentlichen', 'nostr-signer' ) . '</button></p>';
        echo '</form>';

        echo '<hr />';
        echo '<p>' . esc_html__( 'Importieren Sie einen vorhandenen privaten Schluessel (nsec). Der Schluessel wird nur clientseitig verschluesselt uebertragen.', 'nostr-signer' ) . '</p>';
        echo '<form id="nostr-signer-user-import-form">';
        echo '<label for="nostr-signer-user-import-input">' . esc_html__( 'Neuer nsec', 'nostr-signer' ) . '</label><br />';
        echo '<input type="text" id="nostr-signer-user-import-input" class="regular-text" autocomplete="off" />';
        echo '<p class="description" id="nostr-signer-user-import-status">' . esc_html__( 'Geben Sie Ihren nsec ein und klicken Sie auf Speichern.', 'nostr-signer' ) . '</p>';
        echo '<button type="submit" class="button button-secondary">' . esc_html__( 'nsec speichern', 'nostr-signer' ) . '</button>';
        echo '</form>';
    }
}
