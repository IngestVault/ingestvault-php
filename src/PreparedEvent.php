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
        $this->idempotencyKey = $idempotencyKey ?? IdempotencyKey::generate();
        $this->body = Transport::encode(['type' => $type, 'payload' => $payload]);
    }
}
