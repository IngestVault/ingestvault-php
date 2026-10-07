<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Exception\ApiException;
use IngestVault\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;

final class SubscriptionsTest extends TestCase
{
    private const ENDPOINT = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    private const ID = '0199b2c4-3333-7a3b-9c4d-5e6f7a8b9c03';

    private const BASE = 'https://api.example.test/v1/endpoints/' . self::ENDPOINT . '/subscriptions';

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
            static fn(Client $client): mixed => $client->subscriptions->list(self::ENDPOINT),
            self::pageResponse([self::subscription()], null),
            'GET', self::BASE, null,
        ];
        yield 'list with a page size and a cursor' => [
            static fn(Client $client): mixed => $client->subscriptions->list(self::ENDPOINT, 5, 'c2'),
            self::pageResponse([self::subscription()], null),
            'GET', self::BASE . '?page_size=5&cursor=c2', null,
        ];
        yield 'create' => [
            static fn(Client $client): mixed => $client->subscriptions->create(self::ENDPOINT, ['order.created', 'order.paid'], 'Orders'),
            self::subscriptionResponse(201),
            'POST', self::BASE, '{"filter":["order.created","order.paid"],"description":"Orders"}',
        ];
        yield 'create matching every event' => [
            static fn(Client $client): mixed => $client->subscriptions->create(self::ENDPOINT, []),
            self::subscriptionResponse(201),
            'POST', self::BASE, '{"filter":[],"description":null}',
        ];
        yield 'create with a filter whose keys have gaps' => [
            static fn(Client $client): mixed => $client->subscriptions->create(self::ENDPOINT, array_unique(['order.created', 'order.created', 'order.paid'])),
            self::subscriptionResponse(201),
            'POST', self::BASE, '{"filter":["order.created","order.paid"],"description":null}',
        ];
        yield 'get' => [
            static fn(Client $client): mixed => $client->subscriptions->get(self::ENDPOINT, self::ID),
            self::subscriptionResponse(),
            'GET', self::BASE . '/' . self::ID, null,
        ];
        yield 'get with ids holding a slash' => [
            static fn(Client $client): mixed => $client->subscriptions->get('e/1', 's/1'),
            self::subscriptionResponse(),
            'GET', 'https://api.example.test/v1/endpoints/e%2F1/subscriptions/s%2F1', null,
        ];
        yield 'update' => [
            static fn(Client $client): mixed => $client->subscriptions->update(self::ENDPOINT, self::ID, ['filter' => ['order.paid'], 'description' => 'Paid orders']),
            self::subscriptionResponse(),
            'PATCH', self::BASE . '/' . self::ID, '{"filter":["order.paid"],"description":"Paid orders"}',
        ];
        yield 'update with a filter whose keys have gaps' => [
            static fn(Client $client): mixed => $client->subscriptions->update(self::ENDPOINT, self::ID, ['filter' => array_unique(['order.created', 'order.created', 'order.paid'])]),
            self::subscriptionResponse(),
            'PATCH', self::BASE . '/' . self::ID, '{"filter":["order.created","order.paid"]}',
        ];
        yield 'update to match every event' => [
            static fn(Client $client): mixed => $client->subscriptions->update(self::ENDPOINT, self::ID, ['filter' => []]),
            self::subscriptionResponse(),
            'PATCH', self::BASE . '/' . self::ID, '{"filter":[]}',
        ];
        yield 'update clearing the description' => [
            static fn(Client $client): mixed => $client->subscriptions->update(self::ENDPOINT, self::ID, ['description' => null]),
            self::subscriptionResponse(),
            'PATCH', self::BASE . '/' . self::ID, '{"description":null}',
        ];
        yield 'update with no fields' => [
            static fn(Client $client): mixed => $client->subscriptions->update(self::ENDPOINT, self::ID, []),
            self::subscriptionResponse(),
            'PATCH', self::BASE . '/' . self::ID, '{}',
        ];
        yield 'delete' => [
            static fn(Client $client): mixed => $client->subscriptions->delete(self::ENDPOINT, self::ID),
            new Response(204),
            'DELETE', self::BASE . '/' . self::ID, null,
        ];
    }

    /**
     * @param \Closure(Client): Subscription $call
     */
    #[DataProvider('operationsReturningASubscription')]
    public function test_returns_the_subscription_from_the_answer(\Closure $call, int $status): void
    {
        $subscription = $call($this->client([self::subscriptionResponse($status)]));

        $this->assertSame(self::ID, $subscription->id);
        $this->assertSame(['order.created', 'order.paid'], $subscription->filter);
        $this->assertSame('Orders', $subscription->description);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $subscription->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-02T09:30:00Z'), $subscription->updatedAt);
    }

    /**
     * @return iterable<string, array{\Closure(Client): Subscription, int}>
     */
    public static function operationsReturningASubscription(): iterable
    {
        yield 'create' => [static fn(Client $client): Subscription => $client->subscriptions->create(self::ENDPOINT, ['order.created']), 201];
        yield 'get' => [static fn(Client $client): Subscription => $client->subscriptions->get(self::ENDPOINT, self::ID), 200];
        yield 'update' => [static fn(Client $client): Subscription => $client->subscriptions->update(self::ENDPOINT, self::ID, []), 200];
    }

    public function test_a_catch_all_subscription_with_no_description_is_read_as_such(): void
    {
        $subscription = $this->client([self::subscriptionResponse(overrides: ['filter' => [], 'description' => null])])
            ->subscriptions->get(self::ENDPOINT, self::ID);

        $this->assertSame([], $subscription->filter);
        $this->assertNull($subscription->description);
    }

    public function test_unknown_members_in_the_subscription_are_ignored(): void
    {
        $subscription = $this->client([self::subscriptionResponse(overrides: ['endpoint_id' => self::ENDPOINT, 'paused' => false])])
            ->subscriptions->get(self::ENDPOINT, self::ID);

        $this->assertSame(self::ID, $subscription->id);
    }

    public function test_a_filter_that_is_not_a_list_of_names_makes_the_answer_unreadable(): void
    {
        $client = $this->client([self::subscriptionResponse(overrides: ['filter' => ['order.created', 7]])]);

        try {
            $client->subscriptions->get(self::ENDPOINT, self::ID);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(ApiException::class, $e::class);
            $this->assertSame('The API answered with HTTP 200 but the body is not a subscription.', $e->getMessage());
        }
    }
}
