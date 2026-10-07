<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class SigningSecret
{
    private \SensitiveParameterValue $secret;

    public function __construct(
        public string $id,
        #[\SensitiveParameter]
        string $secret,
        public ?\DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        $this->secret = new \SensitiveParameterValue($secret);
    }

    public function secret(): string
    {
        /** @var string */
        return $this->secret->getValue();
    }

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(#[\SensitiveParameter] array $item): ?self
    {
        $id = $item['id'] ?? null;
        $secret = $item['secret'] ?? null;
        $expiresAt = $item['expires_at'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $updatedAt = Timestamp::parse($item['updated_at'] ?? null);

        if ($expiresAt !== null) {
            $expiresAt = Timestamp::parse($expiresAt);
            if ($expiresAt === null) {
                return null;
            }
        }

        if (! is_string($id) || ! is_string($secret) || ! array_key_exists('expires_at', $item) || $createdAt === null || $updatedAt === null) {
            return null;
        }

        return new self($id, $secret, $expiresAt, $createdAt, $updatedAt);
    }
}
