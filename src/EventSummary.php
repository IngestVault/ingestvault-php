<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class EventSummary
{
    public function __construct(
        public string $id,
        public ?string $type,
        public ?string $typeRegistrationStatus,
        public string $origin,
        public ?SourceReference $source,
        public ?string $providerEventType,
        public \DateTimeImmutable $receivedAt,
        public bool $replay,
        public ?string $rootEventId,
        public ?string $requestId,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item, ?string $requestId): ?self
    {
        $id = $item['id'] ?? null;
        $type = $item['type'] ?? null;
        $typeRegistrationStatus = $item['type_registration_status'] ?? null;
        $origin = $item['origin'] ?? null;
        $source = $item['source'] ?? null;
        $providerEventType = $item['provider_event_type'] ?? null;
        $receivedAt = Timestamp::parse($item['received_at'] ?? null);
        $replay = $item['replay'] ?? null;
        $rootEventId = $item['root_event_id'] ?? null;

        if (! is_string($id) || ! array_key_exists('type', $item) || ! (is_string($type) || $type === null)
            || ! array_key_exists('type_registration_status', $item) || ! (is_string($typeRegistrationStatus) || $typeRegistrationStatus === null)
            || ! is_string($origin) || ! array_key_exists('source', $item)
            || ! array_key_exists('provider_event_type', $item) || ! (is_string($providerEventType) || $providerEventType === null)
            || $receivedAt === null || ! is_bool($replay) || ! (is_string($rootEventId) || $rootEventId === null)) {
            return null;
        }

        if ($source !== null) {
            $source = is_array($source) ? SourceReference::fromArray($source) : null;
            if ($source === null) {
                return null;
            }
        }

        return new self($id, $type, $typeRegistrationStatus, $origin, $source, $providerEventType, $receivedAt, $replay, $rootEventId, $requestId);
    }
}
