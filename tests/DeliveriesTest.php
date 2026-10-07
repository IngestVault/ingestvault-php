<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Delivery;
use IngestVault\DeliveryAttempt;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeliveriesTest extends TestCase
{
    private const ID = '0199b2c4-5555-7a3b-9c4d-5e6f7a8b9c05';

    private const EVENT = '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d';

    private const ENDPOINT = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

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
            static fn(Client $client): mixed => $client->deliveries->list(),
            self::pageResponse([self::delivery()], null),
            self::BASE . '/deliveries',
        ];
        yield 'list with every filter' => [
            static fn(Client $client): mixed => $client->deliveries->list(
                pageSize: 10,
                cursor: 'c2',
                endpointId: self::ENDPOINT,
                eventId: self::EVENT,
                eventType: 'order.created',
                status: 'failed',
                createdAfter: '2026-10-05T12:00:00Z',
                createdBefore: '2026-10-06T12:00:00Z',
            ),
            self::pageResponse([self::delivery()], null),
            self::BASE . '/deliveries?page_size=10&cursor=c2&endpoint_id=' . self::ENDPOINT . '&event_id=' . self::EVENT
                . '&event_type=order.created&status=failed&created_after=2026-10-05T12%3A00%3A00Z&created_before=2026-10-06T12%3A00%3A00Z',
        ];
        yield 'get' => [
            static fn(Client $client): mixed => $client->deliveries->get(self::ID),
            self::deliveryResponse(),
            self::BASE . '/deliveries/' . self::ID,
        ];
    }

    public function test_returns_the_delivery_with_its_attempts_from_the_answer(): void
    {
        $client = $this->client([
            self::deliveryResponse(),
            self::deliveryResponse(overrides: ['status' => 'succeeded', 'attempt_count' => 3, 'next_attempt_at' => null, 'attempts' => []]),
        ]);

        $delivery = $client->deliveries->get(self::ID);

        $this->assertSame(self::ID, $delivery->id);
        $this->assertSame('retrying', $delivery->status);
        $this->assertSame(2, $delivery->attemptCount);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:05:00Z'), $delivery->nextAttemptAt);
        $this->assertSame(self::EVENT, $delivery->eventId);
        $this->assertSame('order.created', $delivery->eventType);
        $this->assertSame(self::ENDPOINT, $delivery->endpointId);
        $this->assertSame('https://hooks.example.test/orders', $delivery->endpointUrl);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:01Z'), $delivery->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:01:30Z'), $delivery->updatedAt);
        $this->assertSame('available', $delivery->payloadState);

        $this->assertSame(
            ['0199b2c4-6666-7a3b-9c4d-5e6f7a8b9c06', '0199b2c4-6666-7a3b-9c4d-5e6f7a8b9c07'],
            array_map(static fn(DeliveryAttempt $attempt): string => $attempt->id, $delivery->attempts),
        );
        [$first, $second] = $delivery->attempts;
        $this->assertSame('failed', $first->outcome);
        $this->assertSame(503, $first->httpStatus);
        $this->assertNull($first->errorClassification);
        $this->assertSame(182, $first->durationMs);
        $this->assertSame('Service Unavailable', $first->responseBody);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:02Z'), $first->attemptedAt);
        $this->assertNull($second->httpStatus);
        $this->assertSame('timeout', $second->errorClassification);
        $this->assertNull($second->responseBody);

        $settled = $client->deliveries->get(self::ID);

        $this->assertSame('succeeded', $settled->status);
        $this->assertNull($settled->nextAttemptAt);
        $this->assertSame([], $settled->attempts);
    }

    public function test_an_expired_delivery_keeps_every_attempt_member_but_the_response_body(): void
    {
        $delivery = $this->client([self::deliveryResponse(overrides: [
            'payload_state' => 'expired',
            'attempts' => [
                self::attempt(['response_body' => null]),
                self::attempt(['id' => 'second', 'outcome' => 'succeeded', 'http_status' => 200, 'duration_ms' => 95, 'response_body' => null, 'attempted_at' => '2026-10-05T12:01:30Z']),
            ],
        ])])->deliveries->get(self::ID);

        $this->assertSame('expired', $delivery->payloadState);
        $this->assertCount(2, $delivery->attempts);
        [$first, $second] = $delivery->attempts;

        $this->assertSame('0199b2c4-6666-7a3b-9c4d-5e6f7a8b9c06', $first->id);
        $this->assertSame('failed', $first->outcome);
        $this->assertSame(503, $first->httpStatus);
        $this->assertNull($first->errorClassification);
        $this->assertSame(182, $first->durationMs);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:02Z'), $first->attemptedAt);
        $this->assertNull($first->responseBody);

        $this->assertSame('second', $second->id);
        $this->assertSame('succeeded', $second->outcome);
        $this->assertSame(200, $second->httpStatus);
        $this->assertSame(95, $second->durationMs);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:01:30Z'), $second->attemptedAt);
        $this->assertNull($second->responseBody);
    }

    public function test_the_deliveries_of_a_page_are_read(): void
    {
        $page = $this->client([self::pageResponse([
            self::delivery(),
            self::delivery(['id' => 'second', 'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => null]),
        ], null)])->deliveries->list();

        $this->assertSame([self::ID, 'second'], array_map(static fn(Delivery $delivery): string => $delivery->id, $page->data));
        $this->assertSame('retrying', $page->data[0]->status);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:05:00Z'), $page->data[0]->nextAttemptAt);
        $this->assertSame('order.created', $page->data[0]->eventType);
        $this->assertSame('https://hooks.example.test/orders', $page->data[0]->endpointUrl);
        $this->assertSame('pending', $page->data[1]->status);
        $this->assertSame(0, $page->data[1]->attemptCount);
        $this->assertNull($page->data[1]->nextAttemptAt);
    }

    public function test_unknown_members_are_ignored(): void
    {
        $client = $this->client([
            self::deliveryResponse(overrides: [
                'status' => 'paused',
                'payload_state' => 'archived',
                'event' => ['id' => self::EVENT, 'type' => 'order.created', 'replay' => false],
                'endpoint' => ['id' => self::ENDPOINT, 'url' => 'https://hooks.example.test/orders', 'enabled' => true],
                'attempts' => [self::attempt(['outcome' => 'skipped', 'region' => 'eu'])],
                'priority' => 'high',
            ]),
            self::pageResponse([self::delivery(['priority' => 'high'])], null),
        ]);

        $delivery = $client->deliveries->get(self::ID);
        $page = $client->deliveries->list();

        $this->assertSame('paused', $delivery->status);
        $this->assertSame('archived', $delivery->payloadState);
        $this->assertSame('skipped', $delivery->attempts[0]->outcome);
        $this->assertSame(self::ID, $page->data[0]->id);
    }
}
