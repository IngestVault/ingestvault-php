<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Page;
use IngestVault\Subscription;
use IngestVault\Transport;

final class Subscriptions extends Group
{
    /**
     * @return Page<Subscription>
     */
    public function list(string $endpointId, ?int $pageSize = null, ?string $cursor = null): Page
    {
        return self::page(
            $this->transport->request('GET', self::path($endpointId), ['page_size' => $pageSize, 'cursor' => $cursor]),
            Subscription::fromArray(...),
            'a page of subscriptions',
        );
    }

    /**
     * @return \Generator<int, Subscription, mixed, void>
     */
    public function all(string $endpointId, ?int $pageSize = null): \Generator
    {
        $cursor = null;
        do {
            $page = $this->list($endpointId, $pageSize, $cursor);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * @param array<string> $filter Event type names, sent as a list; an empty array matches every event.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function create(string $endpointId, array $filter, ?string $description = null): Subscription
    {
        return self::item(
            $this->transport->request(
                'POST',
                self::path($endpointId),
                body: Transport::encode(['filter' => array_values($filter), 'description' => $description]),
                retry: false,
            ),
            Subscription::fromArray(...),
            'a subscription',
        );
    }

    public function get(string $endpointId, string $subscriptionId): Subscription
    {
        return self::item(
            $this->transport->request('GET', self::path($endpointId, $subscriptionId)),
            Subscription::fromArray(...),
            'a subscription',
        );
    }

    /**
     * @param array{filter?: array<string>, description?: ?string} $fields Only the given members change; a filter replaces the old one, a null description clears it.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function update(string $endpointId, string $subscriptionId, array $fields): Subscription
    {
        // A filter with gaps in its keys, as array_filter() or array_unique() leave it, would otherwise encode as a JSON object.
        $filter = $fields['filter'] ?? null;
        if (is_array($filter)) {
            $fields['filter'] = array_values($filter);
        }

        return self::item(
            $this->transport->request('PATCH', self::path($endpointId, $subscriptionId), body: Transport::encode($fields)),
            Subscription::fromArray(...),
            'a subscription',
        );
    }

    public function delete(string $endpointId, string $subscriptionId): void
    {
        $this->transport->request('DELETE', self::path($endpointId, $subscriptionId));
    }

    private static function path(string $endpointId, ?string $subscriptionId = null): string
    {
        $path = '/endpoints/' . rawurlencode($endpointId) . '/subscriptions';

        return $subscriptionId === null ? $path : $path . '/' . rawurlencode($subscriptionId);
    }
}
