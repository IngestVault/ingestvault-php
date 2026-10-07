<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<array{request: RequestInterface, response: ?ResponseInterface, error: mixed, options: array<string, mixed>}> */
    protected array $history = [];

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    protected function client(array $queue, ?int $retries = null, string $apiKey = 'ivk_test_key', string $baseUrl = 'https://api.example.test/v1'): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $httpClient = new GuzzleClient(['handler' => $stack]);

        return $retries === null
            ? new Client($apiKey, $baseUrl, httpClient: $httpClient)
            : new Client($apiKey, $baseUrl, retries: $retries, httpClient: $httpClient);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function eventResponse(int $status = 201, array $overrides = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($overrides + [
            'id' => '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d',
            'type' => 'order.created',
            'type_registration_status' => 'registered',
            'payload' => ['order' => 1042],
            'payload_state' => 'available',
            'received_at' => '2026-10-05T12:00:00Z',
            'idempotent' => $status === 200,
            'replay' => false,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    protected static function problemResponse(int $status, string $code, string $detail, array $extra = [], array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/problem+json'], json_encode([
            'title' => 'Problem',
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
        ] + $extra, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function endpointResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::endpoint($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function eventTypeResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::eventType($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function subscriptionResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::subscription($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function signingSecretResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::signingSecret($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function deliveryResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::deliveryWithAttempts($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected static function organizationResponse(int $status = 200, array $overrides = []): Response
    {
        return self::jsonResponse($status, self::organization($overrides));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    protected static function pageResponse(array $items, ?string $nextCursor): Response
    {
        return self::jsonResponse(200, ['data' => $items, 'has_more' => $nextCursor !== null, 'next_cursor' => $nextCursor]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function endpoint(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01',
            'url' => 'https://hooks.example.test/orders',
            'description' => 'Order hooks',
            'enabled' => true,
            'created_at' => '2026-10-01T08:00:00Z',
            'updated_at' => '2026-10-02T09:30:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function eventType(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-2222-7a3b-9c4d-5e6f7a8b9c02',
            'name' => 'order.created',
            'description' => 'An order was placed.',
            'archived' => false,
            'created_at' => '2026-10-01T08:00:00Z',
            'updated_at' => '2026-10-02T09:30:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function subscription(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-3333-7a3b-9c4d-5e6f7a8b9c03',
            'filter' => ['order.created', 'order.paid'],
            'description' => 'Orders',
            'created_at' => '2026-10-01T08:00:00Z',
            'updated_at' => '2026-10-02T09:30:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function signingSecret(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-4444-7a3b-9c4d-5e6f7a8b9c04',
            'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
            'expires_at' => null,
            'created_at' => '2026-10-01T08:00:00Z',
            'updated_at' => '2026-10-01T08:00:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function eventSummary(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d',
            'type' => 'order.created',
            'type_registration_status' => 'registered',
            'received_at' => '2026-10-05T12:00:00Z',
            'replay' => false,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function delivery(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-5555-7a3b-9c4d-5e6f7a8b9c05',
            'status' => 'retrying',
            'attempt_count' => 2,
            'next_attempt_at' => '2026-10-05T12:05:00Z',
            'event' => ['id' => '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d', 'type' => 'order.created'],
            'endpoint' => ['id' => '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01', 'url' => 'https://hooks.example.test/orders'],
            'created_at' => '2026-10-05T12:00:01Z',
            'updated_at' => '2026-10-05T12:01:30Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function deliveryWithAttempts(array $overrides = []): array
    {
        return $overrides + self::delivery() + [
            'payload_state' => 'available',
            'attempts' => [
                self::attempt(),
                self::attempt([
                    'id' => '0199b2c4-6666-7a3b-9c4d-5e6f7a8b9c07',
                    'http_status' => null,
                    'error_classification' => 'timeout',
                    'duration_ms' => 10000,
                    'response_body' => null,
                    'attempted_at' => '2026-10-05T12:01:30Z',
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function attempt(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-6666-7a3b-9c4d-5e6f7a8b9c06',
            'outcome' => 'failed',
            'http_status' => 503,
            'error_classification' => null,
            'duration_ms' => 182,
            'response_body' => 'Service Unavailable',
            'attempted_at' => '2026-10-05T12:00:02Z',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function organization(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0199b2c4-8888-7a3b-9c4d-5e6f7a8b9c08',
            'name' => 'Acme',
            'notification_email' => 'ops@acme.example',
            'created_at' => '2026-09-01T10:00:00Z',
            'payloads_visible' => false,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function sentHeader(int $index, string $name): string
    {
        return $this->history[$index]['request']->getHeaderLine($name);
    }

    protected function sentUri(int $index): string
    {
        return (string) $this->history[$index]['request']->getUri();
    }

    protected function sentBody(int $index): string
    {
        return (string) $this->history[$index]['request']->getBody();
    }

    /**
     * Asserts the shape every configuration request shares: no idempotency key, and a JSON content type only with a body.
     */
    protected function assertSentRequest(string $method, string $uri, ?string $body, int $index = 0): void
    {
        $request = $this->history[$index]['request'];

        $this->assertSame($method, $request->getMethod());
        $this->assertSame($uri, $this->sentUri($index));
        $this->assertSame('Bearer ivk_test_key', $this->sentHeader($index, 'Authorization'));
        $this->assertSame('application/json', $this->sentHeader($index, 'Accept'));
        $this->assertSame('ingestvault-php/' . Client::VERSION, $this->sentHeader($index, 'User-Agent'));
        $this->assertFalse($request->hasHeader('Idempotency-Key'));
        $this->assertSame($body ?? '', $this->sentBody($index));
        $this->assertSame($body === null ? '' : 'application/json', $this->sentHeader($index, 'Content-Type'));
    }
}
