<?php

declare(strict_types=1);

namespace IngestVault;

use IngestVault\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;

final readonly class Event
{
    public function __construct(
        public string $id,
        public string $type,
        public string $typeRegistrationStatus,
        public \DateTimeImmutable $receivedAt,
        public bool $idempotent,
    ) {}

    /**
     * @internal
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];

        $id = $body['id'] ?? null;
        $type = $body['type'] ?? null;
        $typeRegistrationStatus = $body['type_registration_status'] ?? null;
        $receivedAt = is_string($body['received_at'] ?? null)
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $body['received_at'])
            : false;
        $idempotent = $body['idempotent'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($typeRegistrationStatus) || $receivedAt === false || ! is_bool($idempotent)) {
            throw new ApiException(
                sprintf('The API answered with HTTP %d but the body is not an event.', $response->getStatusCode()),
                $response->getStatusCode(),
            );
        }

        return new self($id, $type, $typeRegistrationStatus, $receivedAt, $idempotent);
    }
}
