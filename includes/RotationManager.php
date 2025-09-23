<?php

namespace NostrSigner;

use WP_User_Query;

class RotationManager {
    private const STATE_OPTION = 'nostr_signer_rotation_state';
    private const LAST_OK_OPTION = 'nostr_signer_rotation_last_ok';
    private const DEFAULT_LIMIT = 200;

    public function register_hooks(): void {
        add_action( 'nostr_signer_rotate_event', [ $this, 'handle_cron' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_old_key_notice' ] );
    }

    public function schedule_events(): void {
        if ( ! wp_next_scheduled( 'nostr_signer_rotate_event' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'nostr_signer_rotate_event' );
        }
    }

    public function clear_schedule(): void {
        wp_clear_scheduled_hook( 'nostr_signer_rotate_event' );
    }

    public function handle_cron(): void {
        $this->run_batch();
    }
    public function reset_state( ?int $target_version = null ): void
    {
        if ( $target_version === null ) {
            $target_version = Crypto::get_active_key_version();
        }

        $state = [
            'target_version' => $target_version,
            'user_paged'     => 1,
            'done_users'     => false,
            'done_options'   => false,
        ];

        update_option( self::STATE_OPTION, $state, false );
    }

    public function run_batch( int $limit = self::DEFAULT_LIMIT ): int {
        if ( $limit <= 0 ) {
            $limit = self::DEFAULT_LIMIT;
        }

        if ( ! Crypto::is_master_key_available() ) {
            return 0;
        }

        $active_version = Crypto::get_active_key_version();
        $state          = get_option( self::STATE_OPTION, [] );
        if ( ! is_array( $state ) || ! isset( $state['target_version'] ) || (int) $state['target_version'] !== $active_version ) {
            $this->reset_state( $active_version );
            $state = get_option( self::STATE_OPTION, [] );
        }

        $updated = 0;

        if ( empty( $state['done_users'] ) ) {
            $updated += $this->rewrap_user_batch( $state, $limit );
            $state = get_option( self::STATE_OPTION, $state );
            if ( empty( $state['done_users'] ) ) {
                return $updated;
            }
        }

        if ( empty( $state['done_options'] ) ) {
            $updated += $this->rewrap_option();
            $state = get_option( self::STATE_OPTION, $state );
        }

        if ( ! empty( $state['done_users'] ) && ! empty( $state['done_options'] ) ) {
            update_option( self::LAST_OK_OPTION, time(), false );
        }

        return $updated;
    }

    private function rewrap_user_batch( array $state, int $limit ): int {
        $paged = isset( $state['user_paged'] ) ? max( 1, (int) $state['user_paged'] ) : 1;
        $allowed_versions = Crypto::get_allowed_key_versions();
        $min_allowed      = min( $allowed_versions );

        $query = new WP_User_Query(
            [
                'number'   => $limit,
                'paged'    => $paged,
                'meta_key' => KeyManager::META_ENCRYPTED_NSEC,
                'fields'   => [ 'ID' ],
            ]
        );
        $users   = $query->get_results();
        $updated = 0;

        foreach ( (array) $users as $user ) {
            $user_id    = (int) $user->ID;
            $ciphertext = get_user_meta( $user_id, KeyManager::META_ENCRYPTED_NSEC, true );
            if ( ! is_string( $ciphertext ) || $ciphertext === '' ) {
                continue;
            }

            $version = $this->extract_envelope_version( $ciphertext );
            if ( $version === null ) {
                continue;
            }

            if ( $version >= $min_allowed && in_array( $version, $allowed_versions, true ) ) {
                continue;
            }

            $result = Crypto::maybe_rewrap_to_active( $ciphertext );
            if ( $result !== null && ! empty( $result['changed'] ) && isset( $result['ciphertext'] ) ) {
                update_user_meta( $user_id, KeyManager::META_ENCRYPTED_NSEC, $result['ciphertext'] );
                $updated++;
            }
        }

        if ( count( $users ) < $limit ) {
            $state['done_users'] = true;
        } else {
            $state['user_paged'] = $paged + 1;
        }

        update_option( self::STATE_OPTION, $state, false );
        return $updated;
    }

    private function rewrap_option(): int {
        $state          = get_option( self::STATE_OPTION, [] );
        $allowed        = Crypto::get_allowed_key_versions();
        $min_allowed    = min( $allowed );
        $blog_cipher    = get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC );
        $updated        = 0;

        if ( is_string( $blog_cipher ) && $blog_cipher !== '' ) {
            $version = $this->extract_envelope_version( $blog_cipher );
            if ( $version !== null && ( $version < $min_allowed || ! in_array( $version, $allowed, true ) ) ) {
                $result = Crypto::maybe_rewrap_to_active( $blog_cipher );
                if ( $result !== null && ! empty( $result['changed'] ) && isset( $result['ciphertext'] ) ) {
                    update_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC, $result['ciphertext'], false );
                    $updated++;
                }
            }
        }

        $state['done_options'] = true;
        update_option( self::STATE_OPTION, $state, false );

        return $updated;
    }

    private function extract_envelope_version( string $ciphertext ): ?int {
        $json = base64_decode( $ciphertext, true );
        if ( $json === false ) {
            return null;
        }

        $envelope = json_decode( $json, true );
        if ( ! is_array( $envelope ) || ! isset( $envelope['kv'] ) ) {
            return null;
        }

        return (int) $envelope['kv'];
    }

    public function maybe_render_old_key_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! Crypto::is_master_key_available() ) {
            return;
        }

        $allowed = Crypto::get_allowed_key_versions();
        if ( empty( $allowed ) ) {
            return;
        }

        $min_allowed = min( $allowed );

        if ( $this->has_outdated_envelopes( $min_allowed ) ) {
            return;
        }

        // Check if notice was already dismissed
        $dismissed = get_user_meta( get_current_user_id(), 'nostr_signer_key_rotation_notice_dismissed', true );
        if ( $dismissed ) {
            return;
        }

        $message = __( 'Nostr Signer: Alle Envelopes wurden auf zulässige Key-Versionen umgewandelt. Ältere KEKs können aus der wp-config.php entfernt werden.', 'nostr-signer' );

        echo '<div class="notice notice-success is-dismissible" data-notice-type="key_rotation_success">';
        echo '<p>' . esc_html( $message ) . '</p>';
        echo '</div>';

        // Add JavaScript to handle dismiss
        add_action( 'admin_footer', [ $this, 'add_dismiss_javascript' ] );
    }

    public function add_dismiss_javascript(): void {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.notice[data-notice-type="key_rotation_success"]').on('click', '.notice-dismiss', function() {
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

    private function has_outdated_envelopes( int $min_allowed ): bool {
        $query = new WP_User_Query(
            [
                'number'   => 50,
                'meta_key' => KeyManager::META_ENCRYPTED_NSEC,
                'fields'   => [ 'ID' ],
            ]
        );

        foreach ( (array) $query->get_results() as $user ) {
            $cipher = get_user_meta( $user->ID, KeyManager::META_ENCRYPTED_NSEC, true );
            if ( ! is_string( $cipher ) || $cipher === '' ) {
                continue;
            }
            $version = $this->extract_envelope_version( $cipher );
            if ( $version !== null && $version < $min_allowed ) {
                return true;
            }
        }

        $blog_cipher = get_option( KeyManager::OPTION_BLOG_ENCRYPTED_NSEC );
        if ( is_string( $blog_cipher ) && $blog_cipher !== '' ) {
            $version = $this->extract_envelope_version( $blog_cipher );
            if ( $version !== null && $version < $min_allowed ) {
                return true;
            }
        }

        return false;
    }
}
