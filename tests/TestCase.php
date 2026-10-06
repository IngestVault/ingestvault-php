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

    protected function sentHeader(int $index, string $name): string
    {
        return $this->history[$index]['request']->getHeaderLine($name);
    }
}
