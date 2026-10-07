<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Initiator
{
    public function __construct(
        public string $type,
        public ?string $id,
        public ?string $name,
        public ?string $hint,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $type = $item['type'] ?? null;
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $hint = $item['hint'] ?? null;

        if (! is_string($type) || ! (is_string($id) || $id === null) || ! (is_string($name) || $name === null) || ! (is_string($hint) || $hint === null)) {
            return null;
        }

        return new self($type, $id, $name, $hint);
    }
}
