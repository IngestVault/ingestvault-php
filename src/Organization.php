<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $notificationEmail,
        public \DateTimeImmutable $createdAt,
        public bool $payloadsVisible,
        public string $state,
        public ?string $plan,
        public ?string $unpaidReason,
        public ?string $requestId,
    ) {}

    /**
     * @internal
     *
     * @param array<mixed> $item
     */
    public static function fromArray(array $item, ?string $requestId): ?self
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $notificationEmail = $item['notification_email'] ?? null;
        $createdAt = Timestamp::parse($item['created_at'] ?? null);
        $payloadsVisible = $item['payloads_visible'] ?? null;
        $state = $item['state'] ?? null;
        $plan = $item['plan'] ?? null;
        $unpaidReason = $item['unpaid_reason'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($notificationEmail) || $createdAt === null || ! is_bool($payloadsVisible)
            || ! is_string($state) || ! (is_string($plan) || $plan === null) || ! (is_string($unpaidReason) || $unpaidReason === null)) {
            return null;
        }

        return new self($id, $name, $notificationEmail, $createdAt, $payloadsVisible, $state, $plan, $unpaidReason, $requestId);
    }
}
