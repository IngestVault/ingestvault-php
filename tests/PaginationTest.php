<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use IngestVault\Client;
use IngestVault\Endpoint;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaginationTest extends TestCase
{
    private const ENDPOINT = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    public function test_a_page_exposes_its_items_whether_more_follow_and_the_next_cursor(): void
    {
        $page = $this->client([self::pageResponse([self::endpoint(['id' => 'a']), self::endpoint(['id' => 'b'])], 'cursor-2')])
            ->endpoints->list();

        $this->assertSame(['a', 'b'], self::ids($page->data));
        $this->assertTrue($page->hasMore);
        $this->assertSame('cursor-2', $page->nextCursor);
    }

    public function test_the_last_page_has_no_next_cursor(): void
    {
        $page = $this->client([self::pageResponse([], null)])->endpoints->list();

        $this->assertSame([], $page->data);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    public function test_the_next_cursor_passed_back_is_sent(): void
    {
        $client = $this->client([
            self::pageResponse([self::endpoint(['id' => 'a'])], 'eyJpZCI6ImEifQ=='),
            self::pageResponse([self::endpoint(['id' => 'b'])], null),
        ]);

        $first = $client->endpoints->list();
        $client->endpoints->list(cursor: $first->nextCursor);

        $this->assertStringNotContainsString('cursor=', $this->sentUri(0));
        $this->assertStringContainsString('cursor=eyJpZCI6ImEifQ%3D%3D', $this->sentUri(1));
    }

    public function test_iterating_all_yields_every_item_across_pages_in_order(): void
    {
        $client = $this->client([
            self::pageResponse([self::endpoint(['id' => 'a']), self::endpoint(['id' => 'b'])], 'cursor-2'),
            self::pageResponse([self::endpoint(['id' => 'c'])], 'cursor-3'),
            self::pageResponse([self::endpoint(['id' => 'd'])], null),
        ]);

        $ids = [];
        foreach ($client->endpoints->all() as $endpoint) {
            $ids[] = $endpoint->id;
        }

        $this->assertSame(['a', 'b', 'c', 'd'], $ids);
        $this->assertCount(3, $this->history);
        $this->assertSame('https://api.example.test/v1/endpoints', $this->sentUri(0));
        $this->assertSame('https://api.example.test/v1/endpoints?cursor=cursor-2', $this->sentUri(1));
        $this->assertSame('https://api.example.test/v1/endpoints?cursor=cursor-3', $this->sentUri(2));
    }

    public function test_all_requests_the_next_page_only_when_its_items_are_needed(): void
    {
        $client = $this->client([
            self::pageResponse([self::endpoint(['id' => 'a']), self::endpoint(['id' => 'b'])], 'cursor-2'),
            self::pageResponse([self::endpoint(['id' => 'c'])], null),
        ]);

        $all = $client->endpoints->all();
        $this->assertCount(0, $this->history);

        $this->assertSame('a', $all->current()->id);
        $this->assertCount(1, $this->history);

        $all->next();
        $this->assertSame('b', $all->current()->id);
        $this->assertCount(1, $this->history);

        $all->next();
        $this->assertSame('c', $all->current()->id);
        $this->assertCount(2, $this->history);
    }

    public function test_a_last_page_without_a_cursor_ends_iteration_after_one_request(): void
    {
        $client = $this->client([self::pageResponse([self::endpoint(['id' => 'a'])], null)]);

        $this->assertSame(['a'], self::ids(iterator_to_array($client->endpoints->all())));
        $this->assertCount(1, $this->history);
    }

    public function test_collecting_all_into_an_array_keeps_every_item(): void
    {
        $client = $this->client([
            self::pageResponse([self::endpoint(['id' => 'a']), self::endpoint(['id' => 'b'])], 'cursor-2'),
            self::pageResponse([self::endpoint(['id' => 'c']), self::endpoint(['id' => 'd'])], null),
        ]);

        $endpoints = iterator_to_array($client->endpoints->all());

        $this->assertSame([0, 1, 2, 3], array_keys($endpoints));
        $this->assertSame(['a', 'b', 'c', 'd'], self::ids($endpoints));
    }

    public function test_all_sends_the_page_size_on_every_request(): void
    {
        $client = $this->client([
            self::pageResponse([self::endpoint(['id' => 'a'])], 'cursor-2'),
            self::pageResponse([self::endpoint(['id' => 'b'])], null),
        ]);

        iterator_to_array($client->endpoints->all(pageSize: 1));

        $this->assertSame('https://api.example.test/v1/endpoints?page_size=1', $this->sentUri(0));
        $this->assertSame('https://api.example.test/v1/endpoints?page_size=1&cursor=cursor-2', $this->sentUri(1));
    }

    /**
     * @param \Closure(Client): \Generator<int, object> $all
     * @param \Closure(array<string, mixed>): array<string, mixed> $item
     */
    #[DataProvider('everyList')]
    public function test_every_list_iterates_across_pages(\Closure $all, \Closure $item, string $uri): void
    {
        $client = $this->client([
            self::pageResponse([$item(['id' => 'a'])], 'cursor-2'),
            self::pageResponse([$item(['id' => 'b'])], null),
        ]);

        $ids = [];
        foreach ($all($client) as $object) {
            $this->assertObjectHasProperty('id', $object);
            $ids[] = $object->id;
        }

        $this->assertSame(['a', 'b'], $ids);
        $this->assertCount(2, $this->history);
        $this->assertSame($uri, $this->sentUri(0));
        $this->assertSame($uri . '?cursor=cursor-2', $this->sentUri(1));
    }

    /**
     * @return iterable<string, array{\Closure(Client): \Generator<int, object>, \Closure(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function everyList(): iterable
    {
        yield 'endpoints' => [
            static fn(Client $client): \Generator => $client->endpoints->all(),
            static fn(array $overrides): array => self::endpoint($overrides),
            'https://api.example.test/v1/endpoints',
        ];
        yield 'subscriptions' => [
            static fn(Client $client): \Generator => $client->subscriptions->all(self::ENDPOINT),
            static fn(array $overrides): array => self::subscription($overrides),
            'https://api.example.test/v1/endpoints/' . self::ENDPOINT . '/subscriptions',
        ];
        yield 'event types' => [
            static fn(Client $client): \Generator => $client->eventTypes->all(),
            static fn(array $overrides): array => self::eventType($overrides),
            'https://api.example.test/v1/event-types',
        ];
        yield 'events' => [
            static fn(Client $client): \Generator => $client->events->all(),
            static fn(array $overrides): array => self::eventSummary($overrides),
            'https://api.example.test/v1/events',
        ];
        yield 'deliveries' => [
            static fn(Client $client): \Generator => $client->deliveries->all(),
            static fn(array $overrides): array => self::delivery($overrides),
            'https://api.example.test/v1/deliveries',
        ];
    }

    /**
     * @param \Closure(Client): \Generator<int, object> $all
     * @param \Closure(array<string, mixed>): array<string, mixed> $item
     */
    #[DataProvider('filteredLists')]
    public function test_all_sends_the_same_filters_on_every_page(\Closure $all, \Closure $item, string $uri, string $filters): void
    {
        $client = $this->client([
            self::pageResponse([$item(['id' => 'a'])], 'cursor-2'),
            self::pageResponse([$item(['id' => 'b'])], null),
        ]);

        $this->assertCount(2, iterator_to_array($all($client)));

        $this->assertCount(2, $this->history);
        $this->assertSame($uri . '?' . $filters, $this->sentUri(0));
        $this->assertSame($uri . '?cursor=cursor-2&' . $filters, $this->sentUri(1));
    }

    /**
     * @return iterable<string, array{\Closure(Client): \Generator<int, object>, \Closure(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function filteredLists(): iterable
    {
        yield 'events by type, originals only' => [
            static fn(Client $client): \Generator => $client->events->all(type: 'order.created', replay: false),
            static fn(array $overrides): array => self::eventSummary($overrides),
            'https://api.example.test/v1/events',
            'type=order.created&replay=false',
        ];
        yield 'deliveries by status' => [
            static fn(Client $client): \Generator => $client->deliveries->all(status: 'failed'),
            static fn(array $overrides): array => self::delivery($overrides),
            'https://api.example.test/v1/deliveries',
            'status=failed',
        ];
    }

    /**
     * @param array<Endpoint> $endpoints
     * @return list<string>
     */
    private static function ids(array $endpoints): array
    {
        return array_values(array_map(static fn(Endpoint $endpoint): string => $endpoint->id, $endpoints));
    }
}
