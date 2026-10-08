<?php

declare(strict_types=1);

namespace IngestVault\Resource;

use IngestVault\SigningSecret;

final class SigningSecrets extends Group
{
    /**
     * @return list<SigningSecret>
     */
    public function list(string $endpointId): array
    {
        $response = $this->transport->request('GET', self::path($endpointId));

        return self::items(self::body($response)['data'] ?? null, SigningSecret::fromArray(...), self::requestId($response))
            ?? throw self::unreadable($response, "the endpoint's signing secrets");
    }

    public function rotate(string $endpointId): SigningSecret
    {
        return self::item(
            $this->transport->request('POST', self::path($endpointId) . '/rotate', retry: false),
            SigningSecret::fromArray(...),
            'a signing secret',
        );
    }

    private static function path(string $endpointId): string
    {
        return '/endpoints/' . rawurlencode($endpointId) . '/signing-secrets';
    }
}
