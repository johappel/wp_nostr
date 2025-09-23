<?php

namespace NostrSigner;

use NostrSigner\Admin\AdminPage;
use NostrSigner\Frontend\DemoPage;
use NostrSigner\Frontend\CalendarPage;
use NostrSigner\Frontend\ProfileIntegration;
use NostrSigner\Frontend\WellKnownEndpoint;
use NostrSigner\RelayPublisher;
use NostrSigner\RotationManager;
use NostrSigner\Rest\ImportKeyController;
use NostrSigner\Rest\SignEventController;

class Plugin
{
    private const DEFAULT_RELAYS = [
        'wss://relay-rpi.edufeed.org',
        // 'wss://relay.damus.io',
        // 'wss://relay.nostr.band',
        // 'wss://nostr.fmt.wiz.biz',
    ];

    private static function get_configured_relays(): array
    {
        $configured = get_option( 'nostr_signer_default_relays', self::DEFAULT_RELAYS );
        return is_array( $configured ) ? $configured : self::DEFAULT_RELAYS;
    }

    public function register_relay_filter(): void
    {
        add_filter( 'nostr_signer_default_relays', [ $this, 'get_default_relays' ] );
    }

    public function get_default_relays( array $default_relays ): array
    {
        return self::get_configured_relays();
    }

    private static function get_configured_tools_url(): string
    {
        $configured = get_option( 'nostr_signer_tools_url', 'https://cdn.jsdelivr.net/npm/nostr-tools@2.16.2/lib/nostr.bundle.min.js' );
        return is_string( $configured ) ? $configured : 'https://cdn.jsdelivr.net/npm/nostr-tools@2.16.2/lib/nostr.bundle.min.js';
    }

    private static ?Plugin $instance = null;

    private NostrService $nostr_service;
    private KeyManager $key_manager;
    private SignEventController $rest_controller;
    private ImportKeyController $import_controller;
    private RelayPublisher $relay_publisher;
    private RotationManager $rotation_manager;
    private AdminPage $admin_page;
    private ProfileIntegration $profile_integration;
    private WellKnownEndpoint $well_known_endpoint;
    private DemoPage $demo_page;
    private CalendarPage $calendar_page;

    private bool $gmp_available;

    public static function instance(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $this->gmp_available = extension_loaded( 'gmp' );

        $this->nostr_service       = new NostrService();
        $this->key_manager         = new KeyManager( $this->nostr_service );
        $configured_relays         = self::get_configured_relays();
        $this->relay_publisher     = new RelayPublisher( $configured_relays );
        $this->rest_controller     = new SignEventController( $this->key_manager, $this->nostr_service, $this->relay_publisher );
        $this->import_controller   = new ImportKeyController( $this->key_manager, $this->nostr_service );
        $this->rotation_manager    = new RotationManager();
        $this->admin_page          = new AdminPage( $this->nostr_service );
        $this->demo_page           = new DemoPage();
        $this->calendar_page       = new CalendarPage();
        $this->profile_integration = new ProfileIntegration( $this->key_manager, $this->nostr_service, $configured_relays );
        $this->well_known_endpoint = new WellKnownEndpoint( $this->key_manager, $this->nostr_service, $configured_relays );

        $this->rotation_manager->register_hooks();
        add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_assets' ], 0 );
        add_action( 'init', [ $this, 'register_relay_filter' ], 5 );
        $this->profile_integration->boot();
        $this->well_known_endpoint->boot();

        add_action( 'admin_notices', [ $this, 'maybe_render_master_key_notice' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_gmp_notice' ] );
        add_action( 'wp_ajax_nostr_signer_dismiss_notice', [ $this, 'handle_notice_dismiss' ] );
        add_action( 'plugins_loaded', [ $this, 'init_hooks' ] );
        add_action( 'user_register', [ $this, 'handle_user_register' ], 10, 1 );
        add_action( 'rest_api_init', [ $this->rest_controller, 'register_routes' ] );
        add_action( 'rest_api_init', [ $this->import_controller, 'register_routes' ] );
        add_action( 'admin_menu', [ $this->admin_page, 'register_admin_page' ] );

        $this->demo_page->boot();
        $this->calendar_page->boot();

        register_activation_hook( NOSTR_SIGNER_PLUGIN_FILE, [ $this, 'handle_activation' ] );
        register_deactivation_hook( NOSTR_SIGNER_PLUGIN_FILE, [ $this, 'handle_deactivation' ] );
    }

    public function register_admin_assets(): void
    {
        if ( ! wp_script_is( 'nostr-tools', 'registered' ) ) {
            wp_register_script(
                'nostr-tools',
                self::get_configured_tools_url(),
                [],
                '2.3.1',
                true
            );
        }

        if ( ! wp_script_is( 'nostr-signer-import', 'registered' ) ) {
            wp_register_script(
                'nostr-signer-import',
                NOSTR_SIGNER_PLUGIN_URL . 'assets/js/nostr-signer-import.js',
                [ 'wp-api-fetch', 'nostr-tools' ],
                NOSTR_SIGNER_PLUGIN_VERSION,
                true
            );
        }

        if ( ! wp_script_is( 'nostr-signer-profile', 'registered' ) ) {
            wp_register_script(
                'nostr-signer-profile',
                NOSTR_SIGNER_PLUGIN_URL . 'assets/js/nostr-signer-profile.js',
                [ 'wp-api-fetch', 'nostr-tools' ],
                NOSTR_SIGNER_PLUGIN_VERSION,
                true
            );
        }
    }

    public function init_hooks(): void
    {
        if ( Crypto::is_master_key_available() && $this->nostr_service->is_library_available() ) {
            $this->key_manager->ensure_blog_key_exists();
        }
    }

    public function maybe_render_master_key_notice(): void
    {
        if ( Crypto::is_master_key_available() ) {
            return;
        }

        $issues = Crypto::get_configuration_issues();
        if ( empty( $issues ) ) {
            $issues[] = __( 'Die Schluesselkonfiguration ist unvollstaendig.', 'nostr-signer' );
        }

        $intro = __( 'Nostr Signer ist deaktiviert, weil die Schluesselkonfiguration unvollstaendig ist. Bitte pruefen Sie folgende Punkte:', 'nostr-signer' );

        echo '<div class="notice notice-error"><p>' . esc_html( $intro ) . '</p><ul>';
        foreach ( $issues as $issue ) {
            echo '<li>' . esc_html( $issue ) . '</li>';
        }
        echo '</ul>';

        $example = "define('NOSTR_SIGNER_MASTER_KEY', 'base64:IhreMasterBasis64==');\n"
            . "define('NOSTR_SIGNER_ACTIVE_KEY_VERSION', 2);\n"
            . "define('NOSTR_SIGNER_MAX_KEY_VERSIONS', 2);\n"
            . "define('NOSTR_SIGNER_KEY_V1', 'base64:AltKeyBasis64==');\n"
            . "define('NOSTR_SIGNER_KEY_V2', 'base64:AktiverKeyBasis64==');";

        echo '<p>' . esc_html__( 'Beispiel-Konfiguration in wp-config.php:', 'nostr-signer' ) . '</p>';
        echo '<pre><code>' . esc_html( $example ) . '</code></pre>';
        echo '</div>';
    }

    public function maybe_render_gmp_notice(): void
    {
        if ( $this->gmp_available || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check if notice was already dismissed
        $dismissed = get_user_meta( get_current_user_id(), 'nostr_signer_gmp_notice_dismissed', true );
        if ( $dismissed ) {
            return;
        }

        $message = __( 'Die PHP-Erweiterung GMP ist nicht aktiv. Die Nostr-Bibliothek benötigt GMP für kryptografische Operationen. Bitte installieren Sie die GMP-Erweiterung auf Ihrem Server.', 'nostr-signer' );

        echo '<div class="notice notice-warning is-dismissible" data-notice-type="gmp_missing">';
        echo '<p>' . esc_html( $message ) . '</p>';
        echo '</div>';

        // Add JavaScript to handle dismiss
        add_action( 'admin_footer', [ $this, 'add_gmp_dismiss_javascript' ] );
    }

    public function add_gmp_dismiss_javascript(): void
    {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.notice[data-notice-type="gmp_missing"]').on('click', '.notice-dismiss', function() {
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

    public function handle_activation(): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            deactivate_plugins( plugin_basename( NOSTR_SIGNER_PLUGIN_FILE ) );
            $issues = Crypto::get_configuration_issues();
            $message = '<p>' . esc_html__( 'Aktivierung abgebrochen: Die Schluesselkonfiguration ist unvollstaendig.', 'nostr-signer' ) . '</p>';
            if ( ! empty( $issues ) ) {
                $message .= '<ul>';
                foreach ( $issues as $issue ) {
                    $message .= '<li>' . esc_html( $issue ) . '</li>';
                }
                $message .= '</ul>';
            }
            wp_die( wp_kses_post( $message ) );
        }

        if ( ! $this->gmp_available ) {
            deactivate_plugins( plugin_basename( NOSTR_SIGNER_PLUGIN_FILE ) );
            wp_die( esc_html__( 'Aktivierung abgebrochen: Die PHP-Erweiterung GMP ist erforderlich.', 'nostr-signer' ) );
        }

        $this->demo_page->register_rewrite();
        $this->calendar_page->register_rewrite();
        $this->well_known_endpoint->add_rewrite_rule();
        flush_rewrite_rules();
        $this->rotation_manager->schedule_events();

        $this->key_manager->ensure_blog_key_exists();
    }

    public function handle_deactivation(): void
    {
        flush_rewrite_rules();
        $this->rotation_manager->clear_schedule();
    }

    public function get_rotation_manager(): RotationManager
    {
        return $this->rotation_manager;
    }
    public function handle_user_register( int $user_id ): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            return;
        }

        if ( ! $this->nostr_service->is_library_available() ) {
            return;
        }

        $this->key_manager->create_keys_for_user( $user_id );
    }

    public function handle_notice_dismiss(): void
    {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'nostr_signer_dismiss_notice' ) ) {
            wp_die( esc_html__( 'Sicherheitsfehler', 'nostr-signer' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung', 'nostr-signer' ) );
        }

        $notice_type = sanitize_text_field( $_POST['notice_type'] ?? '' );

        if ( $notice_type === 'key_rotation_success' ) {
            update_user_meta( get_current_user_id(), 'nostr_signer_key_rotation_notice_dismissed', true );
        } elseif ( $notice_type === 'gmp_missing' ) {
            update_user_meta( get_current_user_id(), 'nostr_signer_gmp_notice_dismissed', true );
        } elseif ( $notice_type === 'plugin_ready' ) {
            update_user_meta( get_current_user_id(), 'nostr_signer_readiness_notice_dismissed', true );
        }

        wp_die();
    }
}







