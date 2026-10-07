<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Delivery;
use IngestVault\DeliveryWithAttempts;
use IngestVault\Event;
use IngestVault\IdempotencyKey;
use IngestVault\Page;

final class Deliveries extends Group
{
    /**
     * @return Page<Delivery>
     */
    public function list(
        ?int $pageSize = null,
        ?string $cursor = null,
        ?string $endpointId = null,
        ?string $eventId = null,
        ?string $eventType = null,
        ?string $status = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
    ): Page {
        return self::page(
            $this->transport->request('GET', '/deliveries', [
                'page_size' => $pageSize,
                'cursor' => $cursor,
                'endpoint_id' => $endpointId,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => $status,
                'created_after' => $createdAfter,
                'created_before' => $createdBefore,
            ]),
            Delivery::fromArray(...),
            'a page of deliveries',
        );
    }

    /**
     * @return \Generator<int, Delivery, mixed, void>
     */
    public function all(
        ?int $pageSize = null,
        ?string $endpointId = null,
        ?string $eventId = null,
        ?string $eventType = null,
        ?string $status = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
    ): \Generator {
        $cursor = null;
        do {
            $page = $this->list($pageSize, $cursor, $endpointId, $eventId, $eventType, $status, $createdAfter, $createdBefore);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    public function get(string $deliveryId): DeliveryWithAttempts
    {
        return self::item(
            $this->transport->request('GET', self::path($deliveryId)),
            DeliveryWithAttempts::fromArray(...),
            'a delivery',
        );
    }

    public function replay(string $deliveryId, ?string $idempotencyKey = null): Event
    {
        return self::event(
            $this->transport->request('POST', self::path($deliveryId) . '/replay', headers: ['Idempotency-Key' => $idempotencyKey ?? IdempotencyKey::generate()]),
        );
    }

    private static function path(string $deliveryId): string
    {
        return '/deliveries/' . rawurlencode($deliveryId);
    }
}
