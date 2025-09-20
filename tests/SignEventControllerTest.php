<?php

namespace NostrSigner\Tests;

use Brain\Monkey\Functions;
use Mockery;
use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\Rest\SignEventController;
use NostrSigner\RelayPublisher;
use NostrSigner\NostrService;

class SignEventControllerTest extends TestCase
{
    public function testHandleSignEventAddsRTagAndReturnsSignature(): void
    {
        $userId = 123;
        $nsec   = 'nsec1signingsecret';
        $encrypted = Crypto::encrypt( $nsec );
        $npub = 'npub1example';

        Functions\expect( 'wp_verify_nonce' )->once()->with( 'valid', 'wp_rest' )->andReturn( true );
        Functions\expect( 'get_current_user_id' )->andReturn( $userId );
        Functions\expect( 'get_author_posts_url' )->once()->with( $userId )->andReturn( 'https://example.com/authors/123' );
        Functions\expect( 'home_url' )->never();
        Functions\expect( '__' )->zeroOrMoreTimes()->andReturnUsing(function () { return ; });

        $keyManager = Mockery::mock( KeyManager::class );
        $keyManager->shouldReceive( 'ensure_user_key_exists' )->once()->with( $userId );
        $keyManager->shouldReceive( 'get_encrypted_user_nsec' )->once()->with( $userId )->andReturn( $encrypted );
        $keyManager->shouldReceive( 'ensure_blog_key_exists' )->never();
        $keyManager->shouldReceive( 'get_encrypted_blog_nsec' )->never();

        $nostrService = Mockery::mock( NostrService::class );
        $nostrService->shouldReceive( 'is_library_available' )->once()->andReturn( true );
        $nostrService->shouldReceive( 'signEvent' )
            ->once()
            ->withArgs( function ( array $event, string $secret ) use ( $nsec ) {
                return $secret === $nsec && isset( $event['kind'], $event['created_at'] );
            } )
            ->andReturnUsing( function ( array $event ) use ( $npub ) {
                $event['sig']    = 'signature';
                $event['pubkey'] = $npub;
                return $event;
            } );

        $relayPublisher = Mockery::mock( RelayPublisher::class );
        $relayPublisher->shouldReceive( 'publish' )->never();

        $controller = new SignEventController( $keyManager, $nostrService, $relayPublisher );

        $request = new \WP_REST_Request(
            [
                'event' => [ 'content' => 'hello world' ],
                'key_type' => 'user',
                'broadcast' => false,
            ],
            [ 'X-WP-Nonce' => 'valid' ]
        );

        $response = $controller->handle_sign_event( $request );
        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $data = $response->get_data();

        $this->assertSame( 'user', $data['key_type'] );
        $this->assertFalse( $data['broadcast'] );
        $this->assertSame( 'signature', $data['event']['sig'] );
        $this->assertSame( $npub, $data['event']['pubkey'] );

        $tags = $data['event']['tags'];
        $this->assertContains( [ 'r', 'https://example.com/authors/123' ], $tags );
    }
}
