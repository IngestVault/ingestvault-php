<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Endpoint;
use PHPUnit\Framework\Attributes\DataProvider;

final class EndpointsTest extends TestCase
{
    private const ID = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

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
            static fn(Client $client): mixed => $client->endpoints->list(),
            self::pageResponse([self::endpoint()], null),
            'GET', self::BASE . '/endpoints', null,
        ];
        yield 'list with a page size and a cursor' => [
            static fn(Client $client): mixed => $client->endpoints->list(10, 'c2'),
            self::pageResponse([self::endpoint()], null),
            'GET', self::BASE . '/endpoints?page_size=10&cursor=c2', null,
        ];
        yield 'create' => [
            static fn(Client $client): mixed => $client->endpoints->create('https://hooks.example.test/orders', 'Order hooks', false),
            self::endpointResponse(201),
            'POST', self::BASE . '/endpoints', '{"url":"https://hooks.example.test/orders","description":"Order hooks","enabled":false}',
        ];
        yield 'create with only the url' => [
            static fn(Client $client): mixed => $client->endpoints->create('https://hooks.example.test/orders'),
            self::endpointResponse(201),
            'POST', self::BASE . '/endpoints', '{"url":"https://hooks.example.test/orders","description":null,"enabled":true}',
        ];
        yield 'get' => [
            static fn(Client $client): mixed => $client->endpoints->get(self::ID),
            self::endpointResponse(),
            'GET', self::BASE . '/endpoints/' . self::ID, null,
        ];
        yield 'get with an id holding a slash and a space' => [
            static fn(Client $client): mixed => $client->endpoints->get('a/b c'),
            self::endpointResponse(),
            'GET', self::BASE . '/endpoints/a%2Fb%20c', null,
        ];
        yield 'update' => [
            static fn(Client $client): mixed => $client->endpoints->update(self::ID, ['url' => 'https://hooks.example.test/v2', 'enabled' => false]),
            self::endpointResponse(),
            'PATCH', self::BASE . '/endpoints/' . self::ID, '{"url":"https://hooks.example.test/v2","enabled":false}',
        ];
        yield 'update clearing the description' => [
            static fn(Client $client): mixed => $client->endpoints->update(self::ID, ['description' => null]),
            self::endpointResponse(),
            'PATCH', self::BASE . '/endpoints/' . self::ID, '{"description":null}',
        ];
        yield 'update with no fields' => [
            static fn(Client $client): mixed => $client->endpoints->update(self::ID, []),
            self::endpointResponse(),
            'PATCH', self::BASE . '/endpoints/' . self::ID, '{}',
        ];
        yield 'delete' => [
            static fn(Client $client): mixed => $client->endpoints->delete(self::ID),
            new Response(204),
            'DELETE', self::BASE . '/endpoints/' . self::ID, null,
        ];
    }

    /**
     * @param \Closure(Client): Endpoint $call
     */
    #[DataProvider('operationsReturningAnEndpoint')]
    public function test_returns_the_endpoint_from_the_answer(\Closure $call, int $status): void
    {
        $endpoint = $call($this->client([self::endpointResponse($status)]));

        $this->assertSame(self::ID, $endpoint->id);
        $this->assertSame('https://hooks.example.test/orders', $endpoint->url);
        $this->assertSame('Order hooks', $endpoint->description);
        $this->assertTrue($endpoint->enabled);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01T08:00:00Z'), $endpoint->createdAt);
        $this->assertEquals(new \DateTimeImmutable('2026-10-02T09:30:00Z'), $endpoint->updatedAt);
    }

    /**
     * @return iterable<string, array{\Closure(Client): Endpoint, int}>
     */
    public static function operationsReturningAnEndpoint(): iterable
    {
        yield 'create' => [static fn(Client $client): Endpoint => $client->endpoints->create('https://hooks.example.test/orders'), 201];
        yield 'get' => [static fn(Client $client): Endpoint => $client->endpoints->get(self::ID), 200];
        yield 'update' => [static fn(Client $client): Endpoint => $client->endpoints->update(self::ID, ['enabled' => true]), 200];
    }

    public function test_a_null_description_and_a_disabled_endpoint_are_read_as_such(): void
    {
        $endpoint = $this->client([self::endpointResponse(overrides: ['description' => null, 'enabled' => false])])->endpoints->get(self::ID);

        $this->assertNull($endpoint->description);
        $this->assertFalse($endpoint->enabled);
    }

    public function test_unknown_members_in_the_endpoint_are_ignored(): void
    {
        $endpoint = $this->client([self::endpointResponse(overrides: ['health' => 'degraded', 'labels' => ['team' => 'shop']])])
            ->endpoints->get(self::ID);

        $this->assertSame(self::ID, $endpoint->id);
    }

    public function test_the_endpoints_of_a_page_are_read(): void
    {
        $page = $this->client([self::pageResponse([self::endpoint(), self::endpoint(['id' => 'second'])], null)])->endpoints->list();

        $this->assertCount(2, $page->data);
        $this->assertSame([self::ID, 'second'], array_map(static fn(Endpoint $endpoint): string => $endpoint->id, $page->data));
    }
}
