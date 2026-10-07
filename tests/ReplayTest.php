<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Event;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\QuotaExceededException;
use IngestVault\Exception\RateLimitedException;
use IngestVault\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReplayTest extends TestCase
{
    private const EVENT = '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d';

    private const DELIVERY = '0199b2c4-5555-7a3b-9c4d-5e6f7a8b9c05';

    private const ROOT = '0199b2c4-0000-7a3b-9c4d-5e6f7a8b9c00';

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /**
     * @return iterable<string, array{\Closure(Client, ?string): Event}>
     */
    public static function replays(): iterable
    {
        foreach (self::replaysWithTheirUri() as $name => [$replay]) {
            yield $name => [$replay];
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client, ?string): Event, string}>
     */
    public static function replaysWithTheirUri(): iterable
    {
        yield 'event replay' => [
            static fn(Client $client, ?string $key): Event => $client->events->replay(self::EVENT, $key),
            'https://api.example.test/v1/events/' . self::EVENT . '/replay',
        ];
        yield 'delivery replay' => [
            static fn(Client $client, ?string $key): Event => $client->deliveries->replay(self::DELIVERY, $key),
            'https://api.example.test/v1/deliveries/' . self::DELIVERY . '/replay',
        ];
    }

    private static function replayed(int $status = 201): Response
    {
        return self::eventResponse($status, ['id' => '0199b2c4-aaaa-7a3b-9c4d-5e6f7a8b9c0a', 'replay' => true, 'root_event_id' => self::ROOT, 'initiator' => null]);
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     */
    #[DataProvider('replays')]
    public function test_a_replay_without_a_key_sends_a_generated_one(\Closure $replay): void
    {
        $client = $this->client([self::replayed(), self::replayed()]);

        $replay($client, null);
        $replay($client, null);

        $this->assertMatchesRegularExpression(self::UUID_V4, $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertMatchesRegularExpression(self::UUID_V4, $this->sentHeader(1, 'Idempotency-Key'));
        $this->assertNotSame($this->sentHeader(0, 'Idempotency-Key'), $this->sentHeader(1, 'Idempotency-Key'));
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     */
    #[DataProvider('replays')]
    public function test_a_replay_with_a_key_sends_it_as_given(\Closure $replay): void
    {
        $replay($this->client([self::replayed()]), 'replay-order-1042');

        $this->assertSame('replay-order-1042', $this->sentHeader(0, 'Idempotency-Key'));
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     */
    #[DataProvider('replaysWithTheirUri')]
    public function test_a_replay_sends_no_body(\Closure $replay, string $uri): void
    {
        $replay($this->client([self::replayed()]), null);

        $this->assertCount(1, $this->history);
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame($uri, $this->sentUri(0));
        $this->assertSame('Bearer ivk_test_key', $this->sentHeader(0, 'Authorization'));
        $this->assertSame('application/json', $this->sentHeader(0, 'Accept'));
        $this->assertSame('ingestvault-php/' . Client::VERSION, $this->sentHeader(0, 'User-Agent'));
        $this->assertSame('', $this->sentBody(0));
        $this->assertFalse($this->history[0]['request']->hasHeader('Content-Type'));
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     */
    #[DataProvider('replays')]
    public function test_a_fresh_replay_and_an_idempotent_repeat_are_told_apart(\Closure $replay): void
    {
        $client = $this->client([self::replayed(201), self::replayed(200)]);

        $fresh = $replay($client, 'replay-order-1042');
        $repeat = $replay($client, 'replay-order-1042');

        $this->assertSame($fresh->id, $repeat->id);
        $this->assertFalse($fresh->idempotent);
        $this->assertTrue($repeat->idempotent);
        $this->assertTrue($fresh->replay);
        $this->assertTrue($repeat->replay);
        $this->assertSame(self::ROOT, $fresh->rootEventId);
        $this->assertSame(self::ROOT, $repeat->rootEventId);
        $this->assertSame('available', $fresh->payloadState);
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     * @param \Closure(): (Response|\Throwable) $failure
     */
    #[DataProvider('retriedReplays')]
    public function test_a_retried_replay_sends_the_same_key_on_every_try(\Closure $replay, \Closure $failure, ?string $key): void
    {
        $event = $replay($this->client([$failure(), self::replayed()], retries: 1), $key);

        $this->assertSame('0199b2c4-aaaa-7a3b-9c4d-5e6f7a8b9c0a', $event->id);
        $this->assertCount(2, $this->history);
        $this->assertNotSame('', $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertSame($this->sentHeader(0, 'Idempotency-Key'), $this->sentHeader(1, 'Idempotency-Key'));
        if ($key !== null) {
            $this->assertSame($key, $this->sentHeader(1, 'Idempotency-Key'));
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client, ?string): Event, \Closure(): (Response|\Throwable), ?string}>
     */
    public static function retriedReplays(): iterable
    {
        $failures = [
            'a timeout' => static fn(): ConnectException => new ConnectException('Connection timed out after 10001 milliseconds', new Request('POST', 'replay')),
            'a 503' => static fn(): Response => new Response(503),
        ];

        foreach (self::replays() as $name => [$replay]) {
            foreach ($failures as $failureName => $failure) {
                yield $name . ' after ' . $failureName . ' with a generated key' => [$replay, $failure, null];
                yield $name . ' after ' . $failureName . ' with a given key' => [$replay, $failure, 'replay-order-1042'];
            }
        }
    }

    /**
     * @param \Closure(Client, ?string): Event $replay
     * @param class-string<ApiException> $class
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('refusals')]
    public function test_a_refused_replay_raises_an_api_error_with_its_status_and_code_after_one_try(\Closure $replay, Response $answer, string $class, int $status, string $code, array $errors, ?int $retryAfter): void
    {
        $client = $this->client([$answer, self::replayed()], retries: 2);

        try {
            $replay($client, null);
            $this->fail('Expected a ' . $class . '.');
        } catch (ApiException $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->problemCode);
            if ($e instanceof ValidationException) {
                $this->assertSame($errors, $e->errors);
            }
            if ($e instanceof RateLimitedException || $e instanceof QuotaExceededException) {
                $this->assertSame($retryAfter, $e->retryAfter);
            }
        }

        $this->assertCount(1, $this->history);
    }

    /**
     * @return iterable<string, array{\Closure(Client, ?string): Event, Response, class-string<ApiException>, int, string, array<string, list<string>>, ?int}>
     */
    public static function refusals(): iterable
    {
        $inFlight = ['delivery' => ['The delivery is still in flight; replay it once it has settled.']];
        $disabled = ['endpoint' => ['The endpoint is disabled; enable it before replaying.']];

        foreach (self::replays() as $name => [$replay]) {
            yield $name . ' of an expired payload' => [
                $replay, self::problemResponse(409, 'payload_expired', 'The payload has expired.'),
                ApiException::class, 409, 'payload_expired', [], null,
            ];
            yield $name . ' of a missing resource' => [
                $replay, self::problemResponse(404, 'not_found', 'The resource does not exist.'),
                ApiException::class, 404, 'not_found', [], null,
            ];
            yield $name . ' over the rate limit' => [
                $replay, self::problemResponse(429, 'rate_limited', 'Slow down.', headers: ['Retry-After' => '12']),
                RateLimitedException::class, 429, 'rate_limited', [], 12,
            ];
            yield $name . ' over the quota' => [
                $replay, self::problemResponse(429, 'quota_exceeded', 'The quota is used up.', headers: ['Retry-After' => '3600']),
                QuotaExceededException::class, 429, 'quota_exceeded', [], 3600,
            ];
        }

        $deliveryReplay = iterator_to_array(self::replays())['delivery replay'][0];
        yield 'delivery replay of a delivery still in flight' => [
            $deliveryReplay, self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $inFlight]),
            ValidationException::class, 422, 'validation_failed', $inFlight, null,
        ];
        yield 'delivery replay to a disabled endpoint' => [
            $deliveryReplay, self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $disabled]),
            ValidationException::class, 422, 'validation_failed', $disabled, null,
        ];
    }
}
