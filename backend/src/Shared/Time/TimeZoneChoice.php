<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * Picks an IANA zone for presentation. The choice never becomes the stored attendance instant.
 */
final readonly class TimeZoneChoice
{
    public function __construct(
        public TimeDisplay $display,
        public string $tenantTimeZone,
        public ?string $terminalTimeZone = null,
        public ?string $browserTimeZone = null,
        public ?string $reportTimeZone = null,
    ) {
    }

    public function resolved(): string
    {
        $selected = match ($this->display) {
            TimeDisplay::Tenant => $this->tenantTimeZone,
            TimeDisplay::Terminal => $this->terminalTimeZone ?? throw new \InvalidArgumentException('A terminal timezone is required.'),
            TimeDisplay::Browser => $this->browserTimeZone ?? throw new \InvalidArgumentException('A browser timezone is required.'),
            TimeDisplay::Report => $this->reportTimeZone ?? $this->tenantTimeZone,
        };

        return UtcTime::requireIana($selected);
    }
}
