<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Uid\Uuid;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenantId)
    {
        if (!Uuid::isValid($tenantId)) {
            throw new \InvalidArgumentException('A tenant stamp requires a UUID tenant id.');
        }
    }
}
