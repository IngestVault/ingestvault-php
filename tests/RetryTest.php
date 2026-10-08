<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\NetworkException;
use IngestVault\Exception\QuotaExceededException;
use IngestVault\Exception\RateLimitedException;
use IngestVault\Exception\ServerException;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryTest extends TestCase
{
    private static function timeout(): ConnectException
    {
        return new ConnectException('Connection timed out after 10001 milliseconds', new Request('POST', 'events'));
    }

    public function test_a_timeout_then_a_success_sends_one_event_with_the_same_key_on_both_tries(): void
    {
        $event = $this->client([self::timeout(), self::eventResponse()], retries: 1)->events->send('order.created');

        $this->assertSame('0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d', $event->id);
        $this->assertCount(2, $this->history);
        $this->assertNotSame('', $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertSame($this->sentHeader(0, 'Idempotency-Key'), $this->sentHeader(1, 'Idempotency-Key'));
        $this->assertSame((string) $this->history[0]['request']->getBody(), (string) $this->history[1]['request']->getBody());
    }

    public function test_with_retries_at_zero_a_network_failure_is_raised_after_one_try(): void
    {
        $client = $this->client([self::timeout(), self::eventResponse()], retries: 0);

        try {
            $client->events->send('order.created');
            $this->fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            $this->assertSame('Connection timed out after 10001 milliseconds', $e->getMessage());
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('tooManyRequests')]
    public function test_a_429_is_raised_after_one_try_with_the_wait_in_seconds(string $code, string $class): void
    {
        $client = $this->client([
            self::problemResponse(429, $code, 'Slow down.', headers: ['Retry-After' => '37']),
            self::eventResponse(),
        ], retries: 2);

        try {
            $client->events->send('order.created');
            $this->fail('Expected a ' . $class . '.');
        } catch (RateLimitedException|QuotaExceededException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame(429, $e->status);
            $this->assertSame($code, $e->problemCode);
            $this->assertSame(37, $e->retryAfter);
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @return iterable<string, array{string, class-string<ApiException>}>
     */
    public static function tooManyRequests(): iterable
    {
        yield 'quota exceeded' => ['quota_exceeded', QuotaExceededException::class];
        yield 'rate limited' => ['rate_limited', RateLimitedException::class];
    }

    public function test_the_default_retry_count_makes_three_tries(): void
    {
        $client = $this->client([self::timeout(), self::timeout(), self::timeout(), self::eventResponse()]);

        $this->expectException(NetworkException::class);

        try {
            $client->events->send('order.created');
        } finally {
            $this->assertCount(3, $this->history);
        }
    }

    public function test_a_server_error_is_retried_then_recovered(): void
    {
        $event = $this->client([new Response(503), self::eventResponse()], retries: 1)->events->send('order.created');

        $this->assertSame('0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d', $event->id);
        $this->assertCount(2, $this->history);
        $this->assertSame($this->sentHeader(0, 'Idempotency-Key'), $this->sentHeader(1, 'Idempotency-Key'));
    }

    public function test_a_server_error_is_retried_then_raised(): void
    {
        $client = $this->client([
            self::problemResponse(500, 'internal_error', 'Something went wrong.'),
            self::problemResponse(500, 'internal_error', 'Something went wrong.'),
            self::eventResponse(),
        ], retries: 1);

        try {
            $client->events->send('order.created');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->status);
            $this->assertSame('internal_error', $e->problemCode);
        }

        $this->assertCount(2, $this->history);
    }

    public function test_a_server_error_retried_then_raised_carries_the_request_id_of_the_last_try(): void
    {
        $client = $this->client([
            (new Response(503))->withHeader('Request-Id', 'req_a'),
            self::problemResponse(500, 'internal_error', 'Something went wrong.')->withHeader('Request-Id', 'req_b'),
        ], retries: 1);

        try {
            $client->events->send('order.created');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame('req_b', $e->requestId);
            $this->assertTrue(str_ends_with($e->getMessage(), ' (request req_b)'), $e->getMessage());
        }

        $this->assertCount(2, $this->history);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        $client = $this->client([
            self::problemResponse(400, 'malformed_json', 'The body is not valid JSON.'),
            self::eventResponse(),
        ], retries: 2);

        try {
            $client->events->send('order.created');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('retriedOperations')]
    public function test_a_timeout_on_a_retried_operation_is_retried_then_recovered(\Closure $call, \Closure $success): void
    {
        $call($this->client([self::timeout(), $success()], retries: 1));

        $this->assertCount(2, $this->history);
        $this->assertSame($this->sentUri(0), $this->sentUri(1));
        $this->assertSame($this->sentBody(0), $this->sentBody(1));
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('retriedOperations')]
    public function test_a_server_error_on_a_retried_operation_is_retried_then_recovered(\Closure $call, \Closure $success): void
    {
        $call($this->client([new Response(503), $success()], retries: 1));

        $this->assertCount(2, $this->history);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, \Closure(): Response}>
     */
    public static function retriedOperations(): iterable
    {
        $id = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';
        $endpoint = static fn(): Response => self::endpointResponse();
        $eventType = static fn(): Response => self::eventTypeResponse();
        $subscription = static fn(): Response => self::subscriptionResponse();
        $noContent = static fn(): Response => new Response(204);

        yield 'send an event' => [static fn(Client $client): mixed => $client->events->send('order.created'), static fn(): Response => self::eventResponse()];
        yield 'list endpoints' => [static fn(Client $client): mixed => $client->endpoints->list(), static fn(): Response => self::pageResponse([self::endpoint()], null)];
        yield 'get an endpoint' => [static fn(Client $client): mixed => $client->endpoints->get($id), $endpoint];
        yield 'update an endpoint' => [static fn(Client $client): mixed => $client->endpoints->update($id, ['enabled' => false]), $endpoint];
        yield 'delete an endpoint' => [static fn(Client $client): mixed => $client->endpoints->delete($id), $noContent];
        yield 'list signing secrets' => [static fn(Client $client): mixed => $client->signingSecrets->list($id), static fn(): Response => new Response(200, [], '{"data":[]}')];
        yield 'list subscriptions' => [static fn(Client $client): mixed => $client->subscriptions->list($id), static fn(): Response => self::pageResponse([self::subscription()], null)];
        yield 'get a subscription' => [static fn(Client $client): mixed => $client->subscriptions->get($id, 's1'), $subscription];
        yield 'update a subscription' => [static fn(Client $client): mixed => $client->subscriptions->update($id, 's1', ['filter' => []]), $subscription];
        yield 'delete a subscription' => [static fn(Client $client): mixed => $client->subscriptions->delete($id, 's1'), $noContent];
        yield 'list event types' => [static fn(Client $client): mixed => $client->eventTypes->list(), static fn(): Response => self::pageResponse([self::eventType()], null)];
        yield 'get an event type' => [static fn(Client $client): mixed => $client->eventTypes->get($id), $eventType];
        yield 'update an event type' => [static fn(Client $client): mixed => $client->eventTypes->update($id, ['description' => null]), $eventType];
        yield 'archive an event type' => [static fn(Client $client): mixed => $client->eventTypes->archive($id), $eventType];
        yield 'unarchive an event type' => [static fn(Client $client): mixed => $client->eventTypes->unarchive($id), $eventType];
        yield 'list events' => [static fn(Client $client): mixed => $client->events->list(type: 'order.created'), static fn(): Response => self::pageResponse([self::eventSummary()], null)];
        yield 'get an event' => [static fn(Client $client): mixed => $client->events->get($id), static fn(): Response => self::eventResponse(200, ['idempotent' => false])];
        yield 'list deliveries' => [static fn(Client $client): mixed => $client->deliveries->list(status: 'failed'), static fn(): Response => self::pageResponse([self::delivery()], null)];
        yield 'get a delivery' => [static fn(Client $client): mixed => $client->deliveries->get($id), static fn(): Response => self::deliveryResponse()];
        yield 'read the current organization' => [static fn(Client $client): mixed => $client->organization->current(), static fn(): Response => self::organizationResponse()];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('neverRetriedOperations')]
    public function test_a_timeout_on_a_create_or_a_rotation_is_raised_after_one_try(\Closure $call, \Closure $success): void
    {
        $client = $this->client([self::timeout(), $success()], retries: 2);

        try {
            $call($client);
            $this->fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            $this->assertSame('Connection timed out after 10001 milliseconds', $e->getMessage());
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('neverRetriedOperations')]
    public function test_a_server_error_on_a_create_or_a_rotation_is_raised_after_one_try(\Closure $call, \Closure $success): void
    {
        $client = $this->client([self::problemResponse(500, 'internal_error', 'Something went wrong.'), $success()], retries: 2);

        try {
            $call($client);
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->status);
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, \Closure(): Response}>
     */
    public static function neverRetriedOperations(): iterable
    {
        $id = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

        yield 'create an endpoint' => [static fn(Client $client): mixed => $client->endpoints->create('https://hooks.example.test/orders'), static fn(): Response => self::endpointResponse(201)];
        yield 'create a subscription' => [static fn(Client $client): mixed => $client->subscriptions->create($id, []), static fn(): Response => self::subscriptionResponse(201)];
        yield 'create an event type' => [static fn(Client $client): mixed => $client->eventTypes->create('order.created'), static fn(): Response => self::eventTypeResponse(201)];
        yield 'rotate a signing secret' => [static fn(Client $client): mixed => $client->signingSecrets->rotate($id), static fn(): Response => self::signingSecretResponse(201)];
    }
}
