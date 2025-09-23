<?php

namespace NostrSigner\Admin;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\NostrService;

class AdminPage
{
    private const DEFAULT_RELAYS = [
        'wss://relay-rpi.edufeed.org',
        // 'wss://relay.damus.io',
        // 'wss://relay.nostr.band',
        // 'wss://nostr.fmt.wiz.biz',
    ];

    private string $hook_suffix = '';

    public function __construct( private NostrService $nostr_service ) {
    }

    public function register_admin_page(): void
    {
        $this->hook_suffix = add_options_page(
            __( 'Nostr Signer', 'nostr-signer' ),
            __( 'Nostr Signer', 'nostr-signer' ),
            'manage_options',
            'nostr-signer',
            [ $this, 'render_page' ]
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void
    {
        // Settings section
        add_settings_section(
            'nostr_signer_main_section',
            __( 'Plugin-Konfiguration', 'nostr-signer' ),
            [ $this, 'render_settings_section' ],
            'nostr_signer_settings'
        );

        // Register settings
        register_setting( 'nostr_signer_settings', 'nostr_signer_default_relays', [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_relays' ],
            'default' => self::DEFAULT_RELAYS,
        ] );

        register_setting( 'nostr_signer_settings', 'nostr_signer_tools_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://cdn.jsdelivr.net/npm/nostr-tools@2.16.2/lib/nostr.bundle.min.js',
        ] );

        register_setting( 'nostr_signer_settings', 'nostr_signer_profile_role', [
            'type' => 'string',
            'sanitize_callback' => [ $this, 'sanitize_role' ],
            'default' => 'author',
        ] );

        register_setting( 'nostr_signer_settings', 'nostr_signer_sign_own_role', [
            'type' => 'string',
            'sanitize_callback' => [ $this, 'sanitize_role' ],
            'default' => 'author',
        ] );

        register_setting( 'nostr_signer_settings', 'nostr_signer_sign_blog_role', [
            'type' => 'string',
            'sanitize_callback' => [ $this, 'sanitize_role' ],
            'default' => 'editor',
        ] );
    }

    public function render_settings_section(): void
    {
        echo '<p>' . esc_html__( 'Konfigurieren Sie hier die Standardeinstellungen für das Nostr Signer Plugin.', 'nostr-signer' ) . '</p>';
    }

    public function sanitize_relays( $input ): array
    {
        if ( ! is_array( $input ) ) {
            $input = explode( "\n", $input );
        }

        $relays = [];
        foreach ( $input as $relay ) {
            $relay = trim( sanitize_text_field( $relay ) );
            if ( ! empty( $relay ) && filter_var( $relay, FILTER_VALIDATE_URL ) ) {
                $relays[] = $relay;
            }
        }

        return $relays;
    }

    public function sanitize_role( $input ): string
    {
        $roles = wp_roles()->roles;
        if ( array_key_exists( $input, $roles ) ) {
            return $input;
        }

        return 'edit_posts';
    }

    public function maybe_render_success_notice(): void
    {
        // Only show on tools tab
        if ( ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'tools' ) {
            return;
        }

        // Check if all conditions are met
        $conditions_met = $this->check_plugin_readiness();

        if ( ! $conditions_met ) {
            return;
        }

        // Check if notice was already dismissed
        $dismissed = get_user_meta( get_current_user_id(), 'nostr_signer_readiness_notice_dismissed', true );
        if ( $dismissed ) {
            return;
        }

        $message = __( 'Nostr Signer ist vollständig betriebsbereit! Alle Bedingungen sind erfüllt: Schlüsselrotation erfolgreich, Relay-Konfiguration korrekt, GMP verfügbar und keine Fehler bei der Envelope-Umwandlung.', 'nostr-signer' );

        echo '<div class="notice notice-success is-dismissible" data-notice-type="plugin_ready">';
        echo '<p>' . esc_html( $message ) . '</p>';
        echo '</div>';

        // Add JavaScript to handle dismiss
        add_action( 'admin_footer', [ $this, 'add_readiness_dismiss_javascript' ] );
    }

    private function check_plugin_readiness(): bool
    {
        // Check if master key is available
        if ( ! Crypto::is_master_key_available() ) {
            return false;
        }

        // Check if nostr library is available
        if ( ! $this->nostr_service->is_library_available() ) {
            return false;
        }

        // Check if GMP is available
        if ( ! extension_loaded( 'gmp' ) ) {
            return false;
        }

        // Check if relays are configured
        $relays = get_option( 'nostr_signer_default_relays', [] );
        if ( empty( $relays ) || ! is_array( $relays ) ) {
            return false;
        }

        // Check if blog key exists
        $blog_npub = get_option( KeyManager::OPTION_BLOG_NPUB );
        if ( empty( $blog_npub ) ) {
            return false;
        }

        // Check if no outdated envelopes exist (successful key rotation)
        $allowed = Crypto::get_allowed_key_versions();
        if ( empty( $allowed ) ) {
            return false;
        }

        $min_allowed = min( $allowed );

        // Quick check for outdated envelopes
        $query = new \WP_User_Query(
            [
                'number'   => 10,
                'meta_key' => KeyManager::META_ENCRYPTED_NSEC,
                'fields'   => [ 'ID' ],
            ]
        );

        foreach ( (array) $query->get_results() as $user ) {
            $cipher = get_user_meta( $user->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            if ( ! is_string( $cipher ) || $cipher === '' ) {
                continue;
            }

            $json = base64_decode( $cipher, true );
            if ( $json === false ) {
                continue;
            }

            $envelope = json_decode( $json, true );
            if ( ! is_array( $envelope ) || ! isset( $envelope['kv'] ) ) {
                continue;
            }

            $version = (int) $envelope['kv'];
            if ( $version < $min_allowed ) {
                return false;
            }
        }

        return true;
    }

    public function add_readiness_dismiss_javascript(): void
    {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.notice[data-notice-type="plugin_ready"]').on('click', '.notice-dismiss', function() {
                var notice = $(this).closest('.notice');
                var noticeType = notice.data('notice-type');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'nostr_signer_dismiss_notice',
                        notice_type: noticeType,
                        nonce: '<?php echo wp_create_nonce( 'nostr_signer_dismiss_notice' ); ?>'
                    }
                });
            });
        });
        </script>
        <?php
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
        echo '<h1>' . esc_html__( 'Nostr Signer', 'nostr-signer' ) . '</h1>';

        // Show success notice if all conditions are met
        $this->maybe_render_success_notice();

        if ( ! Crypto::is_master_key_available() || ! $this->nostr_service->is_library_available() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Die Signierung ist deaktiviert. Bitte stellen Sie sicher, dass der Master-Schluessel gesetzt ist und die Nostr-Bibliothek geladen wurde.', 'nostr-signer' ) . '</p></div>';
        }

        // Tab-Navigation
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="#settings" class="nav-tab nav-tab-active">' . esc_html__( 'Einstellungen', 'nostr-signer' ) . '</a>';
        echo '<a href="#tools" class="nav-tab">' . esc_html__( 'Tools', 'nostr-signer' ) . '</a>';
        echo '</nav>';

        // Tab-Inhalte
        echo '<div id="settings" class="tab-content" style="display: block;">';
        $this->render_settings_tab();
        echo '</div>';

        echo '<div id="tools" class="tab-content" style="display: none;">';
        $this->render_tools_tab();
        echo '</div>';

        echo '</div>';

        // JavaScript für Tab-Navigation
        echo '<script type="text/javascript">
        jQuery(document).ready(function($) {
            $(".nav-tab-wrapper a").click(function(e) {
                e.preventDefault();
                var target = $(this).attr("href").substring(1);

                // Tabs ausblenden
                $(".tab-content").hide();

                // Aktiven Tab anzeigen
                $("#" + target).show();

                // Aktive Klasse setzen
                $(".nav-tab").removeClass("nav-tab-active");
                $(this).addClass("nav-tab-active");
            });
        });
        </script>';
    }

    private function render_settings_tab(): void
    {
        echo '<form method="post" action="options.php">';

        settings_fields( 'nostr_signer_settings' );
        do_settings_sections( 'nostr_signer_settings' );

        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        // Default Relays
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Standard-Relays', 'nostr-signer' ) . '</th>';
        echo '<td>';
        echo '<fieldset>';
        $default_relays = get_option( 'nostr_signer_default_relays', self::DEFAULT_RELAYS );
        echo '<textarea name="nostr_signer_default_relays" rows="4" cols="50" class="large-text code">' . esc_textarea( implode( "\n", $default_relays ) ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Eine Relay-URL pro Zeile. Diese werden als Standard für neue Events verwendet.', 'nostr-signer' ) . '</p>';
        echo '</fieldset>';
        echo '</td>';
        echo '</tr>';

        // Nostr Tools URL
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Nostr-Tools URL', 'nostr-signer' ) . '</th>';
        echo '<td>';
        echo '<fieldset>';
        $nostr_tools_url = get_option( 'nostr_signer_tools_url', 'https://cdn.jsdelivr.net/npm/nostr-tools@2.16.2/lib/nostr.bundle.min.js' );
        echo '<input type="url" name="nostr_signer_tools_url" value="' . esc_attr( $nostr_tools_url ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'URL zur nostr-tools JavaScript-Bibliothek.', 'nostr-signer' ) . '</p>';
        echo '</fieldset>';
        echo '</td>';
        echo '</tr>';

        // Berechtigungen für Nostr nsec auf Profilseite
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Berechtigung: Nostr nsec auf Profilseite', 'nostr-signer' ) . '</th>';
        echo '<td>';
        echo '<fieldset>';
        $profile_role = get_option( 'nostr_signer_profile_role', 'edit_posts' );
        echo '<select name="nostr_signer_profile_role">';
        wp_dropdown_roles( $profile_role );
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Mindestrolle für die Bereitstellung einer nostr nsec auf der Profilseite (Standard: ab Autor-Rolle).', 'nostr-signer' ) . '</p>';
        echo '</fieldset>';
        echo '</td>';
        echo '</tr>';

        // Berechtigung für eigene Events signieren
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Berechtigung: Eigene Events signieren', 'nostr-signer' ) . '</th>';
        echo '<td>';
        echo '<fieldset>';
        $sign_own_role = get_option( 'nostr_signer_sign_own_role', 'edit_posts' );
        echo '<select name="nostr_signer_sign_own_role">';
        wp_dropdown_roles( $sign_own_role );
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Mindestrolle für das Signieren eigener Events (Standard: ab Autor-Rolle).', 'nostr-signer' ) . '</p>';
        echo '</fieldset>';
        echo '</td>';
        echo '</tr>';

        // Berechtigung für Blog Events signieren
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Berechtigung: Blog Events signieren', 'nostr-signer' ) . '</th>';
        echo '<td>';
        echo '<fieldset>';
        $sign_blog_role = get_option( 'nostr_signer_sign_blog_role', 'edit_others_posts' );
        echo '<select name="nostr_signer_sign_blog_role">';
        wp_dropdown_roles( $sign_blog_role );
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Mindestrolle für das Signieren von Blog-Events (Standard: ab Redakteur-Rolle).', 'nostr-signer' ) . '</p>';
        echo '</fieldset>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody>';
        echo '</table>';

        submit_button();
        echo '</form>';
    }

    private function render_tools_tab(): void
    {
        echo '<h2>' . esc_html__( 'Event-Signierung', 'nostr-signer' ) . '</h2>';
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
    }
}


