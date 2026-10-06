<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Exception\IngestVaultException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiKeyTest extends TestCase
{
    private const KEY = 'ivk_live_8Hq2ZtW0secretKeyValue';

    public function test_the_key_is_absent_from_every_printable_form_of_the_client(): void
    {
        $client = new Client(self::KEY);

        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();

        try {
            $serialized = serialize($client);
        } catch (\Exception $e) {
            $serialized = $e->getMessage();
        }

        foreach ([
            'print_r' => print_r($client, true),
            'var_export' => var_export($client, true),
            'var_dump' => $dumped,
            'json_encode' => json_encode($client, JSON_THROW_ON_ERROR),
            'array cast' => print_r((array) $client, true),
            'serialize' => $serialized,
        ] as $form => $output) {
            $this->assertStringNotContainsString(self::KEY, $output, $form);
        }
    }

    #[DataProvider('failures')]
    public function test_the_key_is_absent_from_errors(Response|\Throwable $answer): void
    {
        $client = $this->client([$answer], retries: 0, apiKey: self::KEY);

        try {
            $client->sendEvent('order.created', ['order' => 1042]);
            $this->fail('Expected an IngestVaultException.');
        } catch (IngestVaultException $e) {
            // The request history belongs to this test, not the SDK; clear it so only the exception is inspected.
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertStringContainsString('order.created', $printed, 'trace arguments are included');
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
            $this->assertStringNotContainsString(self::KEY, $printed);
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{Response|\Throwable}>
     */
    public static function failures(): iterable
    {
        yield 'authentication failure' => [self::problemResponse(401, 'unauthenticated', 'The API key is invalid.')];
        yield 'server error' => [self::problemResponse(500, 'internal_error', 'Something went wrong.')];
        yield 'network failure' => [new ConnectException('Could not resolve host', new Request('POST', 'events'))];
    }

    public function test_a_key_that_cannot_be_a_header_value_fails_without_showing_it(): void
    {
        $mock = new MockHandler([self::eventResponse()]);

        try {
            new Client(self::KEY . "\n", httpClient: new GuzzleClient(['handler' => $mock]));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The API key contains characters that cannot be sent in a header.', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, print_r($e, true));
        }

        $this->assertCount(1, $mock);
    }

    public function test_the_key_is_absent_from_an_error_about_an_invalid_idempotency_key(): void
    {
        $client = $this->client([self::eventResponse()], retries: 0, apiKey: self::KEY);

        try {
            $client->sendEvent('order.created', null, "order-1042\n");
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->history = [];
            $printed = print_r($e, true);

            $this->assertStringContainsString('order.created', $printed, 'trace arguments are included');
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $printed);
            $this->assertNull($e->getPrevious());
        }
    }

    #[DataProvider('emptyKeys')]
    public function test_an_empty_key_fails_before_any_request(string $key): void
    {
        $mock = new MockHandler([self::eventResponse()]);

        try {
            new Client($key, httpClient: new GuzzleClient(['handler' => $mock]));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('An API key is required.', $e->getMessage());
        }

        $this->assertCount(1, $mock);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n"];
    }

    public function test_a_missing_key_is_a_type_error(): void
    {
        $this->expectException(\TypeError::class);

        new Client(null);
    }
}
