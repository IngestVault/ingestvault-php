<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\Event;
use IngestVault\Exception\ApiException;
use IngestVault\Json;
use IngestVault\Page;
use IngestVault\Transport;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
abstract class Group
{
    public function __construct(protected readonly Transport $transport) {}

    /**
     * @return array<mixed>
     */
    protected static function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    protected static function requestId(ResponseInterface $response): ?string
    {
        $requestId = $response->getHeaderLine('Request-Id');

        return $requestId === '' ? null : $requestId;
    }

    /**
     * @template T of object
     *
     * @param callable(array<mixed>, ?string): ?T $fromArray
     * @return T
     */
    protected static function item(ResponseInterface $response, callable $fromArray, string $noun): object
    {
        return $fromArray(self::body($response), self::requestId($response)) ?? throw self::unreadable($response, $noun);
    }

    protected static function event(ResponseInterface $response): Event
    {
        $json = (string) $response->getBody();
        $body = json_decode($json, true);

        return Event::fromArray(is_array($body) ? $body : [], Json::member($json, 'payload'), self::requestId($response))
            ?? throw self::unreadable($response, 'an event');
    }

    /**
     * @template T of object
     *
     * @param callable(array<mixed>, ?string): ?T $fromArray
     * @return Page<T>
     */
    protected static function page(ResponseInterface $response, callable $fromArray, string $noun): Page
    {
        $body = self::body($response);
        $requestId = self::requestId($response);
        $data = self::items($body['data'] ?? null, $fromArray, $requestId);
        $hasMore = $body['has_more'] ?? null;
        $nextCursor = $body['next_cursor'] ?? null;

        if ($data === null || ! is_bool($hasMore) || ! array_key_exists('next_cursor', $body) || ! (is_string($nextCursor) || $nextCursor === null)) {
            throw self::unreadable($response, $noun);
        }

        return new Page($data, $hasMore, $nextCursor, $requestId);
    }

    /**
     * @template T of object
     *
     * @param callable(array<mixed>, ?string): ?T $fromArray
     * @return ?list<T>
     */
    protected static function items(mixed $data, callable $fromArray, ?string $requestId): ?array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            return null;
        }

        $items = [];
        foreach ($data as $item) {
            $item = is_array($item) ? $fromArray($item, $requestId) : null;
            if ($item === null) {
                return null;
            }
            $items[] = $item;
        }

        return $items;
    }

    protected static function unreadable(ResponseInterface $response, string $noun): ApiException
    {
        return new ApiException(
            sprintf('The API answered with HTTP %d but the body is not %s.', $response->getStatusCode(), $noun),
            $response->getStatusCode(),
            null,
            self::requestId($response),
        );
    }
}
