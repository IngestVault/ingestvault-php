<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Event
{
    /**
     * @param mixed $payload The payload decoded as json_decode() with associative arrays does it; null when expired or none.
     * @param ?string $payloadJson The payload exactly as the API sent it, as JSON text; null when expired or none, 'null' for a null payload.
     * @param string $payloadState 'available', 'expired' or 'none' (sent without a payload); tells a null payload from no payload.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $typeRegistrationStatus,
        public mixed $payload,
        public ?string $payloadJson,
        public string $payloadState,
        public \DateTimeImmutable $receivedAt,
        public bool $idempotent,
        public bool $replay,
        public ?string $rootEventId,
        public ?Initiator $initiator,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item, ?string $payloadJson = null): ?self
    {
        $id = $item['id'] ?? null;
        $type = $item['type'] ?? null;
        $typeRegistrationStatus = $item['type_registration_status'] ?? null;
        $payloadState = $item['payload_state'] ?? null;
        $receivedAt = Timestamp::parse($item['received_at'] ?? null);
        $idempotent = $item['idempotent'] ?? null;
        $replay = $item['replay'] ?? null;
        $rootEventId = $item['root_event_id'] ?? null;
        $initiator = $item['initiator'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($typeRegistrationStatus) || ! is_string($payloadState)
            || $receivedAt === null || ! is_bool($idempotent) || ! is_bool($replay) || ! (is_string($rootEventId) || $rootEventId === null)) {
            return null;
        }

        if ($initiator !== null) {
            $initiator = is_array($initiator) ? Initiator::fromArray($initiator) : null;
            if ($initiator === null) {
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

        return new self($id, $type, $typeRegistrationStatus, $payload, $payloadJson, $payloadState, $receivedAt, $idempotent, $replay, $rootEventId, $initiator);
    }
}
