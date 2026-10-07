<?php

declare(strict_types=1);

namespace IngestVault\Resource;

final class Organization extends Group
{
    public function current(): \IngestVault\Organization
    {
        return self::item(
            $this->transport->request('GET', '/organizations/current'),
            \IngestVault\Organization::fromArray(...),
            'an organization',
        );
    }
}
