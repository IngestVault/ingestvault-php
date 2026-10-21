<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Event
{
    /**
     * @param string $origin 'api' for an event sent to the API (and its replays), 'source' for one received on a source URL.
     * @param ?string $body The received request's bytes, decoded when they were sent as base64; null on 'api' events and once expired.
     * @param ?string $bodyAsSent The body exactly as the API wrote it, before any base64 decoding.
     * @param ?list<array{0: string, 1: string}> $headers The received request's headers as name/value pairs, in order with repeats kept.
     * @param mixed $payload The payload decoded as json_decode() with associative arrays does it; null when expired or none.
     * @param ?string $payloadJson The payload exactly as the API sent it, as JSON text; null when expired or none, 'null' for a null payload.
     * @param string $payloadState 'available', 'expired' or 'none' (sent without a payload); tells a null payload from no payload.
     */
    public function __construct(
        public string $id,
        public ?string $type,
        public ?string $typeRegistrationStatus,
        public string $origin,
        public ?SourceReference $source,
        public ?string $providerEventType,
        public ?string $body,
        public ?string $bodyAsSent,
        public ?string $bodyEncoding,
        public ?string $contentType,
        public ?array $headers,
        public ?string $queryString,
        public mixed $payload,
        public ?string $payloadJson,
        public string $payloadState,
        public \DateTimeImmutable $receivedAt,
        public bool $idempotent,
        public bool $replay,
        public ?string $rootEventId,
        public ?Initiator $initiator,
        public ?string $requestId,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item, ?string $payloadJson, ?string $requestId): ?self
    {
        $id = $item['id'] ?? null;
        $type = $item['type'] ?? null;
        $typeRegistrationStatus = $item['type_registration_status'] ?? null;
        $origin = $item['origin'] ?? null;
        $source = $item['source'] ?? null;
        $providerEventType = $item['provider_event_type'] ?? null;
        $bodyAsSent = $item['body'] ?? null;
        $bodyEncoding = $item['body_encoding'] ?? null;
        $contentType = $item['content_type'] ?? null;
        $headers = $item['headers'] ?? null;
        $queryString = $item['query_string'] ?? null;
        $payloadState = $item['payload_state'] ?? null;
        $receivedAt = Timestamp::parse($item['received_at'] ?? null);
        $idempotent = $item['idempotent'] ?? null;
        $replay = $item['replay'] ?? null;
        $rootEventId = $item['root_event_id'] ?? null;
        $initiator = $item['initiator'] ?? null;

        if (! is_string($id) || ! array_key_exists('type', $item) || ! (is_string($type) || $type === null)
            || ! array_key_exists('type_registration_status', $item) || ! (is_string($typeRegistrationStatus) || $typeRegistrationStatus === null)
            || ! is_string($origin) || ! array_key_exists('source', $item)
            || ! array_key_exists('provider_event_type', $item) || ! (is_string($providerEventType) || $providerEventType === null)
            || ! is_string($payloadState) || $receivedAt === null || ! is_bool($idempotent) || ! is_bool($replay) || ! (is_string($rootEventId) || $rootEventId === null)) {
            return null;
        }

        if ($source !== null) {
            $source = is_array($source) ? SourceReference::fromArray($source) : null;
            if ($source === null) {
                return null;
            }
        }

        if ($initiator !== null) {
            $initiator = is_array($initiator) ? Initiator::fromArray($initiator) : null;
            if ($initiator === null) {
                return null;
            }
        }

        foreach (['body', 'body_encoding', 'content_type', 'headers', 'query_string'] as $key) {
            if (! array_key_exists($key, $item)) {
                return null;
            }
        }

        if (! (is_string($bodyAsSent) || $bodyAsSent === null) || ! (is_string($bodyEncoding) || $bodyEncoding === null)
            || ! (is_string($contentType) || $contentType === null) || ! (is_string($queryString) || $queryString === null)) {
            return null;
        }

        if ($headers !== null) {
            $headers = self::headers($headers);
            if ($headers === null) {
                return null;
            }
        }

        $body = $bodyAsSent;
        if ($bodyEncoding === 'base64' && $bodyAsSent !== null) {
            $body = base64_decode($bodyAsSent, true);
            if ($body === false) {
                return null;
            }
        }

        // An expired payload and a missing one are null in the body, which must not read as a stored null.
        if ($payloadState === 'expired' || $payloadState === 'none') {
            $payload = null;
            $payloadJson = null;
        } elseif (array_key_exists('payload', $item) && $payloadJson !== null) {
            $payload = $item['payload'];
        } else {
            return null;
        }

        // A received event never has a payload, whatever its state: its request is in the body members.
        if ($origin === 'source') {
            $payload = null;
            $payloadJson = null;
        }

        return new self(
            $id,
            $type,
            $typeRegistrationStatus,
            $origin,
            $source,
            $providerEventType,
            $body,
            $bodyAsSent,
            $bodyEncoding,
            $contentType,
            $headers,
            $queryString,
            $payload,
            $payloadJson,
            $payloadState,
            $receivedAt,
            $idempotent,
            $replay,
            $rootEventId,
            $initiator,
            $requestId,
        );
    }

    /**
     * @return ?list<array{0: string, 1: string}>
     */
    private static function headers(mixed $headers): ?array
    {
        if (! is_array($headers) || ! array_is_list($headers)) {
            return null;
        }

        $pairs = [];
        foreach ($headers as $header) {
            if (! is_array($header) || ! array_is_list($header) || count($header) !== 2 || ! is_string($header[0]) || ! is_string($header[1])) {
                return null;
            }
            $pairs[] = [$header[0], $header[1]];
        }

        return $pairs;
    }
}
