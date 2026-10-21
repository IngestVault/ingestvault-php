<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class SourceReference
{
    public function __construct(
        public string $id,
        public string $name,
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

        if (! is_string($id) || ! is_string($name)) {
            return null;
        }

        return new self($id, $name);
    }
}
