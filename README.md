# IngestVault PHP

The PHP client for the IngestVault API. It sends events to IngestVault, which stores them and delivers them to your endpoints as webhooks, configures the endpoints, subscriptions and event types that decide where they go, and reads and replays the events and deliveries that resulted. It needs PHP 8.2 or newer and works with any framework.

## Installation

```bash
composer require ingestvault/ingestvault-php
```

## Configuration

```php
use IngestVault\Client;

$client = new Client('ivk_...');
```

The API key is the only required value. The others have defaults:

```php
$client = new Client(
    apiKey: 'ivk_...',
    baseUrl: 'https://api.ingestvault.com/v1',
    timeout: 10.0, // seconds, per try
    retries: 2,    // 0 disables retries
);
```

An API key that is empty, or that contains spaces, control characters or anything outside plain ASCII (such as a trailing newline read from a file), throws an `InvalidArgumentException` before any request is made.

`Client::VERSION` is the version of this client (`'0.5.0'`); every request sends it in the `User-Agent` header as `ingestvault-php/0.5.0`.

## Sending an event

```php
$event = $client->events->send('order.created', [
    'order' => 1042,
    'total' => 99.5,
]);

$event->id;                     // '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d'
$event->type;                   // 'order.created'
$event->typeRegistrationStatus; // 'registered', 'unregistered' or 'archived'
$event->receivedAt;             // DateTimeImmutable
$event->idempotent;             // true when this answer repeats an earlier send
$event->requestId;              // 'req_3f9a...', the API's id for this request
```

The payload can be any value that encodes as JSON, or left out:

```php
use IngestVault\Payload;
use IngestVault\RawJson;

$client->events->send('order.created');                // no payload
$client->events->send('order.created', Payload::None); // no payload, said explicitly
$client->events->send('order.created', null);          // the payload null
$client->events->send('order.created', new RawJson('{"ratio": 1.0, "big": 123456789012345678901234567890}'));
```

Leaving the payload out, or passing `Payload::None`, sends an event without one, which is not the same as a payload of `null`. A PHP value is encoded by the client, and `1.0` stays `1.0`. When the exact JSON text matters, pass it as `RawJson`: it is sent byte for byte, and text that is not valid JSON throws an `InvalidArgumentException` before anything is sent.

`Payload::None` is the default of the payload argument, so a function of your own around `send()` should default to it too and pass it on unchanged:

```php
use IngestVault\Client;
use IngestVault\Event;
use IngestVault\Payload;

function sendEvent(Client $client, string $type, mixed $payload = Payload::None, ?string $key = null): Event
{
    return $client->events->send($type, $payload, $key);
}

sendEvent($client, 'order.created');       // no payload
sendEvent($client, 'order.created', null); // the payload null
```

Defaulting such a parameter to `null` instead would quietly turn every event sent without a payload into one whose payload is `null`. The same goes for a `PreparedEvent`: hand it `Payload::None`, never `null`, when there is no payload.

An event whose type is not registered, or is archived, is still accepted: `typeRegistrationStatus` reports it, and no exception is thrown. The API checks the type name and the payload size; the client sends what it is given.

## Idempotency and retries

Every send carries an `Idempotency-Key`. Pass your own as the third argument to make a send safe to repeat; otherwise the client generates one per send.

```php
$event = $client->events->send('order.created', ['order' => 1042], 'order-created-1042');
$event = $client->events->send('order.cancelled', Payload::None, 'order-cancelled-1042'); // a key and no payload
```

Sending the same type and payload again with the same key within 24 hours returns the original event, with `idempotent` set to `true`. The API compares the payload byte for byte, so a second send under the same key whose payload was built differently, with the members in another order, an integer that became a float or raw JSON with other whitespace, throws an `IdempotencyConflictException`. A retry of the same send is always safe: its body is fixed once and sent unchanged.

Network failures and 5xx answers are retried, up to `retries` times, after a pause of 250 ms before the first retry and 500 ms before the second. Every retry sends the same key, so a retry never creates a second event. Nothing else is retried. A 429 is thrown straight away with the wait time from `Retry-After`; the client never sleeps on it, so you decide when to try again.

Replays carry a key too, generated unless you pass one, and are retried the same way, so a retried replay never creates a second event. Replays and sends share one key space: a key you used for a send must not be reused for a replay, nor the other way round.

Creating an endpoint, a subscription or an event type and rotating a signing secret are never retried: they carry no idempotency key, and a request whose answer was lost may still have succeeded. Every other operation is retried like a send, up to `retries` times. A retried delete whose first try went through answers 404; treat a 404 on delete as deleted if that suits you.

A send can be prepared now and sent later, for example from a queued job. The idempotency key and the request body are fixed when the event is prepared, and a `PreparedEvent` can be serialized:

```php
use IngestVault\PreparedEvent;

$prepared = new PreparedEvent('order.created', ['order' => 1042]);
$prepared = new PreparedEvent('order.cancelled', idempotencyKey: 'order-cancelled-1042'); // no payload, your own key

$prepared->idempotencyKey; // the key, generated when you pass none

// later, possibly in another process, possibly more than once
$event = $client->events->sendPrepared($prepared);
```

## Endpoints

```php
$endpoint = $client->endpoints->create('https://shop.example/webhooks', description: 'Orders');
$paused = $client->endpoints->create('https://shop.example/webhooks', enabled: false); // starts disabled

$endpoint->id;          // '0199b2c4-...'
$endpoint->url;         // 'https://shop.example/webhooks'
$endpoint->description; // 'Orders', or null
$endpoint->enabled;     // true
$endpoint->createdAt;   // DateTimeImmutable, as is updatedAt

$client->endpoints->get($endpoint->id);
$client->endpoints->update($endpoint->id, ['enabled' => false, 'description' => null]);
$client->endpoints->delete($endpoint->id);
```

An update changes only the members it is given: `null` clears the description, and a member that is left out keeps its value.

Every object returned here and in the sections that follow carries the `requestId` of the answer it came from.

## Signing secrets

```php
foreach ($client->signingSecrets->list($endpoint->id) as $secret) {
    $secret->secret();   // 'whsec_...'
    $secret->expiresAt;  // null for the current secret, a DateTimeImmutable for the previous one
}

$secret = $client->signingSecrets->rotate($endpoint->id);
```

The value is read through `secret()`, so it never shows up when the object is dumped or logged.

## Subscriptions

```php
$subscription = $client->subscriptions->create($endpoint->id, ['order.created', 'order.paid'], 'Orders');

$subscription->filter;      // ['order.created', 'order.paid']
$subscription->description; // 'Orders', or null

$client->subscriptions->get($endpoint->id, $subscription->id);
$client->subscriptions->list($endpoint->id); // one page, see Lists

foreach ($client->subscriptions->all($endpoint->id) as $existing) {
    $existing->filter;
}

$client->subscriptions->update($endpoint->id, $subscription->id, ['filter' => []]);
$client->subscriptions->delete($endpoint->id, $subscription->id);
```

An empty filter delivers every event to the endpoint. An update replaces the whole filter.

## Event types

```php
$type = $client->eventTypes->create('order.created', 'An order was placed.');

$type->name;     // 'order.created'
$type->archived; // false

$client->eventTypes->get($type->id);
$client->eventTypes->list(); // one page, see Lists

foreach ($client->eventTypes->all() as $existing) {
    $existing->name;
}

$client->eventTypes->update($type->id, ['description' => 'An order was placed at checkout.']);
$client->eventTypes->archive($type->id);
$client->eventTypes->unarchive($type->id);
```

## Events

```php
$page = $client->events->list(type: 'order.created', typeRegistrationStatus: 'unregistered');
$page = $client->events->list(pageSize: 50, receivedAfter: '2026-10-05T00:00:00Z', receivedBefore: '2026-10-05T23:59:59Z');

foreach ($client->events->all(receivedAfter: '2026-10-05T12:00:00Z', replay: false) as $summary) {
    $summary->id;
    $summary->type;
    $summary->receivedAt; // DateTimeImmutable
    $summary->replay;     // true for an event created by a replay, with rootEventId set
}

$event = $client->events->get($summary->id);

$event->payload;      // ['order' => 1042, 'total' => 99.5]
$event->payloadJson;  // '{"order":1042,"total":99.5}'
$event->payloadState; // 'available', 'expired' or 'none'
```

List rows carry no payload; `get()` returns the full event. The payload comes in two forms: `payload` is decoded with PHP arrays, and `payloadJson` is the JSON text exactly as the API sent it, for when the difference between `{}` and `[]`, the formatting of a number such as `1.0`, or a large integer matters. The client writes a PHP float with its fraction, so a `1.0` you send reads back as `1.0` in `payloadJson` and as a PHP float in `payload`; every other float the API answers with carries its fraction as well. `payloadState` tells a payload of `null` from no payload and from an expired one, see [Payload states](#payload-states).

A replay creates a new event with the same type and payload, delivered to the endpoints whose subscriptions match it now:

```php
$replayed = $client->events->replay($event->id);
$replayed = $client->events->replay($event->id, 'replay-order-1042');

$replayed->replay;      // true
$replayed->rootEventId; // the original event's id
$replayed->idempotent;  // true when this answer repeats an earlier replay with the same key
```

On a replayed event `initiator` tells who started the replay; it is `null` on original events, and on replays made before the API recorded it.

```php
$replayed->initiator->type; // 'user', 'api_key' or 'support'
$replayed->initiator->id;   // the user's or the API key's id, null for 'support'
$replayed->initiator->name; // the API key's name, 'api_key' only
$replayed->initiator->hint; // the API key's hint, 'ivk_...' and its last four characters, 'api_key' only
```

The replay keeps the original's payload exactly: an event without a payload replays without one, and a payload of `null` stays `null`.

## Payload states

An event's `payloadState` is one of three values:

- `'available'`: the event was sent with a payload and is within the payload retention of your plan. It is the only state in which `payload` and `payloadJson` are set; a payload of `null` reads as `payload` `null` and `payloadJson` `'null'`.
- `'expired'`: the event is past the retention of your plan. Both forms are `null`, and replaying the event, or a delivery of it, throws an `ApiException` with the `problemCode` `'payload_expired'` (409).
- `'none'`: the event was sent without a payload. Both forms are `null`; the state never becomes `'expired'`, so the event can always be replayed.

The state is how to tell a null payload from no payload, and an expired one from either. It is read when the event is: a send or replay answers `'available'` or `'none'`, and a later `get()`, or a repeated send or replay under the same key, answers `'expired'` once the retention has passed.

A delivery read with `get()` carries the same state for its event in `payloadState`; delivery list rows carry none. Its attempts keep their response bodies for as long as the event's payload would be kept: `responseBody` is `null` once the event is past the retention, under `'expired'` and under `'none'` alike.

## Deliveries

```php
foreach ($client->deliveries->all(status: 'failed') as $delivery) {
    $delivery->status;        // 'pending', 'retrying', 'succeeded' or 'failed'
    $delivery->attemptCount;
    $delivery->nextAttemptAt; // DateTimeImmutable while retrying, otherwise null
    $delivery->eventId;
    $delivery->eventType;
    $delivery->endpointId;
    $delivery->endpointUrl;
    $delivery->createdAt;     // DateTimeImmutable, as is updatedAt
}

$page = $client->deliveries->list(
    endpointId: $endpoint->id,
    eventId: $event->id,
    eventType: 'order.created',
    status: 'succeeded',
    createdAfter: '2026-10-05T00:00:00Z',
    createdBefore: '2026-10-05T23:59:59Z',
);

$delivery = $client->deliveries->get($delivery->id);

$delivery->payloadState; // 'available', 'expired' or 'none'

foreach ($delivery->attempts as $attempt) {
    $attempt->outcome;             // 'succeeded' or 'failed'
    $attempt->httpStatus;          // 503, or null when no answer arrived
    $attempt->errorClassification; // 'timeout' and the like, or null when an answer arrived
    $attempt->durationMs;
    $attempt->responseBody;        // null once the event is past its retention, whatever the payload state
    $attempt->attemptedAt;         // DateTimeImmutable
}

$replayed = $client->deliveries->replay($delivery->id);
```

A delivery replay creates a new event too, delivered to that delivery's endpoint only. It is refused with a `ValidationException` while the delivery is still pending or retrying, or when the endpoint is disabled.

Every delivery returned, and the event a replay returns, carries the `requestId` of the answer it came from.

## Organization

```php
$organization = $client->organization->current();

$organization->id;
$organization->name;
$organization->notificationEmail;
$organization->createdAt;       // DateTimeImmutable
$organization->payloadsVisible; // whether IngestVault support may view your payloads
```

`current()` reads the organization the API key belongs to.

## Lists

`list()` returns one page, and its `nextCursor` asks for the next one. `all()` goes through every page, requesting each one only when the loop reaches it:

```php
$page = $client->endpoints->list(pageSize: 50);
$page->data;       // Endpoint objects
$page->hasMore;    // true when another page follows
$page->nextCursor; // null on the last page
$next = $client->endpoints->list(pageSize: 50, cursor: $page->nextCursor);

foreach ($client->endpoints->all() as $endpoint) {
    // ...
}
```

Subscriptions, event types, events and deliveries are listed the same way; the signing secrets of an endpoint come as a plain array. Filters on events and deliveries are sent as given on every page, and timestamps are written as `2026-10-05T12:00:00Z`; the API answers a value it cannot use with a `ValidationException`.

Each item carries the `requestId` of the page it came from, and `$page->requestId` is the page's own; listed signing secrets carry the `requestId` of their answer.

## Handling errors

A request that fails throws an exception that extends `IngestVault\Exception\IngestVaultException`: a `NetworkException` when no answer arrived, or an `ApiException` or one of its subclasses when the answer is an error or cannot be read, with the HTTP `status`, the API's `problemCode` and its message. Every `ApiException` also carries `requestId`, the API's id for the failed request, and its message ends with that id, as in `The given data was invalid. (request req_3f9a...)`. After retries, it is the id of the last try.

```php
use IngestVault\Exception\ApiException;
use IngestVault\Exception\AuthenticationException;
use IngestVault\Exception\IdempotencyConflictException;
use IngestVault\Exception\NetworkException;
use IngestVault\Exception\PayloadTooLargeException;
use IngestVault\Exception\QuotaExceededException;
use IngestVault\Exception\RateLimitedException;
use IngestVault\Exception\ServerException;
use IngestVault\Exception\ValidationException;

try {
    $client->events->send('order.created', $payload);
} catch (ValidationException $e) {
    $e->errors;      // ['type' => ['The name may contain only lowercase letters, ...']]
} catch (RateLimitedException | QuotaExceededException $e) {
    $e->retryAfter;  // seconds to wait
} catch (AuthenticationException $e) {
    // 401: the API key is missing, wrong or revoked
} catch (IdempotencyConflictException $e) {
    // 409: the key was already used for a different request
} catch (PayloadTooLargeException $e) {
    // 413: the event is larger than 1 MB
} catch (ServerException | NetworkException $e) {
    // 5xx or no answer, after the retries ran out
} catch (ApiException $e) {
    $e->status;      // 403
    $e->problemCode; // a code this client does not know yet, or another
                     // code such as 'archived_name_conflict' (409, creating an
                     // event type whose name an archived type holds) or
                     // 'payload_expired' (409, replaying an expired event)
    $e->getMessage();
    $e->requestId;   // 'req_3f9a...': quote it to support to trace the request
}
```

`problemCode` is `null` when an answer carries no problem document, for example an error page from a proxy. `requestId` is `null` for the same answers, and the message then carries no id.

A call the client cannot turn into a request fails with PHP's own exceptions before anything is sent: a payload that cannot be encoded as JSON throws a `JsonException`, and raw JSON text that is not valid JSON or a value that cannot be sent as a header, such as an idempotency key with a line break, throws an `InvalidArgumentException`.

## Testing

The client sends its requests through Guzzle. Pass a Guzzle client over a `MockHandler` and your tests run without a network:

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use IngestVault\Client;

$mock = new MockHandler([
    new Response(201, [], json_encode([
        'id' => '0199b2c4-7d1e-7a3b-9c4d-5e6f7a8b9c0d',
        'type' => 'order.created',
        'type_registration_status' => 'registered',
        'payload' => ['order' => 1042],
        'payload_state' => 'available',
        'received_at' => '2026-10-05T12:00:00Z',
        'idempotent' => false,
        'replay' => false,
    ])),
]);

$client = new Client('ivk_test', httpClient: new GuzzleClient(['handler' => $mock]));
```

`$mock->getLastRequest()` returns the request that was sent.

## Verifying deliveries

IngestVault delivers events following the [Standard Webhooks](https://www.standardwebhooks.com) specification, and signing secrets are in its format. Verify deliveries with the official Standard Webhooks library for your language. Two headers are specific to IngestVault:

- `webhook-id` is the id of the original event. It stays the same across delivery retries and replays, so use it to skip deliveries you have already handled.
- `ingestvault-event-type` carries the event type.

## License

MIT. See [LICENSE](LICENSE).
