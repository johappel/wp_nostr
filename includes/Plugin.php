<?php

namespace NostrSigner;

use NostrSigner\Admin\AdminPage;
use NostrSigner\Frontend\DemoPage;
use NostrSigner\Frontend\ProfileIntegration;
use NostrSigner\Rest\ImportKeyController;
use NostrSigner\Rest\SignEventController;

class Plugin
{
    private const DEFAULT_RELAYS = [
        'wss://relay.damus.io',
        'wss://relay.nostr.band',
        'wss://nostr.fmt.wiz.biz',
    ];

    private static ?Plugin $instance = null;

    private NostrService $nostr_service;
    private KeyManager $key_manager;
    private SignEventController $rest_controller;
    private ImportKeyController $import_controller;
    private RelayPublisher $relay_publisher;
    private AdminPage $admin_page;
    private DemoPage $demo_page;
    private ProfileIntegration $profile_integration;

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

        $this->nostr_service     = new NostrService();
        $this->key_manager       = new KeyManager( $this->nostr_service );
        $this->relay_publisher   = new RelayPublisher( self::DEFAULT_RELAYS );
        $this->rest_controller   = new SignEventController( $this->key_manager, $this->nostr_service, $this->relay_publisher );
        $this->import_controller = new ImportKeyController( $this->key_manager, $this->nostr_service );
        $this->admin_page        = new AdminPage( $this->nostr_service );
        $this->demo_page         = new DemoPage();
        $this->profile_integration = new ProfileIntegration( $this->key_manager, $this->nostr_service, self::DEFAULT_RELAYS );

        add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_assets' ], 0 );
        $this->profile_integration->boot();

        add_action( 'admin_notices', [ $this, 'maybe_render_master_key_notice' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_gmp_notice' ] );
        add_action( 'plugins_loaded', [ $this, 'init_hooks' ] );
        add_action( 'user_register', [ $this, 'handle_user_register' ], 10, 1 );
        add_action( 'rest_api_init', [ $this->rest_controller, 'register_routes' ] );
        add_action( 'rest_api_init', [ $this->import_controller, 'register_routes' ] );
        add_action( 'admin_menu', [ $this->admin_page, 'register_admin_page' ] );

        $this->demo_page->boot();

        register_activation_hook( NOSTR_SIGNER_PLUGIN_FILE, [ $this, 'handle_activation' ] );
        register_deactivation_hook( NOSTR_SIGNER_PLUGIN_FILE, [ $this, 'handle_deactivation' ] );
    }

    public function register_admin_assets(): void
    {
        if ( ! wp_script_is( 'nostr-tools', 'registered' ) ) {
            wp_register_script(
                'nostr-tools',
                'https://unpkg.com/nostr-tools@2.3.1/lib/nostr-tools.min.js',
                [],
                '2.3.1',
                true
            );
        }

        if ( ! wp_script_is( 'nostr-signer-import', 'registered' ) ) {
            wp_register_script(
                'nostr-signer-import',
                NOSTR_SIGNER_PLUGIN_URL . 'assets/js/nostr-signer-import.js',
                [ 'nostr-tools' ],
                NOSTR_SIGNER_PLUGIN_VERSION,
                true
            );
        }

        if ( ! wp_script_is( 'nostr-signer-profile', 'registered' ) ) {
            wp_register_script(
                'nostr-signer-profile',
                NOSTR_SIGNER_PLUGIN_URL . 'assets/js/nostr-signer-profile.js',
                [ 'nostr-tools' ],
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

        $message  = __( 'Bitte definieren Sie den NOSTR_SIGNER_MASTER_KEY in Ihrer wp-config.php, um den Nostr Signer zu aktivieren.', 'nostr-signer' );
        $example  = __( "define('NOSTR_SIGNER_MASTER_KEY', 'ihr-sehr-sicherer-zufaelliger-schluessel-hier');", 'nostr-signer' );
        echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p><p><code>' . esc_html( $example ) . '</code></p></div>';
    }

    public function maybe_render_gmp_notice(): void
    {
        if ( $this->gmp_available || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $message = __( 'Die PHP-Erweiterung GMP ist nicht aktiv. Die Nostr-Bibliothek benoetigt GMP fuer kryptografische Operationen.', 'nostr-signer' );
        echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
    }

    public function handle_activation(): void
    {
        if ( ! Crypto::is_master_key_available() ) {
            deactivate_plugins( plugin_basename( NOSTR_SIGNER_PLUGIN_FILE ) );
            wp_die( esc_html__( 'Aktivierung abgebrochen: Bitte setzen Sie zuerst den NOSTR_SIGNER_MASTER_KEY in der wp-config.php.', 'nostr-signer' ) );
        }

        if ( ! $this->gmp_available ) {
            deactivate_plugins( plugin_basename( NOSTR_SIGNER_PLUGIN_FILE ) );
            wp_die( esc_html__( 'Aktivierung abgebrochen: Die PHP-Erweiterung GMP ist erforderlich.', 'nostr-signer' ) );
        }

        $this->demo_page->register_rewrite();
        flush_rewrite_rules();

        $this->key_manager->ensure_blog_key_exists();
    }

    public function handle_deactivation(): void
    {
        flush_rewrite_rules();
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
}
