<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant_settings')]
class TenantSetting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $tenantId,
        #[ORM\Column(length: 64)]
        private string $timezone,
        #[ORM\Column]
        private bool $photoRequired = true,
        #[ORM\Column(length: 16)]
        private string $dateFormat = 'iso',
        #[ORM\Column(length: 16)]
        private string $timeFormat = '24h',
        #[ORM\Column(type: 'smallint')]
        private int $weekStartsOn = 1,
        #[ORM\Column(nullable: true)]
        private ?int $photoRetentionDays = null,
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function photoRequired(): bool
    {
        return $this->photoRequired;
    }

    public function changeTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function changePhotoRequired(bool $photoRequired): void
    {
        $this->photoRequired = $photoRequired;
    }

    public function photoRetentionDays(): ?int
    {
        return $this->photoRetentionDays;
    }

    public function changePhotoRetention(?int $days): void
    {
        $this->photoRetentionDays = $days;
    }

    public function dateFormat(): string
    {
        return $this->dateFormat;
    }

    public function timeFormat(): string
    {
        return $this->timeFormat;
    }

    public function weekStartsOn(): int
    {
        return $this->weekStartsOn;
    }

    public function changeRegional(string $dateFormat, string $timeFormat, int $weekStartsOn): void
    {
        $this->dateFormat = $dateFormat;
        $this->timeFormat = $timeFormat;
        $this->weekStartsOn = $weekStartsOn;
    }
}
