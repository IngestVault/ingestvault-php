<?php

declare(strict_types=1);

namespace IngestVault\Exception;

final class ValidationException extends ApiException
{
    /**
     * @param array<string, list<string>> $errors Field path to messages, as the API sent them.
     */
    public function __construct(
        string $message,
        int $status,
        ?string $problemCode,
        public readonly array $errors,
    ) {
        parent::__construct($message, $status, $problemCode);
    }
}
