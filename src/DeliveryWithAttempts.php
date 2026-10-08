<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class DeliveryWithAttempts
{
    /**
     * @param string $payloadState 'available', 'expired' or 'none' (the event was sent without a payload).
     * @param list<DeliveryAttempt> $attempts In the order they were made.
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $attemptCount,
        public ?\DateTimeImmutable $nextAttemptAt,
        public string $eventId,
        public string $eventType,
        public string $endpointId,
        public string $endpointUrl,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public string $payloadState,
        public array $attempts,
        public ?string $requestId,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item, ?string $requestId): ?self
    {
        $delivery = Delivery::fromArray($item, $requestId);
        $payloadState = $item['payload_state'] ?? null;
        $items = $item['attempts'] ?? null;

        if ($delivery === null || ! is_string($payloadState) || ! is_array($items) || ! array_is_list($items)) {
            return null;
        }

        $attempts = [];
        foreach ($items as $attempt) {
            $attempt = is_array($attempt) ? DeliveryAttempt::fromArray($attempt) : null;
            if ($attempt === null) {
                return null;
            }
            $attempts[] = $attempt;
        }

        return new self(
            $delivery->id,
            $delivery->status,
            $delivery->attemptCount,
            $delivery->nextAttemptAt,
            $delivery->eventId,
            $delivery->eventType,
            $delivery->endpointId,
            $delivery->endpointUrl,
            $delivery->createdAt,
            $delivery->updatedAt,
            $payloadState,
            $attempts,
            $requestId,
        );
    }
}
