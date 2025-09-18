<?php
/**
 * Plugin Name: Nostr Signer
 * Plugin URI: https://example.com/nostr-signer
 * Description: Ermoeglicht das sichere serverseitige Signieren von Nostr-Events fuer Benutzer und den Blog.
 * Version: 1.0
 * Author: [Ihr Name]
 * License: GPL2
 * Text Domain: nostr-signer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOSTR_SIGNER_PLUGIN_VERSION', '1.0' );

define( 'NOSTR_SIGNER_PLUGIN_FILE', __FILE__ );
define( 'NOSTR_SIGNER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOSTR_SIGNER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$nostr_signer_vendor = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $nostr_signer_vendor ) ) {
    require_once $nostr_signer_vendor;
}

spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'NostrSigner\\' ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( 'NostrSigner\\' ) );
    $relative = str_replace( '\\', '/', $relative );
    $path     = NOSTR_SIGNER_PLUGIN_DIR . 'includes/' . $relative . '.php';

    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

NostrSigner\Plugin::instance()->boot();

if ( ! function_exists( 'nostr_signer_encrypt' ) ) {
    function nostr_signer_encrypt( string $plaintext ): string {
        return NostrSigner\Crypto::encrypt( $plaintext );
    }
}

if ( ! function_exists( 'nostr_signer_decrypt' ) ) {
    function nostr_signer_decrypt( string $ciphertext ) {
        return NostrSigner\Crypto::decrypt( $ciphertext );
    }
}