<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Delivery
{
    public function __construct(
        public string $id,
        public string $status,
        public int $attemptCount,
        public ?\DateTimeImmutable $nextAttemptAt,
        public string $eventId,
        public string $eventType,
        public string $endpointId,
        public string $endpointUrl,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $id = $item['id'] ?? null;
        $status = $item['status'] ?? null;
        $attemptCount = $item['attempt_count'] ?? null;
        $nextAttemptAt = $item['next_attempt_at'] ?? null;
        $event = $item['event'] ?? null;
        $endpoint = $item['endpoint'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if ($nextAttemptAt !== null) {
            $nextAttemptAt = Timestamp::parse($nextAttemptAt);
            if ($nextAttemptAt === null) {
                return null;
            }
        }

        if (! is_string($id) || ! is_string($status) || ! is_int($attemptCount) || ! array_key_exists('next_attempt_at', $item)
            || ! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)
            || ! is_array($endpoint) || ! is_string($endpoint['id'] ?? null) || ! is_string($endpoint['url'] ?? null)
            || $createdAt === null || $updatedAt === null) {
            return null;
        }

        return new self($id, $status, $attemptCount, $nextAttemptAt, $event['id'], $event['type'], $endpoint['id'], $endpoint['url'], $createdAt, $updatedAt);
    }
}
