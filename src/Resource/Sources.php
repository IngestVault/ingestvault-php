<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Page;
use IngestVault\Source;
use IngestVault\Transport;

final class Sources extends Group
{
    /**
     * @return Page<Source>
     */
    public function list(?int $pageSize = null, ?string $cursor = null): Page
    {
        return self::page(
            $this->transport->request('GET', '/sources', ['page_size' => $pageSize, 'cursor' => $cursor]),
            Source::fromArray(...),
            'a page of sources',
        );
    }

    /**
     * @return \Generator<int, Source, mixed, void>
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
     * @param array<string> $endpointIds The endpoints to connect, in the order to keep.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function create(string $name, ?string $description = null, array $endpointIds = [], bool $enabled = true): Source
    {
        return self::item(
            $this->transport->request(
                'POST',
                '/sources',
                body: Transport::encode(['name' => $name, 'description' => $description, 'enabled' => $enabled, 'endpoint_ids' => array_values($endpointIds)]),
                retry: false,
            ),
            Source::fromArray(...),
            'a source',
        );
    }

    public function get(string $sourceId): Source
    {
        return self::item(
            $this->transport->request('GET', self::path($sourceId)),
            Source::fromArray(...),
            'a source',
        );
    }

    /**
     * @param array{name?: string, description?: ?string, enabled?: bool, endpoint_ids?: array<string>} $fields Only the given members change; a null description clears it and endpoint_ids replaces the connected endpoints whole.
     *
     * @throws \JsonException When a value cannot be encoded as JSON.
     */
    public function update(string $sourceId, array $fields): Source
    {
        if (array_key_exists('endpoint_ids', $fields)) {
            $fields['endpoint_ids'] = array_values($fields['endpoint_ids']);
        }

        return self::item(
            $this->transport->request('PATCH', self::path($sourceId), body: Transport::encode($fields)),
            Source::fromArray(...),
            'a source',
        );
    }

    public function delete(string $sourceId): void
    {
        $this->transport->request('DELETE', self::path($sourceId));
    }

    public function rotateUrl(string $sourceId): Source
    {
        return self::item(
            $this->transport->request('POST', self::path($sourceId) . '/rotate-url', retry: false),
            Source::fromArray(...),
            'a source',
        );
    }

    private static function path(string $sourceId): string
    {
        return '/sources/' . rawurlencode($sourceId);
    }
}
