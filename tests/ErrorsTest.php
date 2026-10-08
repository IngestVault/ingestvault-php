<?php

declare(strict_types=1);

namespace IngestVault\Tests;

use GuzzleHttp\Psr7\Response;
use IngestVault\Client;
use IngestVault\Exception\ApiException;
use IngestVault\Exception\AuthenticationException;
use IngestVault\Exception\IdempotencyConflictException;
use IngestVault\Exception\IngestVaultException;
use IngestVault\Exception\PayloadTooLargeException;
use IngestVault\Exception\RateLimitedException;
use IngestVault\Exception\ServerException;
use IngestVault\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorsTest extends TestCase
{
    private const ID = '0199b2c4-1111-7a3b-9c4d-5e6f7a8b9c01';

    private const REQUEST_ID = 'req_3f9a1c2b7d4e8f6051a2b3c4d5e6f708';

    private function raised(Response $response): ApiException
    {
        try {
            $this->client([$response], retries: 0)->events->send('order.created');
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected an ApiException.');
    }

    public function test_a_422_raises_a_validation_error_with_the_field_errors_as_received(): void
    {
        $errors = [
            'type' => ['The type must start with a lowercase letter.', 'The type may not be longer than 128 characters.'],
            'payload.items.0' => ['Example message.'],
        ];

        $e = $this->raised(self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $errors]));

        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame(422, $e->status);
        $this->assertSame('validation_failed', $e->problemCode);
        $this->assertSame('The given data was invalid.', $e->getMessage());
        $this->assertSame($errors, $e->errors);
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('distinguishableErrors')]
    public function test_an_authentication_failure_an_idempotency_conflict_and_a_too_large_payload_are_distinguishable(int $status, string $code, string $class): void
    {
        $e = $this->raised(self::problemResponse($status, $code, 'Refused.'));

        $this->assertInstanceOf($class, $e);
        $this->assertSame($status, $e->status);
        $this->assertSame($code, $e->problemCode);
        $this->assertSame('Refused.', $e->getMessage());
    }

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function distinguishableErrors(): iterable
    {
        yield 'authentication failure' => [401, 'unauthenticated', AuthenticationException::class];
        yield 'idempotency conflict' => [409, 'idempotency_key_conflict', IdempotencyConflictException::class];
        yield 'payload too large' => [413, 'payload_too_large', PayloadTooLargeException::class];
    }

    public function test_an_unknown_problem_code_raises_a_general_api_error_with_the_code(): void
    {
        $e = $this->raised(self::problemResponse(403, 'organization_suspended', 'The organization is suspended.'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertInstanceOf(IngestVaultException::class, $e);
        $this->assertSame(403, $e->status);
        $this->assertSame('organization_suspended', $e->problemCode);
        $this->assertSame('The organization is suspended.', $e->getMessage());
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('answersWithoutProblemBody')]
    public function test_answer_without_problem_body(int $status, string $class): void
    {
        $e = $this->raised(new Response($status, ['Content-Type' => 'text/html'], '<html><body>nginx</body></html>'));

        $this->assertSame($class, $e::class);
        $this->assertSame($status, $e->status);
        $this->assertNull($e->problemCode);
        $this->assertSame(sprintf('The API answered with HTTP %d.', $status), $e->getMessage());
        $this->assertNull($e->requestId);
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function answersWithoutProblemBody(): iterable
    {
        yield 'gateway error' => [502, ServerException::class];
        yield 'body too large at the proxy' => [413, PayloadTooLargeException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('errorsWithARequestId')]
    public function test_an_error_carries_the_request_id_from_the_header_and_ends_its_message_with_it(int $status, string $code, string $class): void
    {
        $e = $this->raised(self::problemResponse($status, $code, 'Refused.')->withHeader('Request-Id', self::REQUEST_ID));

        $this->assertInstanceOf($class, $e);
        $this->assertSame(self::REQUEST_ID, $e->requestId);
        $this->assertTrue(str_ends_with($e->getMessage(), ' (request ' . self::REQUEST_ID . ')'), $e->getMessage());
    }

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function errorsWithARequestId(): iterable
    {
        yield 'validation failure' => [422, 'validation_failed', ValidationException::class];
        yield 'rate limited' => [429, 'rate_limited', RateLimitedException::class];
        yield 'authentication failure' => [401, 'unauthenticated', AuthenticationException::class];
        yield 'server error' => [500, 'internal_error', ServerException::class];
    }

    public function test_an_error_without_the_header_takes_the_request_id_from_the_problem_body(): void
    {
        $e = $this->raised(self::problemResponse(500, 'internal_error', 'Something went wrong.', ['request_id' => self::REQUEST_ID]));

        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertSame(self::REQUEST_ID, $e->requestId);
        $this->assertSame('Something went wrong. (request ' . self::REQUEST_ID . ')', $e->getMessage());
    }

    public function test_an_unreadable_success_body_raises_a_general_api_error(): void
    {
        $e = $this->raised(new Response(200, ['Content-Type' => 'text/html'], '<html>Welcome</html>'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(200, $e->status);
        $this->assertNull($e->problemCode);
    }

    public function test_an_error_with_both_the_header_and_the_body_member_takes_the_request_id_from_the_header(): void
    {
        $e = $this->raised(self::problemResponse(500, 'internal_error', 'Something went wrong.', ['request_id' => 'req_b'])->withHeader('Request-Id', 'req_a'));

        $this->assertSame('req_a', $e->requestId);
        $this->assertSame('Something went wrong. (request req_a)', $e->getMessage());
    }

    public function test_an_unreadable_success_body_carries_the_request_id_of_the_answer(): void
    {
        $e = $this->raised(new Response(200, ['Content-Type' => 'text/html', 'Request-Id' => self::REQUEST_ID], '<html>Welcome</html>'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(self::REQUEST_ID, $e->requestId);
        $this->assertTrue(str_ends_with($e->getMessage(), ' (request ' . self::REQUEST_ID . ')'), $e->getMessage());
    }

    public function test_a_retry_after_of_zero_is_read_as_zero(): void
    {
        $e = $this->raised(self::problemResponse(429, 'rate_limited', 'Slow down.', headers: ['Retry-After' => '0']));

        $this->assertInstanceOf(RateLimitedException::class, $e);
        $this->assertSame(0, $e->retryAfter);
    }

    public function test_a_missing_retry_after_is_read_as_null(): void
    {
        $e = $this->raised(self::problemResponse(429, 'rate_limited', 'Slow down.'));

        $this->assertInstanceOf(RateLimitedException::class, $e);
        $this->assertNull($e->retryAfter);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    private function raisedBy(\Closure $call, Response $response): ApiException
    {
        try {
            $call($this->client([$response], retries: 0));
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected an ApiException.');
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed}>
     */
    public static function operations(): iterable
    {
        yield from self::operationsOnAnExistingResource();
        yield 'list endpoints' => [static fn(Client $client): mixed => $client->endpoints->list()];
        yield 'create an endpoint' => [static fn(Client $client): mixed => $client->endpoints->create('https://hooks.example.test/orders')];
        yield 'create a subscription' => [static fn(Client $client): mixed => $client->subscriptions->create(self::ID, ['order.created'])];
        yield 'list event types' => [static fn(Client $client): mixed => $client->eventTypes->list()];
        yield 'create an event type' => [static fn(Client $client): mixed => $client->eventTypes->create('order.created')];
        yield 'list events' => [static fn(Client $client): mixed => $client->events->list()];
        yield 'replay an event' => [static fn(Client $client): mixed => $client->events->replay(self::ID)];
        yield 'list deliveries' => [static fn(Client $client): mixed => $client->deliveries->list()];
        yield 'replay a delivery' => [static fn(Client $client): mixed => $client->deliveries->replay(self::ID)];
        yield 'read the current organization' => [static fn(Client $client): mixed => $client->organization->current()];
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed}>
     */
    public static function operationsOnAnExistingResource(): iterable
    {
        yield 'get an endpoint' => [static fn(Client $client): mixed => $client->endpoints->get(self::ID)];
        yield 'update an endpoint' => [static fn(Client $client): mixed => $client->endpoints->update(self::ID, ['enabled' => false])];
        yield 'delete an endpoint' => [static fn(Client $client): mixed => $client->endpoints->delete(self::ID)];
        yield 'list signing secrets' => [static fn(Client $client): mixed => $client->signingSecrets->list(self::ID)];
        yield 'rotate a signing secret' => [static fn(Client $client): mixed => $client->signingSecrets->rotate(self::ID)];
        yield 'list subscriptions' => [static fn(Client $client): mixed => $client->subscriptions->list(self::ID)];
        yield 'get a subscription' => [static fn(Client $client): mixed => $client->subscriptions->get(self::ID, 's1')];
        yield 'update a subscription' => [static fn(Client $client): mixed => $client->subscriptions->update(self::ID, 's1', ['description' => null])];
        yield 'delete a subscription' => [static fn(Client $client): mixed => $client->subscriptions->delete(self::ID, 's1')];
        yield 'get an event type' => [static fn(Client $client): mixed => $client->eventTypes->get(self::ID)];
        yield 'update an event type' => [static fn(Client $client): mixed => $client->eventTypes->update(self::ID, ['description' => 'Placed.'])];
        yield 'archive an event type' => [static fn(Client $client): mixed => $client->eventTypes->archive(self::ID)];
        yield 'unarchive an event type' => [static fn(Client $client): mixed => $client->eventTypes->unarchive(self::ID)];
        yield 'get an event' => [static fn(Client $client): mixed => $client->events->get(self::ID)];
        yield 'get a delivery' => [static fn(Client $client): mixed => $client->deliveries->get(self::ID)];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('operationsOnAnExistingResource')]
    public function test_a_404_on_a_missing_resource_raises_a_general_api_error(\Closure $call): void
    {
        $e = $this->raisedBy($call, self::problemResponse(404, 'not_found', 'The resource does not exist.'));

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(404, $e->status);
        $this->assertSame('not_found', $e->problemCode);
        $this->assertSame('The resource does not exist.', $e->getMessage());
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('writesWithABody')]
    public function test_a_422_on_a_create_or_update_raises_a_validation_error_with_the_field_errors_as_received(\Closure $call): void
    {
        $errors = ['url' => ['The url must use https.'], 'filter.0' => ['The filter.0 field is invalid.']];

        $e = $this->raisedBy($call, self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $errors]));

        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame(422, $e->status);
        $this->assertSame($errors, $e->errors);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed}>
     */
    public static function writesWithABody(): iterable
    {
        yield 'create an endpoint' => [static fn(Client $client): mixed => $client->endpoints->create('http://hooks.example.test')];
        yield 'update an endpoint' => [static fn(Client $client): mixed => $client->endpoints->update(self::ID, ['url' => 'ftp://x'])];
        yield 'create a subscription' => [static fn(Client $client): mixed => $client->subscriptions->create(self::ID, ['Order'])];
        yield 'update a subscription' => [static fn(Client $client): mixed => $client->subscriptions->update(self::ID, 's1', ['filter' => ['Order']])];
        yield 'create an event type' => [static fn(Client $client): mixed => $client->eventTypes->create('Order')];
        yield 'update an event type' => [static fn(Client $client): mixed => $client->eventTypes->update(self::ID, [])];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('filteredLists')]
    public function test_a_422_on_a_list_with_a_bad_filter_raises_a_validation_error_with_the_field_errors_as_received(\Closure $call, string $field): void
    {
        $errors = [$field => ['The ' . $field . ' field is invalid.']];

        $e = $this->raisedBy($call, self::problemResponse(422, 'validation_failed', 'The given data was invalid.', ['errors' => $errors]));

        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame(422, $e->status);
        $this->assertSame('validation_failed', $e->problemCode);
        $this->assertSame($errors, $e->errors);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, string}>
     */
    public static function filteredLists(): iterable
    {
        yield 'events by a malformed type' => [static fn(Client $client): mixed => $client->events->list(type: 'Order'), 'type'];
        yield 'deliveries by an unknown status' => [static fn(Client $client): mixed => $client->deliveries->list(status: 'lost'), 'status'];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('operations')]
    public function test_a_401_on_any_operation_raises_an_authentication_error(\Closure $call): void
    {
        $e = $this->raisedBy($call, self::problemResponse(401, 'unauthenticated', 'The API key is invalid.'));

        $this->assertInstanceOf(AuthenticationException::class, $e);
        $this->assertSame(401, $e->status);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('operations')]
    public function test_a_500_on_any_operation_raises_a_server_error(\Closure $call): void
    {
        $e = $this->raisedBy($call, self::problemResponse(500, 'internal_error', 'Something went wrong.'));

        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertSame(500, $e->status);
        $this->assertSame('internal_error', $e->problemCode);
        $this->assertCount(1, $this->history);
    }

    public function test_creating_an_event_type_whose_name_is_archived_raises_a_general_api_error_with_the_code(): void
    {
        $e = $this->raisedBy(
            static fn(Client $client): mixed => $client->eventTypes->create('order.created'),
            self::problemResponse(409, 'archived_name_conflict', 'The name belongs to an archived event type.'),
        );

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(409, $e->status);
        $this->assertSame('archived_name_conflict', $e->problemCode);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('unreadableSuccessAnswers')]
    public function test_an_unreadable_success_body_on_a_read_raises_a_general_api_error(\Closure $call, Response $response, string $message): void
    {
        $e = $this->raisedBy($call, $response);

        $this->assertSame(ApiException::class, $e::class);
        $this->assertSame(200, $e->status);
        $this->assertNull($e->problemCode);
        $this->assertSame($message, $e->getMessage());
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, Response, string}>
     */
    public static function unreadableSuccessAnswers(): iterable
    {
        $html = new Response(200, ['Content-Type' => 'text/html'], '<html>Welcome</html>');
        $get = static fn(Client $client): mixed => $client->endpoints->get(self::ID);
        $list = static fn(Client $client): mixed => $client->endpoints->list();
        $secrets = static fn(Client $client): mixed => $client->signingSecrets->list(self::ID);
        $withoutId = static function (array $item): array {
            unset($item['id']);

            return $item;
        };
        $json = static fn(mixed $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));

        yield 'html on get' => [$get, $html, 'The API answered with HTTP 200 but the body is not an endpoint.'];
        yield 'object without an id on get' => [$get, $json($withoutId(self::endpoint())), 'The API answered with HTTP 200 but the body is not an endpoint.'];
        yield 'html on list' => [$list, clone $html, 'The API answered with HTTP 200 but the body is not a page of endpoints.'];
        yield 'item without an id on list' => [$list, $json(['data' => [$withoutId(self::endpoint())], 'has_more' => false, 'next_cursor' => null]), 'The API answered with HTTP 200 but the body is not a page of endpoints.'];
        yield 'page without has_more on list' => [$list, $json(['data' => [], 'next_cursor' => null]), 'The API answered with HTTP 200 but the body is not a page of endpoints.'];
        yield 'html on signing secrets' => [$secrets, clone $html, "The API answered with HTTP 200 but the body is not the endpoint's signing secrets."];
        $event = static fn(Client $client): mixed => $client->events->get(self::ID);
        $delivery = static fn(Client $client): mixed => $client->deliveries->get(self::ID);
        $organization = static fn(Client $client): mixed => $client->organization->current();
        $withoutPayload = self::eventSummary(['payload_state' => 'available', 'idempotent' => false]);
        $withAnUnreadableAttempt = self::deliveryWithAttempts(['attempts' => [$withoutId(self::attempt())]]);

        yield 'html on get event' => [$event, clone $html, 'The API answered with HTTP 200 but the body is not an event.'];
        yield 'event without a payload on get event' => [$event, $json($withoutPayload), 'The API answered with HTTP 200 but the body is not an event.'];
        yield 'event with an unreadable initiator on get event' => [$event, $json($withoutPayload + ['payload' => null, 'initiator' => ['id' => 'k1']]), 'The API answered with HTTP 200 but the body is not an event.'];
        yield 'html on get delivery' => [$delivery, clone $html, 'The API answered with HTTP 200 but the body is not a delivery.'];
        yield 'delivery with an unreadable attempt on get delivery' => [$delivery, $json($withAnUnreadableAttempt), 'The API answered with HTTP 200 but the body is not a delivery.'];
        yield 'delivery without next_attempt_at on get delivery' => [$delivery, $json(array_diff_key(self::deliveryWithAttempts(), ['next_attempt_at' => true])), 'The API answered with HTTP 200 but the body is not a delivery.'];
        yield 'html on the organization' => [$organization, clone $html, 'The API answered with HTTP 200 but the body is not an organization.'];
        yield 'organization without an id' => [$organization, $json($withoutId(self::organization())), 'The API answered with HTTP 200 but the body is not an organization.'];
        yield 'item without an id on signing secrets' => [$secrets, $json(['data' => [$withoutId(self::signingSecret())]]), "The API answered with HTTP 200 but the body is not the endpoint's signing secrets."];
    }

    public function test_a_204_with_a_body_on_delete_is_still_a_success(): void
    {
        $this->client([new Response(204, ['Content-Type' => 'application/json'], '{"unexpected":true}')])->endpoints->delete(self::ID);

        $this->assertCount(1, $this->history);
    }
}
