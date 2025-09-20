<?php

namespace NostrSigner\CLI;

use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\Plugin;
use NostrSigner\RotationManager;
use NostrSigner\NostrService;
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
    private RotationManager $rotation_manager;

    public function __construct( KeyManager $key_manager, RotationManager $rotation_manager )
    {
        $this->key_manager      = $key_manager;
        $this->rotation_manager = $rotation_manager;
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
        if ( ! $file ) {
            $date_str = date( 'Ymd_His' );
            $file     = "nostr_signer_backup_{$date_str}.json";
            WP_CLI::error( "File path is required. e.g. wp nostrsigner backup {$file}" );
        }

        $data = [
            'generated_at' => time(),
            'blog'         => [
                'npub'           => get_option( KeyManager::OPTION_BLOG_NPUB ),
                'encrypted_nsec' => get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC ),
            ],
            'users'        => [],
        ];

        $users = get_users( [ 'fields' => [ 'ID', 'user_login', 'user_email' ] ] );
        foreach ( $users as $user ) {
            $enc  = get_user_meta( $user->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            $npub = get_user_meta( $user->ID, KeyManager::META_NPUB, true );
            if ( $enc ) {
                $data['users'][] = [
                    'id'              => $user->ID,
                    'login'           => $user->user_login,
                    'email'           => $user->user_email,
                    'npub'            => $npub,
                    'encrypted_nsec'  => $enc,
                ];
            }
        }

        $json = wp_json_encode( $data );
        if ( ! is_string( $json ) || file_put_contents( $file, $json ) === false ) {
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
        WP_CLI::line( "Suggested new key (hex): {$key}" );
        WP_CLI::line( "Add it to wp-config.php as define('NOSTR_SIGNER_KEY_VX', '{$key}');" );
        if ( isset( $assoc['show'] ) ) {
            WP_CLI::line( "Raw: {$key}" );
        }
    }

    /**
     * Re-encrypt stored encrypted nsec values using a new master key (legacy helper).
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

        if ( ! method_exists( '\\NostrSigner\\Crypto', 'recryptAll' ) ) {
            WP_CLI::error( 'Crypto::recryptAll method not available. Please update the plugin.' );
        }

        try {
            $count = Crypto::recryptAll( $old_key, $new_key );
            WP_CLI::success( "Re-encrypted {$count} entries." );
        } catch ( \Throwable $throwable ) {
            WP_CLI::error( 'Recrypt failed: ' . $throwable->getMessage() );
        }
    }

    /**
     * Re-wrap stored envelopes with the currently active KEK.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Number of records per batch (default 200).
     *
     * [--reset]
     * : Reset the rotation state before processing.
     */
    public function rotate( $args, $assoc )
    {
        if ( ! Crypto::is_master_key_available() ) {
            WP_CLI::error( 'Key configuration is incomplete. Please configure NOSTR_SIGNER_MASTER_KEY and KEK constants.' );
        }

        if ( isset( $assoc['reset'] ) ) {
            $this->rotation_manager->reset_state();
            WP_CLI::log( 'Rotation state reset.' );
        }

        $limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 200;

        try {
            $updated = $this->rotation_manager->run_batch( $limit );
        } catch ( \Throwable $throwable ) {
            WP_CLI::error( 'Rotation failed: ' . $throwable->getMessage() );
            return;
        }

        WP_CLI::success( sprintf( 'Batch finished ? %d envelopes checked / rewrapped.', $updated ) );

        $state = get_option( 'nostr_signer_rotation_state', [] );
        $done  = is_array( $state ) && ! empty( $state['done_users'] ) && ! empty( $state['done_options'] );

        if ( $done ) {
            WP_CLI::log( 'All envelopes are within the allowed key versions.' );
        } else {
            WP_CLI::log( 'More batches pending. Re-run the command or wait for the cron job.' );
        }
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
            foreach ( $data['users'] as $user ) {
                if ( isset( $user['id'], $user['encrypted_nsec'] ) ) {
                    update_user_meta( (int) $user['id'], KeyManager::META_ENCRYPTED_NSEC, $user['encrypted_nsec'] );
                    if ( isset( $user['npub'] ) ) {
                        update_user_meta( (int) $user['id'], KeyManager::META_NPUB, $user['npub'] );
                    }
                }
            }
        }

        WP_CLI::success( 'Restore complete.' );
    }
}

WP_CLI::add_command( 'nostrsigner', function( $args, $assoc ) {
    $service      = new NostrService();
    $key_manager  = new KeyManager( $service );
    $plugin       = Plugin::instance();
    $rotation     = $plugin->get_rotation_manager();
    $commands     = new Commands( $key_manager, $rotation );

    $sub       = $args[0] ?? null;
    $remaining = array_slice( $args, 1 );

    switch ( $sub ) {
        case 'backup':
            return $commands->backup( $remaining, $assoc );
        case 'keygen':
            return $commands->keygen( $remaining, $assoc );
        case 'recrypt':
            return $commands->recrypt( $remaining, $assoc );
        case 'rotate':
            return $commands->rotate( $remaining, $assoc );
        case 'restore':
            return $commands->restore( $remaining, $assoc );
        default:
            WP_CLI::line( 'Available subcommands: backup, keygen, recrypt, rotate, restore' );
            return;
    }
} );
