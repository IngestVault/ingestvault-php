<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Source
{
    private \SensitiveParameterValue $url;

    private \SensitiveParameterValue $graceUrl;

    /**
     * @param list<string> $endpointIds In the order they are connected.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $enabled,
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        ?string $graceUrl,
        public ?\DateTimeImmutable $graceUrlExpiresAt,
        public array $endpointIds,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $requestId,
    ) {
        $this->url = new \SensitiveParameterValue($url);
        $this->graceUrl = new \SensitiveParameterValue($graceUrl);
    }

    public function url(): string
    {
        /** @var string */
        return $this->url->getValue();
    }

    public function graceUrl(): ?string
    {
        /** @var ?string */
        return $this->graceUrl->getValue();
    }

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(#[\SensitiveParameter] array $item, ?string $requestId): ?self
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $description = $item['description'] ?? null;
        $enabled = $item['enabled'] ?? null;
        $url = $item['url'] ?? null;
        $graceUrl = $item['grace_url'] ?? null;
        $graceUrlExpiresAt = $item['grace_url_expires_at'] ?? null;
        $endpointIds = $item['endpoint_ids'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if ($graceUrlExpiresAt !== null) {
            $graceUrlExpiresAt = Timestamp::parse($graceUrlExpiresAt);
            if ($graceUrlExpiresAt === null) {
                return null;
            }
        }

        if (! is_string($id) || ! is_string($name) || ! array_key_exists('description', $item) || ! (is_string($description) || $description === null)
            || ! is_bool($enabled) || ! is_string($url) || ! array_key_exists('grace_url', $item) || ! (is_string($graceUrl) || $graceUrl === null)
            || ! array_key_exists('grace_url_expires_at', $item) || ! is_array($endpointIds) || ! array_is_list($endpointIds)
            || $createdAt === null || $updatedAt === null) {
            return null;
        }

        $ids = [];
        foreach ($endpointIds as $endpointId) {
            if (! is_string($endpointId)) {
                return null;
            }
            $ids[] = $endpointId;
        }

        return new self($id, $name, $description, $enabled, $url, $graceUrl, $graceUrlExpiresAt, $ids, $createdAt, $updatedAt, $requestId);
    }
}
