<?php
namespace NostrSigner\Frontend; 

define( 'NOSTR_CALENDAR_APP_DIR', WP_CONTENT_DIR . '/nostr-apps/nostr-calendar-app/' );
define( 'NOSTR_CALENDAR_APP_BASE_URL', home_url( '/wp-content/nostr-apps/nostr-calendar-app/' ) );


class CalendarPage
{
    private const QUERY_VAR = 'nostr_signer_calendar';

    public function boot(): void
    {
        add_action( 'init', [ $this, 'register_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render' ] );
        
    }

    public function register_rewrite(): void
    {
        add_rewrite_rule( '^nostr-calendar/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
        add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
    }

    public function register_query_var( array $vars ): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_render(): void
    {
        
        if ( (int) get_query_var( self::QUERY_VAR ) !== 1 ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        
        $default_relays = (array) apply_filters(
            'nostr_signer_default_relays',
            [
                'wss://relay-rpi.edufeed.org',
                // 'wss://relay.damus.io',
                // 'wss://relay.snort.social',
            ]
        );

        $default_relays = array_values(
            array_filter(
                array_map(
                    static fn( $relay ) => is_string( $relay ) ? trim( $relay ) : '',
                    $default_relays
                ),
                static fn( $relay ) => $relay !== ''
            )
        );
        
        if ( empty( $default_relays ) ) {
            $default_relays = [
                'wss://relay.damus.io',
                'wss://relay.snort.social',
            ];
        }
        
        $config = [
            'meUrl'         => rest_url( 'nostr-signer/v1/me' ),
            'signUrl'       => rest_url( 'nostr-signer/v1/sign-event' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'loginUrl'      => wp_login_url( home_url( '/nostr-signer' ) ),
            'logoutUrl'     => wp_logout_url( home_url( '/nostr-signer' ) ),
            'apiBase'       => untrailingslashit( rest_url() ),
            'defaultRelays' => $default_relays,
        ];

        if ( preg_match( '#^/nostr-calendar/?$#', $_SERVER['REQUEST_URI'] ?? '' ) ) {
            $html_path = NOSTR_CALENDAR_APP_DIR . 'index.html';
            $html      = file_get_contents( $html_path );
            if ( $html === null ) {
                wp_die( esc_html__( 'Fehler beim Laden der HTML-Datei.', NOSTR_CALENDAR_APP_BASE_URL . 'index.html' ) );
                return;
            }
        } else {
            wp_die( esc_html__( 'Unbekannte Seite', NOSTR_CALENDAR_APP_BASE_URL . '/index.html' ) );
            return;
        }

        // prepare for relative URLs

        // add <base href="https://www.example.com/" /> to head to make relative URLs work
        $base_tag = '<base href="' . esc_url( NOSTR_CALENDAR_APP_BASE_URL ) . '" />';
        $html     = preg_replace( '/<head>/', '<head>' . $base_tag, $html, 1 );
        
        if ( $html === null ) {
            wp_die( esc_html__( 'Fehler beim Verarbeiten der HTML-Datei.', NOSTR_CALENDAR_APP_BASE_URL . 'index.html' ) );
            return;
        }

        // prepare for config injection

        if ( strpos( $html, '__CONFIG_JSON__' ) === false ) {
        
            // inject config as JS object with this structure:
            
            $script_tags = '<script>';
            $script_tags .= 'window.NostrSignerConfig = {};';
            $script_tags .= 'window.NostrSignerConfig.pluginUrl = ' . wp_json_encode( NOSTR_SIGNER_PLUGIN_URL ) . ';';
            $script_tags .= 'window.NostrSignerConfig.signUrl = ' . wp_json_encode( $config['signUrl'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.meUrl = ' . wp_json_encode( $config['meUrl'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.nonce = ' . wp_json_encode( $config['nonce'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.loginUrl = ' . wp_json_encode( $config['loginUrl'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.logoutUrl = ' . wp_json_encode( $config['logoutUrl'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.apiBase = ' . wp_json_encode( $config['apiBase'] ) . ';';
            $script_tags .= 'window.NostrSignerConfig.defaultRelays = ' . wp_json_encode( $config['defaultRelays'] ) . ';';
            $script_tags .= 'window.nostrCalendarWP = {};';
            $script_tags .= 'window.nostrCalendarWP.sso = {"enabled": true};';
            $script_tags .= '</script>';
            $script_tags .= '<script type="module">';
            $script_tags .= 'import { configureNostr, nostr_send, nostr_fetch, nostr_me, nostr_onEvent, login_url, logout_url } from "' . esc_url( NOSTR_SIGNER_PLUGIN_URL ) . 'assets/js/nostr-app.js";';
            $script_tags .= '</script>';

            $html = preg_replace( '/<\/head>/', $script_tags . '</head>', $html, 1 );
        }else{

            $placeholders = [
                '__CONFIG_JSON__' => wp_json_encode( $config, JSON_UNESCAPED_SLASHES ),
                '__PLUGIN_URL__'  => esc_url( NOSTR_SIGNER_PLUGIN_URL ),
            ];
            $html = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $html );
            
        }

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete HTML document.
        exit;
    }
}
