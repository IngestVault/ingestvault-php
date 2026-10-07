<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class DeliveryAttempt
{
    public function __construct(
        public string $id,
        public string $outcome,
        public ?int $httpStatus,
        public ?string $errorClassification,
        public int $durationMs,
        public ?string $responseBody,
        public \DateTimeImmutable $attemptedAt,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $id = $item['id'] ?? null;
        $outcome = $item['outcome'] ?? null;
        $httpStatus = $item['http_status'] ?? null;
        $errorClassification = $item['error_classification'] ?? null;
        $durationMs = $item['duration_ms'] ?? null;
        $responseBody = $item['response_body'] ?? null;
        $attemptedAt = Timestamp::parse($item['attempted_at'] ?? null);

        if (! is_string($id) || ! is_string($outcome)
            || ! array_key_exists('http_status', $item) || ! (is_int($httpStatus) || $httpStatus === null)
            || ! array_key_exists('error_classification', $item) || ! (is_string($errorClassification) || $errorClassification === null)
            || ! is_int($durationMs)
            || ! array_key_exists('response_body', $item) || ! (is_string($responseBody) || $responseBody === null)
            || $attemptedAt === null) {
            return null;
        }

        return new self($id, $outcome, $httpStatus, $errorClassification, $durationMs, $responseBody, $attemptedAt);
    }
}
