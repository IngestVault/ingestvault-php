<?php

declare(strict_types=1);

namespace IngestVault\Exception;

use Psr\Http\Message\ResponseInterface;

class ApiException extends IngestVaultException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $problemCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @internal
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];

        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = is_string($body['detail'] ?? null)
            ? $body['detail']
            : sprintf('The API answered with HTTP %d.', $status);

        return match (true) {
            $code === 'validation_failed' => new ValidationException($message, $status, $code, self::errors($body)),
            $code === 'rate_limited' => new RateLimitedException($message, $status, $code, self::retryAfter($response)),
            $code === 'quota_exceeded' => new QuotaExceededException($message, $status, $code, self::retryAfter($response)),
            $code === 'idempotency_key_conflict' => new IdempotencyConflictException($message, $status, $code),
            $status === 401 => new AuthenticationException($message, $status, $code),
            $status === 413 => new PayloadTooLargeException($message, $status, $code),
            $status >= 500 => new ServerException($message, $status, $code),
            default => new self($message, $status, $code),
        };
    }

    /**
     * @param array<mixed> $body
     * @return array<string, list<string>>
     */
    private static function errors(array $body): array
    {
        /** @var array<string, list<string>> */
        return is_array($body['errors'] ?? null) ? $body['errors'] : [];
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
