<?php

namespace NostrSigner\Tests;

use NostrSigner\Crypto;

class CryptoTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'nsec1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

        $cipher = Crypto::encrypt( $plaintext );
        $this->assertIsString( $cipher );
        $this->assertNotSame( $plaintext, $cipher );

        $decrypted = Crypto::decrypt( $cipher );
        $this->assertSame( $plaintext, $decrypted );
    }

    public function testDecryptLegacyFormat(): void
    {
        $plaintext = 'legacy-nsec-secret';
        $masterKey = hash( 'sha256', NOSTR_SIGNER_MASTER_KEY, true );

        $legacyCipher = Crypto::encrypt_with_custom_key( $plaintext, $masterKey );
        $this->assertSame( $plaintext, Crypto::decrypt( $legacyCipher ) );
    }

    public function testRewrapWithCustomKeysUpdatesEnvelopeVersion(): void
    {
        $plaintext = 'nsec1aaaalegacytest';
        $binaryV1  = base64_decode( substr( NOSTR_SIGNER_KEY_V1, 7 ) );
        $binaryV2  = base64_decode( substr( NOSTR_SIGNER_KEY_V2, 7 ) );

        $method = new \ReflectionMethod( Crypto::class, 'encrypt_with_explicit_kek' );
        $method->setAccessible( true );
        $legacyEnvelope = $method->invoke( null, $plaintext, $binaryV1, 1 );

        $rewrapped = Crypto::rewrap_envelope_with_custom_keys( $legacyEnvelope, $binaryV1, $binaryV2, NOSTR_SIGNER_ACTIVE_KEY_VERSION );
        $this->assertIsString( $rewrapped );
        $this->assertSame( $plaintext, Crypto::decrypt( $rewrapped ) );

        $decoded = json_decode( base64_decode( $rewrapped ), true );
        $this->assertSame( NOSTR_SIGNER_ACTIVE_KEY_VERSION, $decoded['kv'] ?? null );
    }
}
