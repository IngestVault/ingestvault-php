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
