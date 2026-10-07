<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\EventType;
use IngestVault\Page;
use IngestVault\Transport;

final class EventTypes extends Group
{
    /**
     * @return Page<EventType>
     */
    public function list(?int $pageSize = null, ?string $cursor = null): Page
    {
        return self::page(
            $this->transport->request('GET', '/event-types', ['page_size' => $pageSize, 'cursor' => $cursor]),
            EventType::fromArray(...),
            'a page of event types',
        );
    }

    /**
     * @return \Generator<int, EventType, mixed, void>
     */
    public function all(?int $pageSize = null): \Generator
    {
        $cursor = null;
        do {
            $page = $this->list($pageSize, $cursor);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function create(string $name, ?string $description = null): EventType
    {
        return self::item(
            $this->transport->request(
                'POST',
                '/event-types',
                body: Transport::encode(['name' => $name, 'description' => $description]),
                retry: false,
            ),
            EventType::fromArray(...),
            'an event type',
        );
    }

    public function get(string $eventTypeId): EventType
    {
        return self::item(
            $this->transport->request('GET', self::path($eventTypeId)),
            EventType::fromArray(...),
            'an event type',
        );
    }

    /**
     * @param array{description?: ?string} $fields Only the given members change; a null description clears it.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function update(string $eventTypeId, array $fields): EventType
    {
        return self::item(
            $this->transport->request('PATCH', self::path($eventTypeId), body: Transport::encode($fields)),
            EventType::fromArray(...),
            'an event type',
        );
    }

    public function archive(string $eventTypeId): EventType
    {
        return self::item(
            $this->transport->request('POST', self::path($eventTypeId) . '/archive'),
            EventType::fromArray(...),
            'an event type',
        );
    }

    public function unarchive(string $eventTypeId): EventType
    {
        return self::item(
            $this->transport->request('POST', self::path($eventTypeId) . '/unarchive'),
            EventType::fromArray(...),
            'an event type',
        );
    }

    private static function path(string $eventTypeId): string
    {
        return '/event-types/' . rawurlencode($eventTypeId);
    }
}
