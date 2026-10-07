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
    public function __construct(public string $type, mixed $payload = Payload::None, ?string $idempotencyKey = null)
    {
        $this->idempotencyKey = $idempotencyKey ?? IdempotencyKey::generate();
        $this->body = match (true) {
            $payload === Payload::None => Transport::encode(['type' => $type]),
            $payload instanceof RawJson => substr(Transport::encode(['type' => $type]), 0, -1) . ',"payload":' . $payload->json . '}',
            default => Transport::encode(['type' => $type, 'payload' => $payload]),
        };
    }
}
