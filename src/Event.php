<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Event
{
    public function __construct(
        public string $id,
        public string $type,
        public string $typeRegistrationStatus,
        public \DateTimeImmutable $receivedAt,
        public bool $idempotent,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $id = $item['id'] ?? null;
        $type = $item['type'] ?? null;
        $typeRegistrationStatus = $item['type_registration_status'] ?? null;
        $receivedAt = Timestamp::parse($item['received_at'] ?? null);
        $idempotent = $item['idempotent'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($typeRegistrationStatus) || $receivedAt === null || ! is_bool($idempotent)) {
            return null;
        }

        return new self($id, $type, $typeRegistrationStatus, $receivedAt, $idempotent);
    }
}
