<?php

namespace NostrSigner;

use RuntimeException;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

class NostrService
{
    public function is_library_available(): bool
    {
        return class_exists( Key::class ) && class_exists( Sign::class ) && class_exists( Event::class );
    }

    public function ensure_library_available(): void
    {
        if ( ! $this->is_library_available() ) {
            throw new RuntimeException( 'Required Nostr PHP library classes are not available.' );
        }
    }

    public function generateKeyPair(): array
    {
        $this->ensure_library_available();

        $key         = new Key();
        $private_hex = $key->generatePrivateKey();
        $public_hex  = $key->getPublicKey( $private_hex );

        return [
            'nsec' => $key->convertPrivateKeyToBech32( $private_hex ),
            'npub' => $key->convertPublicKeyToBech32( $public_hex ),
        ];
    }

    public function signEvent( array $event_data, string $nsec ): array
    {
        $this->ensure_library_available();

        $event = new Event();

        if ( isset( $event_data['kind'] ) ) {
            $event->setKind( (int) $event_data['kind'] );
        }

        if ( isset( $event_data['created_at'] ) ) {
            $event->setCreatedAt( (int) $event_data['created_at'] );
        }

        if ( isset( $event_data['content'] ) ) {
            $event->setContent( (string) $event_data['content'] );
        }

        if ( isset( $event_data['tags'] ) && is_array( $event_data['tags'] ) ) {
            $event->setTags( $event_data['tags'] );
        }

        // Pre-set ID if provided.
        if ( isset( $event_data['id'] ) ) {
            $event->setId( (string) $event_data['id'] );
        }

        $signer = new Sign();
        $signer->signEvent( $event, $nsec );

        return [
            'id'         => $event->getId(),
            'pubkey'     => $event->getPublicKey(),
            'sig'        => $event->getSignature(),
            'kind'       => $event->getKind(),
            'created_at' => $event->getCreatedAt(),
            'tags'       => $event->getTags(),
            'content'    => $event->getContent(),
        ];
    }

    public function convertBech32ToHex( ?string $key ): ?string
    {
        if ( $key === null || $key === '' ) {
            return null;
        }

        if ( ! $this->is_library_available() ) {
            return null;
        }

        try {
            $converter = new Key();
            return $converter->convertToHex( $key );
        } catch ( \Throwable ) {
            return null;
        }
    }

    public function deriveNpubFromNsec( string $nsec ): ?string
    {
        if ( ! $this->is_library_available() ) {
            return null;
        }

        try {
            $converter   = new Key();
            $private_hex = $converter->convertToHex( $nsec );
            $public_hex  = $converter->getPublicKey( $private_hex );
            return $converter->convertPublicKeyToBech32( $public_hex );
        } catch ( \Throwable ) {
            return null;
        }
    }
}
