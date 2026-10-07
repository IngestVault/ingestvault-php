<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class PreparedEvent
{
    public string $idempotencyKey;

    public string $body;

    /**
     * @throws \JsonException When the payload cannot be encoded as JSON.
     */
    public function __construct(public string $type, mixed $payload = null, ?string $idempotencyKey = null)
    {
        $this->idempotencyKey = $idempotencyKey ?? self::generateKey();
        $this->body = Transport::encode(['type' => $type, 'payload' => $payload]);
    }

    private static function generateKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
