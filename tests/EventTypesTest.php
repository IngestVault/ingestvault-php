<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\EventType;
use PHPUnit\Framework\Attributes\DataProvider;

final class EventTypesTest extends TestCase
{
    private const ID = '0199b2c4-2222-7a3b-9c4d-5e6f7a8b9c02';

    private const BASE = 'https://api.example.test/v1';

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('requests')]
    public function test_sends_each_operation_with_the_expected_request(\Closure $call, Response $answer, string $method, string $uri, ?string $body): void
    {
        $call($this->client([$answer]));

        $this->assertCount(1, $this->history);
        $this->assertSentRequest($method, $uri, $body);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, Response, string, string, ?string}>
     */
    public static function requests(): iterable
    {
        yield 'list' => [
            static fn(Client $client): mixed => $client->eventTypes->list(),
            self::pageResponse([self::eventType()], null),
            'GET', self::BASE . '/event-types', null,
        ];
        yield 'list with a page size and a cursor' => [
            static fn(Client $client): mixed => $client->eventTypes->list(50, 'c2'),
            self::pageResponse([self::eventType()], null),
            'GET', self::BASE . '/event-types?page_size=50&cursor=c2', null,
        ];
        yield 'create' => [
            static fn(Client $client): mixed => $client->eventTypes->create('order.created', 'An order was placed.'),
            self::eventTypeResponse(201),
            'POST', self::BASE . '/event-types', '{"name":"order.created","description":"An order was placed."}',
        ];
        yield 'create with only the name' => [
            static fn(Client $client): mixed => $client->eventTypes->create('order.created'),
            self::eventTypeResponse(201),
            'POST', self::BASE . '/event-types', '{"name":"order.created","description":null}',
        ];
        yield 'get' => [
            static fn(Client $client): mixed => $client->eventTypes->get(self::ID),
            self::eventTypeResponse(),
            'GET', self::BASE . '/event-types/' . self::ID, null,
        ];
        yield 'get with an id holding a slash' => [
            static fn(Client $client): mixed => $client->eventTypes->get('a/b'),
            self::eventTypeResponse(),
            'GET', self::BASE . '/event-types/a%2Fb', null,
        ];
        yield 'update' => [
            static fn(Client $client): mixed => $client->eventTypes->update(self::ID, ['description' => 'Placed at checkout.']),
            self::eventTypeResponse(),
            'PATCH', self::BASE . '/event-types/' . self::ID, '{"description":"Placed at checkout."}',
        ];
        yield 'update clearing the description' => [
            static fn(Client $client): mixed => $client->eventTypes->update(self::ID, ['description' => null]),
            self::eventTypeResponse(),
            'PATCH', self::BASE . '/event-types/' . self::ID, '{"description":null}',
        ];
        yield 'update with no fields' => [
            static fn(Client $client): mixed => $client->eventTypes->update(self::ID, []),
            self::eventTypeResponse(),
            'PATCH', self::BASE . '/event-types/' . self::ID, '{}',
        ];
        yield 'archive' => [
            static fn(Client $client): mixed => $client->eventTypes->archive(self::ID),
            self::eventTypeResponse(overrides: ['archived' => true]),
            'POST', self::BASE . '/event-types/' . self::ID . '/archive', null,
        ];
        yield 'unarchive' => [
            static fn(Client $client): mixed => $client->eventTypes->unarchive(self::ID),
            self::eventTypeResponse(),
            'POST', self::BASE . '/event-types/' . self::ID . '/unarchive', null,
        ];
    }

    /**
     * @param \Closure(Client): EventType $call
     */
    #[DataProvider('operationsReturningAnEventType')]
    public function test_returns_the_event_type_from_the_answer(\Closure $call, int $status): void
    {
        $eventType = $call($this->client([self::eventTypeResponse($status)]));

        $this->assertSame(self::ID, $eventType->id);
        $this->assertSame('order.created', $eventType->name);
        $this->assertSame('An order was placed.', $eventType->description);
        $this->assertFalse($eventType->archived);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $eventType->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-02T09:30:00Z'), $eventType->updatedAt);
    }

    /**
     * @return iterable<string, array{\Closure(Client): EventType, int}>
     */
    public static function operationsReturningAnEventType(): iterable
    {
        yield 'create' => [static fn(Client $client): EventType => $client->eventTypes->create('order.created'), 201];
        yield 'get' => [static fn(Client $client): EventType => $client->eventTypes->get(self::ID), 200];
        yield 'update' => [static fn(Client $client): EventType => $client->eventTypes->update(self::ID, []), 200];
        yield 'unarchive' => [static fn(Client $client): EventType => $client->eventTypes->unarchive(self::ID), 200];
    }

    public function test_an_archived_type_with_no_description_is_read_as_such(): void
    {
        $eventType = $this->client([self::eventTypeResponse(overrides: ['archived' => true, 'description' => null])])->eventTypes->archive(self::ID);

        $this->assertTrue($eventType->archived);
        $this->assertNull($eventType->description);
    }

    public function test_unknown_members_in_the_event_type_are_ignored(): void
    {
        $eventType = $this->client([self::eventTypeResponse(overrides: ['schema' => ['type' => 'object'], 'owner' => 'billing'])])
            ->eventTypes->get(self::ID);

        $this->assertSame(self::ID, $eventType->id);
    }
}
