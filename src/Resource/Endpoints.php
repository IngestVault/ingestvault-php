<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Endpoint;
use IngestVault\Page;
use IngestVault\Transport;

final class Endpoints extends Group
{
    /**
     * @return Page<Endpoint>
     */
    public function list(?int $pageSize = null, ?string $cursor = null): Page
    {
        return self::page(
            $this->transport->request('GET', '/endpoints', ['page_size' => $pageSize, 'cursor' => $cursor]),
            Endpoint::fromArray(...),
            'a page of endpoints',
        );
    }

    /**
     * @return \Generator<int, Endpoint, mixed, void>
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
    public function create(string $url, ?string $description = null, bool $enabled = true): Endpoint
    {
        return self::item(
            $this->transport->request(
                'POST',
                '/endpoints',
                body: Transport::encode(['url' => $url, 'description' => $description, 'enabled' => $enabled]),
                retry: false,
            ),
            Endpoint::fromArray(...),
            'an endpoint',
        );
    }

    public function get(string $endpointId): Endpoint
    {
        return self::item(
            $this->transport->request('GET', self::path($endpointId)),
            Endpoint::fromArray(...),
            'an endpoint',
        );
    }

    /**
     * @param array{url?: string, description?: ?string, enabled?: bool} $fields Only the given members change; a null description clears it.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function update(string $endpointId, array $fields): Endpoint
    {
        return self::item(
            $this->transport->request('PATCH', self::path($endpointId), body: Transport::encode($fields)),
            Endpoint::fromArray(...),
            'an endpoint',
        );
    }

    public function delete(string $endpointId): void
    {
        $this->transport->request('DELETE', self::path($endpointId));
    }

    private static function path(string $endpointId): string
    {
        return '/endpoints/' . rawurlencode($endpointId);
    }
}
