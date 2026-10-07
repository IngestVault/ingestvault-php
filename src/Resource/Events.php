<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Event;
use IngestVault\PreparedEvent;

final class Events extends Group
{
    /**
     * @throws \JsonException When the payload cannot be encoded as JSON.
     */
    public function send(string $type, mixed $payload = null, ?string $idempotencyKey = null): Event
    {
        return $this->sendPrepared(new PreparedEvent($type, $payload, $idempotencyKey));
    }

    public function sendPrepared(PreparedEvent $event): Event
    {
        return self::item(
            $this->transport->request('POST', '/events', body: $event->body, headers: ['Idempotency-Key' => $event->idempotencyKey]),
            Event::fromArray(...),
            'an event',
        );
    }
}
