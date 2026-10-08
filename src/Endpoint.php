<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Endpoint
{
    public function __construct(
        public string $id,
        public string $url,
        public ?string $description,
        public bool $enabled,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
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
        $url = $item['url'] ?? null;
        $description = $item['description'] ?? null;
        $enabled = $item['enabled'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if (! is_string($id) || ! is_string($url) || ! array_key_exists('description', $item) || ! (is_string($description) || $description === null)
            || ! is_bool($enabled) || $createdAt === null || $updatedAt === null) {
            return null;
        }

        return new self($id, $url, $description, $enabled, $createdAt, $updatedAt, $requestId);
    }
}
