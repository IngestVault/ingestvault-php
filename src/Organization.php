<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $notificationEmail,
        public \DateTimeImmutable $createdAt,
        public bool $payloadsVisible,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $notificationEmail = $item['notification_email'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $payloadsVisible = $item['payloads_visible'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($notificationEmail) || $createdAt === null || ! is_bool($payloadsVisible)) {
            return null;
        }

        return new self($id, $name, $notificationEmail, $createdAt, $payloadsVisible);
    }
}
