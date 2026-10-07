<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use IngestVault\Client;

final class SendEventTest extends TestCase
{
    public function test_sends_the_event_with_the_expected_request(): void
    {
        $client = $this->client([self::eventResponse()], apiKey: 'ivk_abc', baseUrl: 'https://api.example.test/v1/');

        $client->events->send('order.created', ['order' => 1042, 'url' => 'https://shop.example/é'], 'order-1042');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.test/v1/events', (string) $request->getUri());
        $this->assertSame('Bearer ivk_abc', $this->sentHeader(0, 'Authorization'));
        $this->assertSame('application/json', $this->sentHeader(0, 'Content-Type'));
        $this->assertSame('application/json', $this->sentHeader(0, 'Accept'));
        $this->assertSame('order-1042', $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertSame('ingestvault-php/' . Client::VERSION, $this->sentHeader(0, 'User-Agent'));
        $this->assertSame(
            '{"type":"order.created","payload":{"order":1042,"url":"https://shop.example/é"}}',
            (string) $request->getBody(),
        );

        $options = $this->history[0]['options'];
        $this->assertSame(10.0, $options['timeout']);
        $this->assertSame(10.0, $options['connect_timeout']);
        $this->assertFalse($options['http_errors']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_returns_the_event_from_the_answer(): void
    {
        $event = $this->client([self::eventResponse()])->events->send('order.created', ['order' => 1042]);

        $this->assertSame('0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d', $event->id);
        $this->assertSame('order.created', $event->type);
        $this->assertSame('registered', $event->typeRegistrationStatus);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05T12:00:00Z'), $event->receivedAt);
        $this->assertFalse($event->idempotent);
    }

    public function test_an_unregistered_type_is_returned_not_raised(): void
    {
        $event = $this->client([self::eventResponse(overrides: ['type' => 'order.shipped', 'type_registration_status' => 'unregistered'])])
            ->events->send('order.shipped');

        $this->assertSame('unregistered', $event->typeRegistrationStatus);
    }

    public function test_a_repeat_with_the_same_key_returns_the_original_event_marked_as_a_repeat(): void
    {
        $client = $this->client([self::eventResponse(201), self::eventResponse(200)]);

        $first = $client->events->send('order.created', ['order' => 1042], 'order-1042');
        $repeat = $client->events->send('order.created', ['order' => 1042], 'order-1042');

        $this->assertSame($first->id, $repeat->id);
        $this->assertFalse($first->idempotent);
        $this->assertTrue($repeat->idempotent);
        $this->assertSame('order-1042', $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertSame('order-1042', $this->sentHeader(1, 'Idempotency-Key'));
    }

    public function test_sends_without_a_key_carry_different_keys(): void
    {
        $client = $this->client([self::eventResponse(), self::eventResponse()]);

        $client->events->send('order.created');
        $client->events->send('order.created');

        $this->assertNotSame('', $this->sentHeader(0, 'Idempotency-Key'));
        $this->assertNotSame($this->sentHeader(0, 'Idempotency-Key'), $this->sentHeader(1, 'Idempotency-Key'));
    }

    public function test_unknown_members_in_the_answer_are_ignored(): void
    {
        $event = $this->client([self::eventResponse(overrides: [
            'type_registration_status' => 'pending_review',
            'source' => ['kind' => 'sdk'],
        ])])->events->send('order.created');

        $this->assertSame('pending_review', $event->typeRegistrationStatus);
    }

    public function test_the_client_builds_from_plain_configuration_values(): void
    {
        $mock = new MockHandler([self::eventResponse()]);
        $config = [
            'apiKey' => 'ivk_from_config',
            'baseUrl' => 'http://api.ingestvault.test/v1',
            'timeout' => 2.5,
            'retries' => 0,
        ];

        $client = new Client(...$config, httpClient: new GuzzleClient(['handler' => $mock]));
        $client->events->send('order.created');

        $request = $mock->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('http://api.ingestvault.test/v1/events', (string) $request->getUri());
        $this->assertSame('Bearer ivk_from_config', $request->getHeaderLine('Authorization'));
        $this->assertSame(2.5, $mock->getLastOptions()['timeout']);
    }

    public function test_the_network_layer_can_be_replaced(): void
    {
        $mock = new MockHandler([self::eventResponse()]);
        $client = new Client('ivk_test_key', httpClient: new GuzzleClient(['handler' => $mock]));

        $event = $client->events->send('order.created', ['order' => 1042]);

        $this->assertSame('0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d', $event->id);
        $this->assertCount(0, $mock);
        $this->assertSame('https://api.ingestvault.com/v1/events', (string) $mock->getLastRequest()?->getUri());
    }
}
