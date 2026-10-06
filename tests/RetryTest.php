<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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
        $event = $this->client([self::timeout(), self::eventResponse()], retries: 1)->sendEvent('order.created');

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
            $client->sendEvent('order.created');
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
            $client->sendEvent('order.created');
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
            $client->sendEvent('order.created');
        } finally {
            $this->assertCount(3, $this->history);
        }
    }

    public function test_a_server_error_is_retried_then_recovered(): void
    {
        $event = $this->client([new Response(503), self::eventResponse()], retries: 1)->sendEvent('order.created');

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
            $client->sendEvent('order.created');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->status);
            $this->assertSame('internal_error', $e->problemCode);
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
            $client->sendEvent('order.created');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertCount(1, $this->history);
    }
}
