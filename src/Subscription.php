<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Subscription
{
    /**
     * @param list<string> $filter
     */
    public function __construct(
        public string $id,
        public array $filter,
        public ?string $description,
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
        $filter = $item['filter'] ?? null;
        $description = $item['description'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if (! is_string($id) || ! is_array($filter) || ! array_is_list($filter) || ! array_key_exists('description', $item)
            || ! (is_string($description) || $description === null) || $createdAt === null || $updatedAt === null) {
            return null;
        }

        $names = [];
        foreach ($filter as $name) {
            if (! is_string($name)) {
                return null;
            }
            $names[] = $name;
        }

        return new self($id, $names, $description, $createdAt, $updatedAt);
    }
}
