<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class EventSummary
{
    public function __construct(
        public string $id,
        public string $type,
        public string $typeRegistrationStatus,
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
        $receivedAt = Timestamp::parse($item['received_at'] ?? null);
        $replay = $item['replay'] ?? null;
        $rootEventId = $item['root_event_id'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($typeRegistrationStatus) || $receivedAt === null
            || ! is_bool($replay) || ! (is_string($rootEventId) || $rootEventId === null)) {
            return null;
        }

        return new self($id, $type, $typeRegistrationStatus, $receivedAt, $replay, $rootEventId, $requestId);
    }
}
