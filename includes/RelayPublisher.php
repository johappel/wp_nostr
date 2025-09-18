<?php

namespace NostrSigner;

use RuntimeException;
use swentel\nostr\Event\Event;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Relay\RelaySet;

class RelayPublisher
{
    public function __construct( private array $relays = [] ) {
    }

    public function getRelays(): array
    {
        return $this->relays;
    }

    public function publish( array $event ): array
    {
        if ( empty( $this->relays ) ) {
            return [];
        }

        try {
            $relay_set = new RelaySet();
            $relay_set->createFromUrls( $this->relays );
            $relay_set->connect( false );

            $nostr_event = new Event();
            $nostr_event
                ->setId( (string) ( $event['id'] ?? '' ) )
                ->setPublicKey( (string) ( $event['pubkey'] ?? '' ) )
                ->setSignature( (string) ( $event['sig'] ?? '' ) )
                ->setKind( (int) ( $event['kind'] ?? 0 ) )
                ->setCreatedAt( (int) ( $event['created_at'] ?? time() ) )
                ->setContent( (string) ( $event['content'] ?? '' ) )
                ->setTags( is_array( $event['tags'] ?? null ) ? $event['tags'] : [] );

            $message = new EventMessage( $nostr_event );
            $relay_set->setMessage( $message );
            $responses = $relay_set->send();
            $relay_set->disconnect();

            return is_array( $responses ) ? $responses : [];
        } catch ( RuntimeException $exception ) {
            return [ 'error' => $exception->getMessage() ];
        } catch ( \Throwable $throwable ) {
            return [ 'error' => $throwable->getMessage() ];
        }
    }
}
