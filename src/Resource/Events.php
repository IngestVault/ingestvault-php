<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Event;
use IngestVault\EventSummary;
use IngestVault\IdempotencyKey;
use IngestVault\Page;
use IngestVault\Payload;
use IngestVault\PreparedEvent;

final class Events extends Group
{
    /**
     * @throws \JsonException When the payload cannot be encoded as JSON.
     */
    public function send(string $type, mixed $payload = Payload::None, ?string $idempotencyKey = null): Event
    {
        return $this->sendPrepared(new PreparedEvent($type, $payload, $idempotencyKey));
    }

    public function sendPrepared(PreparedEvent $event): Event
    {
        return self::event(
            $this->transport->request('POST', '/events', body: $event->body, headers: ['Idempotency-Key' => $event->idempotencyKey]),
        );
    }

    /**
     * @return Page<EventSummary>
     */
    public function list(
        ?int $pageSize = null,
        ?string $cursor = null,
        ?string $type = null,
        ?string $typeRegistrationStatus = null,
        ?string $receivedAfter = null,
        ?string $receivedBefore = null,
        ?bool $replay = null,
    ): Page {
        return self::page(
            $this->transport->request('GET', '/events', [
                'page_size' => $pageSize,
                'cursor' => $cursor,
                'type' => $type,
                'type_registration_status' => $typeRegistrationStatus,
                'received_after' => $receivedAfter,
                'received_before' => $receivedBefore,
                // A bool would reach the query string as 1 or 0.
                'replay' => $replay === null ? null : ($replay ? 'true' : 'false'),
            ]),
            EventSummary::fromArray(...),
            'a page of events',
        );
    }

    /**
     * @return \Generator<int, EventSummary, mixed, void>
     */
    public function all(
        ?int $pageSize = null,
        ?string $type = null,
        ?string $typeRegistrationStatus = null,
        ?string $receivedAfter = null,
        ?string $receivedBefore = null,
        ?bool $replay = null,
    ): \Generator {
        $cursor = null;
        do {
            $page = $this->list($pageSize, $cursor, $type, $typeRegistrationStatus, $receivedAfter, $receivedBefore, $replay);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    public function get(string $eventId): Event
    {
        return self::event($this->transport->request('GET', self::path($eventId)));
    }

    public function replay(string $eventId, ?string $idempotencyKey = null): Event
    {
        return self::event(
            $this->transport->request('POST', self::path($eventId) . '/replay', headers: ['Idempotency-Key' => $idempotencyKey ?? IdempotencyKey::generate()]),
        );
    }

    private static function path(string $eventId): string
    {
        return '/events/' . rawurlencode($eventId);
    }
}
