<?php

namespace NostrSigner\Tests;

use Brain\Monkey\Functions;
use Mockery;
use NostrSigner\Crypto;
use NostrSigner\KeyManager;
use NostrSigner\Rest\ImportKeyController;
use NostrSigner\NostrService;

class ImportKeyControllerTest extends TestCase
{
    public function testHandleImportStoresEncryptedUserKey(): void
    {
        $npub   = 'npub1testnpubvalue';
        $nsec   = 'nsec1testsecretvalue';
        $userId = 99;
        $sessionToken = 'session-token';

        Functions\expect( 'wp_verify_nonce' )->once()->with( 'valid', 'wp_rest' )->andReturn( true );
        Functions\expect( 'wp_get_session_token' )->once()->andReturn( $sessionToken );
        Functions\expect( 'current_user_can' )->never();
        Functions\expect( 'get_current_user_id' )->once()->andReturn( $userId );
        Functions\expect( 'update_user_meta' )
            ->once()
            ->with( $userId, KeyManager::META_NPUB, $npub )
            ->andReturn( true );
        Functions\expect( 'update_user_meta' )
            ->once()
            ->withArgs( function ( $uid, $key, $value ) use ( $userId, $nsec ) {
                if ( $uid !== $userId || $key !== KeyManager::META_ENCRYPTED_NSEC ) {
                    return false;
                }

                return Crypto::decrypt( $value ) === $nsec;
            } )
            ->andReturn( true );
        Functions\expect( 'update_option' )->never();
        Functions\expect( '__' )->zeroOrMoreTimes()->andReturnUsing(function ($text) { return $text; });

        $nostrService = Mockery::mock( NostrService::class );
        $nostrService->shouldReceive( 'is_library_available' )->once()->andReturn( true );
        $nostrService->shouldReceive( 'deriveNpubFromNsec' )->once()->with( $nsec )->andReturn( $npub );

        $keyManager = new KeyManager( $nostrService );
        $controller = new ImportKeyController( $keyManager, $nostrService );

        $tempKeyHex   = hash_hmac( 'sha256', $sessionToken, NOSTR_SIGNER_MASTER_KEY );
        $tempKey      = hex2bin( $tempKeyHex );
        $clientCipher = Crypto::encrypt_with_custom_key( $nsec, $tempKey );

        $request = new \WP_REST_Request(
            [
                'target'         => 'user',
                'encrypted_nsec' => $clientCipher,
                'npub'           => $npub,
            ],
            [ 'X-WP-Nonce' => 'valid' ]
        );

        $response = $controller->handle_import( $request );
        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertSame( $npub, $data['npub'] );
        $this->assertSame( 'user', $data['target'] );
    }
}
