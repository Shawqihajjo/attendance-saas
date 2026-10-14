<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_device_policies')]
class TenantDevicePolicy
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $tenantId,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->updatedAt = $updatedAt;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowedNetworks = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowedIpRanges = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowedAppVersions = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $networks
     * @param list<string> $ipRanges
     * @param list<string> $appVersions
     */
    public function replace(array $networks, array $ipRanges, array $appVersions, \DateTimeImmutable $now): void
    {
        $this->allowedNetworks = $this->encode($networks);
        $this->allowedIpRanges = $this->encode($ipRanges);
        $this->allowedAppVersions = $this->encode($appVersions);
        $this->updatedAt = $now;
    }

    /**
     * @return array{allowedNetworks: list<string>, allowedIpRanges: list<string>, allowedAppVersions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'allowedNetworks' => $this->decode($this->allowedNetworks),
            'allowedIpRanges' => $this->decode($this->allowedIpRanges),
            'allowedAppVersions' => $this->decode($this->allowedAppVersions),
        ];
    }

    /**
     * @param list<string> $values
     */
    private function encode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function decode(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $items = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
