<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class EventType
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $archived,
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
        $name = $item['name'] ?? null;
        $description = $item['description'] ?? null;
        $archived = $item['archived'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if (! is_string($id) || ! is_string($name) || ! array_key_exists('description', $item) || ! (is_string($description) || $description === null)
            || ! is_bool($archived) || $createdAt === null || $updatedAt === null) {
            return null;
        }

        return new self($id, $name, $description, $archived, $createdAt, $updatedAt);
    }
}
