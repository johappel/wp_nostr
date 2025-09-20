<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

if ( ! defined( 'NOSTR_SIGNER_MASTER_KEY' ) ) {
    define( 'NOSTR_SIGNER_MASTER_KEY', 'test-master-key' );
}
if ( ! defined( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION' ) ) {
    define( 'NOSTR_SIGNER_ACTIVE_KEY_VERSION', 2 );
}
if ( ! defined( 'NOSTR_SIGNER_MAX_KEY_VERSIONS' ) ) {
    define( 'NOSTR_SIGNER_MAX_KEY_VERSIONS', 2 );
}
if ( ! defined( 'NOSTR_SIGNER_KEY_V1' ) ) {
    define( 'NOSTR_SIGNER_KEY_V1', 'base64:' . base64_encode( str_repeat( 'A', 32 ) ) );
}
if ( ! defined( 'NOSTR_SIGNER_KEY_V2' ) ) {
    define( 'NOSTR_SIGNER_KEY_V2', 'base64:' . base64_encode( str_repeat( 'B', 32 ) ) );
}


if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function __construct( private string $code = '', private string $message = '', private array $data = [] ) {}

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): array {
            return $this->data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( private $data = null, private int $status = 200 ) {}

        public function get_data() {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        public function __construct( private array $params = [], private array $headers = [] ) {}

        public function get_param( $key ) {
            return $this->params[ $key ] ?? null;
        }

        public function get_header( $key ) {
            return $this->headers[ $key ] ?? null;
        }
    }
}
