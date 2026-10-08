<?php

declare(strict_types=1);

namespace IngestVault;

/**
 * @template-covariant T of object
 */
final readonly class Page
{
    /**
     * @param list<T> $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore,
        public ?string $nextCursor,
        public ?string $requestId,
    ) {}
}
