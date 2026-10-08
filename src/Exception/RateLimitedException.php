<?php

declare(strict_types=1);

namespace IngestVault\Exception;

final class RateLimitedException extends ApiException
{
    /**
     * @param ?int $retryAfter Seconds to wait before trying again, from the Retry-After header.
     */
    public function __construct(
        string $message,
        int $status,
        ?string $problemCode,
        ?string $requestId,
        public readonly ?int $retryAfter,
    ) {
        parent::__construct($message, $status, $problemCode, $requestId);
    }
}
