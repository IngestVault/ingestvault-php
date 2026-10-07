<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\EventSummary;
use PHPUnit\Framework\Attributes\DataProvider;

final class EventsTest extends TestCase
{
    private const ID = '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d';

    private const ROOT = '0199b2c4-0000-7a3b-9c4d-5e6f7a8b9c00';

    private const BASE = 'https://api.example.test/v1';

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('requests')]
    public function test_sends_each_operation_with_the_expected_request(\Closure $call, Response $answer, string $uri): void
    {
        $call($this->client([$answer]));

        $this->assertCount(1, $this->history);
        $this->assertSentRequest('GET', $uri, null);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, Response, string}>
     */
    public static function requests(): iterable
    {
        yield 'list' => [
            static fn(Client $client): mixed => $client->events->list(),
            self::pageResponse([self::eventSummary()], null),
            self::BASE . '/events',
        ];
        yield 'list with every filter' => [
            static fn(Client $client): mixed => $client->events->list(
                pageSize: 10,
                cursor: 'c2',
                type: 'order.created',
                typeRegistrationStatus: 'unregistered',
                receivedAfter: '2026-10-05T12:00:00Z',
                receivedBefore: '2026-10-06T00:00:00+02:00',
                replay: true,
            ),
            self::pageResponse([self::eventSummary()], null),
            self::BASE . '/events?page_size=10&cursor=c2&type=order.created&type_registration_status=unregistered'
                . '&received_after=2026-10-05T12%3A00%3A00Z&received_before=2026-10-06T00%3A00%3A00%2B02%3A00&replay=true',
        ];
        yield 'list of originals only' => [
            static fn(Client $client): mixed => $client->events->list(replay: false),
            self::pageResponse([self::eventSummary()], null),
            self::BASE . '/events?replay=false',
        ];
        yield 'get' => [
            static fn(Client $client): mixed => $client->events->get(self::ID),
            self::eventResponse(200, ['idempotent' => false]),
            self::BASE . '/events/' . self::ID,
        ];
        yield 'get with an id holding a slash' => [
            static fn(Client $client): mixed => $client->events->get('a/b'),
            self::eventResponse(200, ['idempotent' => false]),
            self::BASE . '/events/a%2Fb',
        ];
    }

    public function test_returns_the_event_from_the_answer(): void
    {
        $event = $this->client([self::eventResponse(200, ['idempotent' => false])])->events->get(self::ID);

        $this->assertSame(self::ID, $event->id);
        $this->assertSame('order.created', $event->type);
        $this->assertSame('registered', $event->typeRegistrationStatus);
        $this->assertSame(['order' => 1042], $event->payload);
        $this->assertSame('{"order":1042}', $event->payloadJson);
        $this->assertSame('available', $event->payloadState);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:00Z'), $event->receivedAt);
        $this->assertFalse($event->idempotent);
        $this->assertFalse($event->replay);
        $this->assertNull($event->rootEventId);
        $this->assertNull($event->initiator);
    }

    /**
     * @param ?array<string, mixed> $initiator
     */
    #[DataProvider('initiators')]
    public function test_a_replay_event_carries_its_root_and_initiator(?array $initiator, ?string $type, ?string $id, ?string $name, ?string $hint): void
    {
        $event = $this->client([self::eventResponse(200, [
            'idempotent' => false,
            'replay' => true,
            'root_event_id' => self::ROOT,
            'initiator' => $initiator,
        ])])->events->get(self::ID);

        $this->assertTrue($event->replay);
        $this->assertSame(self::ROOT, $event->rootEventId);
        $this->assertSame($type, $event->initiator?->type);
        $this->assertSame($id, $event->initiator?->id);
        $this->assertSame($name, $event->initiator?->name);
        $this->assertSame($hint, $event->initiator?->hint);
    }

    /**
     * @return iterable<string, array{?array<string, mixed>, ?string, ?string, ?string, ?string}>
     */
    public static function initiators(): iterable
    {
        yield 'an API key' => [
            ['type' => 'api_key', 'id' => '0199b2c4-9999-7a3b-9c4d-5e6f7a8b9c09', 'name' => 'Checkout', 'hint' => 'ivk_...a1b2'],
            'api_key', '0199b2c4-9999-7a3b-9c4d-5e6f7a8b9c09', 'Checkout', 'ivk_...a1b2',
        ];
        yield 'support' => [['type' => 'support'], 'support', null, null, null];
        yield 'unknown' => [null, null, null, null, null];
    }

    public function test_the_payload_is_readable_exactly_as_sent(): void
    {
        $payload = '{"empty": {}, "list": [], "ratio": 1.0, "big": 9007199254740993}';
        $body = '{"id":"' . self::ID . '","type":"order.created","type_registration_status":"registered",'
            . '"payload": ' . $payload . ',"payload_state":"available","received_at":"2026-10-05T12:00:00Z","idempotent":false,"replay":false}';

        $event = $this->client([new Response(200, ['Content-Type' => 'application/json'], $body)])->events->get(self::ID);

        $this->assertSame($payload, $event->payloadJson);
        $this->assertSame(['empty' => [], 'list' => [], 'ratio' => 1.0, 'big' => 9007199254740993], $event->payload);

        $decoded = json_decode((string) $event->payloadJson, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $decoded);
        $this->assertEquals(new \stdClass(), $decoded->empty);
        $this->assertSame([], $decoded->list);
    }

    public function test_an_expired_event_has_no_payload_in_either_form(): void
    {
        $event = $this->client([self::eventResponse(200, ['idempotent' => false, 'payload' => null, 'payload_state' => 'expired'])])
            ->events->get(self::ID);

        $this->assertSame('expired', $event->payloadState);
        $this->assertNull($event->payload);
        $this->assertNull($event->payloadJson);
    }

    public function test_an_event_without_a_payload_has_no_payload_in_either_form(): void
    {
        $event = $this->client([self::eventResponse(200, ['idempotent' => false, 'payload' => null, 'payload_state' => 'none'])])
            ->events->get(self::ID);

        $this->assertSame('none', $event->payloadState);
        $this->assertNull($event->payload);
        $this->assertNull($event->payloadJson);
    }

    public function test_a_stored_null_payload_reads_null_with_the_text_null(): void
    {
        $event = $this->client([self::eventResponse(200, ['idempotent' => false, 'payload' => null])])->events->get(self::ID);

        $this->assertSame('available', $event->payloadState);
        $this->assertNull($event->payload);
        $this->assertSame('null', $event->payloadJson);
    }

    public function test_the_events_of_a_page_are_read(): void
    {
        $page = $this->client([self::pageResponse([
            self::eventSummary(),
            self::eventSummary(['id' => 'second', 'type_registration_status' => 'archived', 'replay' => true, 'root_event_id' => self::ROOT]),
        ], null)])->events->list();

        $this->assertSame([self::ID, 'second'], array_map(static fn(EventSummary $event): string => $event->id, $page->data));
        $this->assertSame('order.created', $page->data[0]->type);
        $this->assertSame('registered', $page->data[0]->typeRegistrationStatus);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:00Z'), $page->data[0]->receivedAt);
        $this->assertFalse($page->data[0]->replay);
        $this->assertNull($page->data[0]->rootEventId);
        $this->assertSame('archived', $page->data[1]->typeRegistrationStatus);
        $this->assertTrue($page->data[1]->replay);
        $this->assertSame(self::ROOT, $page->data[1]->rootEventId);
    }

    public function test_unknown_members_are_tolerated(): void
    {
        $client = $this->client([
            self::eventResponse(200, [
                'idempotent' => false,
                'payload_state' => 'archived',
                'replay' => true,
                'root_event_id' => self::ROOT,
                'initiator' => ['type' => 'scheduler', 'schedule' => 'nightly'],
                'source' => ['kind' => 'sdk'],
            ]),
            self::pageResponse([self::eventSummary(['type_registration_status' => 'pending_review', 'source' => 'sdk'])], null),
        ]);

        $event = $client->events->get(self::ID);
        $page = $client->events->list();

        $this->assertSame('archived', $event->payloadState);
        $this->assertSame(['order' => 1042], $event->payload);
        $this->assertSame('scheduler', $event->initiator?->type);
        $this->assertSame('pending_review', $page->data[0]->typeRegistrationStatus);
    }
}
