<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_locales')]
class TenantLocale
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 16)]
        private string $locale,
        #[ORM\Column]
        private bool $isDefault,
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
