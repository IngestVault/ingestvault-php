<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\EventSummary;
use IngestVault\Exception\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;

final class EventsTest extends TestCase
{
    private const ID = '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d';

    private const ROOT = '0199b2c4-0000-7a3b-9c4d-5e6f7a8b9c00';

    private const SOURCE = '0199b2c4-aaaa-7a3b-9c4d-5e6f7a8b9c0a';

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
                sourceId: self::SOURCE,
                origin: 'source',
            ),
            self::pageResponse([self::eventSummary()], null),
            self::BASE . '/events?page_size=10&cursor=c2&type=order.created&type_registration_status=unregistered'
                . '&received_after=2026-10-05T12%3A00%3A00Z&received_before=2026-10-06T00%3A00%3A00%2B02%3A00&replay=true'
                . '&source_id=' . self::SOURCE . '&origin=source',
        ];
        yield 'list of api events' => [
            static fn(Client $client): mixed => $client->events->list(origin: 'api'),
            self::pageResponse([self::eventSummary()], null),
            self::BASE . '/events?origin=api',
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
            . '"origin":"api","source":null,"provider_event_type":null,'
            . '"payload": ' . $payload . ',"payload_state":"available",'
            . '"body":null,"body_encoding":null,"content_type":null,"headers":null,"query_string":null,'
            . '"received_at":"2026-10-05T12:00:00Z","idempotent":false,"replay":false}';

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
                'channel' => ['kind' => 'sdk'],
            ]),
            self::pageResponse([self::eventSummary(['type_registration_status' => 'pending_review', 'channel' => 'sdk'])], null),
        ]);

        $event = $client->events->get(self::ID);
        $page = $client->events->list();

        $this->assertSame('archived', $event->payloadState);
        $this->assertSame(['order' => 1042], $event->payload);
        $this->assertSame('scheduler', $event->initiator?->type);
        $this->assertSame('pending_review', $page->data[0]->typeRegistrationStatus);
    }

    public function test_an_api_origin_event_has_no_source_and_no_provider_request(): void
    {
        $event = $this->client([self::eventResponse(200, ['idempotent' => false])])->events->get(self::ID);

        $this->assertSame('api', $event->origin);
        $this->assertNull($event->source);
        $this->assertNull($event->providerEventType);
        $this->assertNull($event->body);
        $this->assertNull($event->bodyAsSent);
        $this->assertNull($event->bodyEncoding);
        $this->assertNull($event->contentType);
        $this->assertNull($event->headers);
        $this->assertNull($event->queryString);
        $this->assertSame(['order' => 1042], $event->payload);
        $this->assertSame('{"order":1042}', $event->payloadJson);
    }

    public function test_an_inbound_event_has_no_type_and_its_source_is_named(): void
    {
        $event = $this->client([self::inboundEventResponse()])->events->get(self::ID);

        $this->assertNull($event->type);
        $this->assertNull($event->typeRegistrationStatus);
        $this->assertSame('source', $event->origin);
        $this->assertSame(self::SOURCE, $event->source?->id);
        $this->assertSame('GitHub', $event->source?->name);
        $this->assertSame('push', $event->providerEventType);
        $this->assertSame('{"ref":"main"}', $event->body);
        $this->assertSame('text', $event->bodyEncoding);
        $this->assertSame('application/json', $event->contentType);
        $this->assertSame('delivery=1&b=%20', $event->queryString);
    }

    public function test_an_inbound_event_never_has_a_payload(): void
    {
        $event = $this->client([self::inboundEventResponse()])->events->get(self::ID);

        $this->assertSame('available', $event->payloadState);
        $this->assertNull($event->payload);
        $this->assertNull($event->payloadJson);
    }

    public function test_an_inbound_event_whose_source_was_deleted_is_read(): void
    {
        $event = $this->client([self::inboundEventResponse(['source' => null])])->events->get(self::ID);

        $this->assertSame('source', $event->origin);
        $this->assertNull($event->source);
    }

    public function test_a_base64_body_is_read_as_the_original_bytes(): void
    {
        $event = $this->client([self::inboundEventResponse([
            'body' => base64_encode("\x00\xff\xfe"),
            'body_encoding' => 'base64',
            'content_type' => 'application/octet-stream',
        ])])->events->get(self::ID);

        $this->assertSame("\x00\xff\xfe", $event->body);
        $this->assertSame(base64_encode("\x00\xff\xfe"), $event->bodyAsSent);
        $this->assertSame('base64', $event->bodyEncoding);
    }

    public function test_a_text_body_is_read_unchanged(): void
    {
        $text = 'Grüße, 東京 – ✓';
        $event = $this->client([self::inboundEventResponse(['body' => $text, 'body_encoding' => 'text', 'content_type' => 'text/plain; charset=utf-8'])])
            ->events->get(self::ID);

        $this->assertSame($text, $event->body);
        $this->assertSame($text, $event->bodyAsSent);
    }

    public function test_headers_keep_their_order_and_repeated_names(): void
    {
        $headers = [['x-github-event', 'push'], ['x-custom', 'a'], ['x-custom', 'b']];
        $event = $this->client([self::inboundEventResponse(['headers' => $headers])])->events->get(self::ID);

        $this->assertSame($headers, $event->headers);
    }

    public function test_a_body_that_is_not_valid_base64_makes_the_answer_unreadable(): void
    {
        $client = $this->client([self::inboundEventResponse(['body' => '%%%', 'body_encoding' => 'base64'])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 200 but the body is not an event.');

        $client->events->get(self::ID);
    }

    #[DataProvider('unreadableHeaders')]
    public function test_headers_that_are_not_name_value_pairs_make_the_answer_unreadable(mixed $headers): void
    {
        $client = $this->client([self::inboundEventResponse(['headers' => $headers])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 200 but the body is not an event.');

        $client->events->get(self::ID);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableHeaders(): iterable
    {
        yield 'a map' => [['x-github-event' => 'push']];
        yield 'a pair of three' => [[['x-github-event', 'push', 'extra']]];
        yield 'a value that is not a string' => [[['x-attempt', 2]]];
        yield 'a string' => ['x-github-event: push'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unreadableInboundEvents')]
    public function test_an_inbound_event_of_an_unreadable_shape_makes_the_answer_unreadable(array $overrides): void
    {
        $client = $this->client([self::inboundEventResponse($overrides)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 200 but the body is not an event.');

        $client->events->get(self::ID);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableInboundEvents(): iterable
    {
        yield 'a source without a name' => [['source' => ['id' => self::SOURCE]]];
        yield 'a source that is a string' => [['source' => self::SOURCE]];
        yield 'an origin that is not a string' => [['origin' => null]];
        yield 'a body that is not a string' => [['body' => ['ref' => 'main']]];
        yield 'a query string that is not a string' => [['query_string' => ['delivery' => '1']]];
    }

    public function test_an_event_without_the_request_members_makes_the_answer_unreadable(): void
    {
        $answer = json_decode((string) self::eventResponse(200, ['idempotent' => false])->getBody(), true, 512, JSON_THROW_ON_ERROR);
        unset($answer['query_string']);
        $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], json_encode($answer, JSON_THROW_ON_ERROR))]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API answered with HTTP 200 but the body is not an event.');

        $client->events->get(self::ID);
    }

    public function test_an_inbound_event_past_retention_keeps_the_encoding_and_content_type(): void
    {
        $event = $this->client([self::inboundEventResponse([
            'payload_state' => 'expired',
            'body' => null,
            'body_encoding' => 'base64',
            'content_type' => 'application/octet-stream',
            'headers' => null,
            'query_string' => null,
        ])])->events->get(self::ID);

        $this->assertSame('expired', $event->payloadState);
        $this->assertNull($event->body);
        $this->assertNull($event->bodyAsSent);
        $this->assertSame('base64', $event->bodyEncoding);
        $this->assertSame('application/octet-stream', $event->contentType);
        $this->assertNull($event->headers);
        $this->assertNull($event->queryString);
        $this->assertNull($event->payload);
    }

    public function test_an_inbound_event_with_an_empty_body_reads_an_empty_string(): void
    {
        $event = $this->client([self::inboundEventResponse([
            'payload_state' => 'none',
            'body' => '',
            'body_encoding' => 'none',
            'content_type' => null,
        ])])->events->get(self::ID);

        $this->assertSame('none', $event->payloadState);
        $this->assertSame('', $event->body);
        $this->assertSame('', $event->bodyAsSent);
        $this->assertSame('none', $event->bodyEncoding);
        $this->assertNull($event->contentType);
    }

    public function test_an_inbound_event_with_an_empty_body_past_retention_is_read(): void
    {
        $event = $this->client([self::inboundEventResponse([
            'payload_state' => 'none',
            'body' => null,
            'body_encoding' => 'none',
            'content_type' => null,
            'headers' => null,
            'query_string' => null,
        ])])->events->get(self::ID);

        $this->assertSame('none', $event->payloadState);
        $this->assertNull($event->body);
        $this->assertNull($event->headers);
        $this->assertNull($event->queryString);
    }

    public function test_an_inbound_row_has_no_type_and_names_its_source(): void
    {
        $page = $this->client([self::pageResponse([
            self::eventSummary(),
            self::eventSummary([
                'id' => 'second',
                'type' => null,
                'type_registration_status' => null,
                'origin' => 'source',
                'source' => ['id' => self::SOURCE, 'name' => 'GitHub'],
                'provider_event_type' => 'push',
            ]),
        ], null)])->events->list();

        $this->assertSame('api', $page->data[0]->origin);
        $this->assertNull($page->data[0]->source);
        $this->assertNull($page->data[0]->providerEventType);

        $this->assertNull($page->data[1]->type);
        $this->assertNull($page->data[1]->typeRegistrationStatus);
        $this->assertSame('source', $page->data[1]->origin);
        $this->assertSame(self::SOURCE, $page->data[1]->source?->id);
        $this->assertSame('GitHub', $page->data[1]->source?->name);
        $this->assertSame('push', $page->data[1]->providerEventType);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function inboundEventResponse(array $overrides = []): Response
    {
        return self::eventResponse(200, $overrides + [
            'type' => null,
            'type_registration_status' => null,
            'origin' => 'source',
            'source' => ['id' => self::SOURCE, 'name' => 'GitHub'],
            'provider_event_type' => 'push',
            'payload' => null,
            'body' => '{"ref":"main"}',
            'body_encoding' => 'text',
            'content_type' => 'application/json',
            'headers' => [['content-type', 'application/json'], ['x-github-event', 'push']],
            'query_string' => 'delivery=1&b=%20',
            'idempotent' => false,
        ]);
    }
}
