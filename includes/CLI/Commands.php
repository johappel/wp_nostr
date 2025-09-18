<?php

namespace NostrSigner\CLI;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * WP-CLI Commands for Nostr Signer.
 */
class Commands
{
    private KeyManager $key_manager;

    public function __construct( KeyManager $key_manager )
    {
        $this->key_manager = $key_manager;
    }

    /**
     * Export encrypted nsec values to a JSON file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to write the backup JSON file.
     */
    public function backup( $args, $assoc )
    {
        [$file] = $args;

        $data = [
            'generated_at' => time(),
            'blog' => [
                'npub' => get_option( KeyManager::OPTION_BLOG_NPUB ),
                'encrypted_nsec' => get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC ),
            ],
            'users' => [],
        ];

        $users = get_users( [ 'fields' => [ 'ID', 'user_login', 'user_email' ] ] );
        foreach ( $users as $u ) {
            $enc = get_user_meta( $u->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            $npub = get_user_meta( $u->ID, KeyManager::META_NPUB, true );
            if ( $enc ) {
                $data['users'][] = [
                    'id' => $u->ID,
                    'login' => $u->user_login,
                    'email' => $u->user_email,
                    'npub' => $npub,
                    'encrypted_nsec' => $enc,
                ];
            }
        }

        $json = wp_json_encode( $data );
        if ( file_put_contents( $file, $json ) === false ) {
            WP_CLI::error( "Failed to write backup to {$file}" );
        }

        WP_CLI::success( "Backup written to {$file}" );
    }

    /**
     * Generate a suggested new master key and print to console (do NOT store it in repo).
     *
     * @synopsis [--show]
     */
    public function keygen( $args, $assoc )
    {
        $key = bin2hex( random_bytes( 32 ) );
        WP_CLI::line( "Suggested new master key (hex): {$key}" );
        WP_CLI::line( "Add it to wp-config.php as define('NOSTR_SIGNER_MASTER_KEY', '{$key}');" );
        if ( isset( $assoc['show'] ) ) {
            WP_CLI::line( "Raw: {$key}" );
        }
    }

    /**
     * Re-encrypt stored encrypted nsec values using a new master key.
     *
     * ## OPTIONS
     *
     * <old_key>
     * : Old master key (hex)
     * <new_key>
     * : New master key (hex)
     */
    public function recrypt( $args, $assoc )
    {
        [$old_key, $new_key] = $args;

        if ( empty( $old_key ) || empty( $new_key ) ) {
            WP_CLI::error( 'Old and new master keys are required.' );
        }

        // Use temporary override of constant via Crypto helper if available.
        if ( ! method_exists( '\NostrSigner\\Crypto', 'recryptAll' ) ) {
            WP_CLI::error( 'Crypto::recryptAll method not available. Please update the plugin.' );
        }

        try {
            $count = Crypto::recryptAll( $old_key, $new_key );
            WP_CLI::success( "Re-encrypted {$count} entries." );
        } catch ( \Exception $e ) {
            WP_CLI::error( 'Recrypt failed: ' . $e->getMessage() );
        }
    }

    /**
     * Rotate: shorthand to keygen + recrypt (interactive guidance).
     *
     * ## OPTIONS
     *
     * [--old=<old_key>]
     * [--new=<new_key>]
     */
    public function rotate( $args, $assoc )
    {
        $old = $assoc['old'] ?? null;
        $new = $assoc['new'] ?? null;

        if ( ! $old ) {
            WP_CLI::warning( 'You did not provide --old; rotation requires the old master key to re-encrypt existing values.' );
            return;
        }

        if ( ! $new ) {
            WP_CLI::warning( 'You did not provide --new; generate one with `wp nostrsigner keygen` and set it in wp-config.php before running recrypt.' );
            return;
        }

        WP_CLI::line( 'Starting rotation: re-encrypting stored nsecs...' );
        $this->recrypt( [ $old, $new ], [] );
    }

    /**
     * Restore from backup JSON file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to backup JSON file.
     */
    public function restore( $args, $assoc )
    {
        [$file] = $args;

        if ( ! file_exists( $file ) ) {
            WP_CLI::error( "File not found: {$file}" );
        }

        $json = file_get_contents( $file );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            WP_CLI::error( 'Invalid backup file.' );
        }

        if ( isset( $data['blog'] ) ) {
            if ( isset( $data['blog']['npub'] ) ) {
                update_option( KeyManager::OPTION_BLOG_NPUB, $data['blog']['npub'] );
            }
            if ( isset( $data['blog']['encrypted_nsec'] ) ) {
                update_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC, $data['blog']['encrypted_nsec'] );
            }
        }

        if ( isset( $data['users'] ) && is_array( $data['users'] ) ) {
            foreach ( $data['users'] as $u ) {
                if ( isset( $u['id'], $u['encrypted_nsec'] ) ) {
                    update_user_meta( (int) $u['id'], KeyManager::META_ENCRYPTED_NSEC, $u['encrypted_nsec'] );
                    if ( isset( $u['npub'] ) ) {
                        update_user_meta( (int) $u['id'], KeyManager::META_NPUB, $u['npub'] );
                    }
                }
            }
        }

        WP_CLI::success( 'Restore complete.' );
    }
}

// Register the commands with WP-CLI
WP_CLI::add_command( 'nostrsigner', function( $args, $assoc ) {
    $key_manager = new \NostrSigner\KeyManager( new \NostrSigner\NostrService() );
    $cmd = new Commands( $key_manager );

    // Map subcommands
    $sub = $args[0] ?? null;
    $remaining = array_slice( $args, 1 );

    switch ( $sub ) {
        case 'backup':
            return $cmd->backup( $remaining, $assoc );
        case 'keygen':
            return $cmd->keygen( $remaining, $assoc );
        case 'recrypt':
            return $cmd->recrypt( $remaining, $assoc );
        case 'rotate':
            return $cmd->rotate( $remaining, $assoc );
        case 'restore':
            return $cmd->restore( $remaining, $assoc );
        default:
            WP_CLI::line( 'Available subcommands: backup, keygen, recrypt, rotate, restore' );
            return;
    }
} );
