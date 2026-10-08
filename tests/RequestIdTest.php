<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\PreparedEvent;
use PHPUnit\Framework\Attributes\DataProvider;

final class RequestIdTest extends TestCase
{
    private const ID = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    private const REQUEST_ID = 'req_3f9a1c2b7d4e8f6051a2b3c4d5e6f708';

    /**
     * @param \Closure(Client): object $call
     */
    #[DataProvider('results')]
    public function test_every_result_carries_the_request_id_of_its_answer(\Closure $call, Response $answer): void
    {
        $result = $call($this->client([$answer->withHeader('Request-Id', self::REQUEST_ID)]));

        $this->assertObjectHasProperty('requestId', $result);
        $this->assertSame(self::REQUEST_ID, $result->requestId);
    }

    /**
     * @return iterable<string, array{\Closure(Client): object, Response}>
     */
    public static function results(): iterable
    {
        yield 'send an event' => [static fn(Client $client): object => $client->events->send('order.created', ['order' => 1042]), self::eventResponse(201)];
        yield 'send a prepared event again' => [static fn(Client $client): object => $client->events->sendPrepared(new PreparedEvent('order.created', ['order' => 1042])), self::eventResponse(200)];
        yield 'get an event' => [static fn(Client $client): object => $client->events->get(self::ID), self::eventResponse(200)];
        yield 'replay an event' => [static fn(Client $client): object => $client->events->replay(self::ID), self::eventResponse(201, ['replay' => true, 'root_event_id' => self::ID])];
        yield 'get a delivery' => [static fn(Client $client): object => $client->deliveries->get(self::ID), self::deliveryResponse()];
        yield 'replay a delivery' => [static fn(Client $client): object => $client->deliveries->replay(self::ID), self::eventResponse(201, ['replay' => true, 'root_event_id' => self::ID])];
        yield 'create an endpoint' => [static fn(Client $client): object => $client->endpoints->create('https://hooks.example.test/orders'), self::endpointResponse(201)];
        yield 'get an endpoint' => [static fn(Client $client): object => $client->endpoints->get(self::ID), self::endpointResponse()];
        yield 'update an endpoint' => [static fn(Client $client): object => $client->endpoints->update(self::ID, ['enabled' => false]), self::endpointResponse()];
        yield 'create an event type' => [static fn(Client $client): object => $client->eventTypes->create('order.created'), self::eventTypeResponse(201)];
        yield 'update a subscription' => [static fn(Client $client): object => $client->subscriptions->update(self::ID, 's1', ['description' => null]), self::subscriptionResponse()];
        yield 'rotate a signing secret' => [static fn(Client $client): object => $client->signingSecrets->rotate(self::ID), self::signingSecretResponse(201)];
        yield 'read the current organization' => [static fn(Client $client): object => $client->organization->current(), self::organizationResponse()];
    }

    public function test_every_signing_secret_listed_carries_the_request_id_of_the_answer(): void
    {
        $answer = new Response(200, ['Content-Type' => 'application/json', 'Request-Id' => self::REQUEST_ID], json_encode([
            'data' => [self::signingSecret(), self::signingSecret(['id' => '0199b2c4-4444-7a3b-9c4d-5e6f7a8b9c09'])],
        ], JSON_THROW_ON_ERROR));

        $secrets = $this->client([$answer])->signingSecrets->list(self::ID);

        $this->assertCount(2, $secrets);
        $this->assertSame(self::REQUEST_ID, $secrets[0]->requestId);
        $this->assertSame(self::REQUEST_ID, $secrets[1]->requestId);
    }

    public function test_no_request_id_header_is_sent(): void
    {
        $this->client([self::eventResponse()])->events->send('order.created');

        $this->assertFalse($this->history[0]['request']->hasHeader('Request-Id'));
    }
}
