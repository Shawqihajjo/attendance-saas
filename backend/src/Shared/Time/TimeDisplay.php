<?php

declare(strict_types=1);

namespace App\Shared\Time;

enum TimeDisplay: string
{
    case Tenant = 'tenant';
    case Terminal = 'terminal';
    case Browser = 'browser';
    case Report = 'report';

    public static function fromRequest(?string $value): self
    {
        return match ($value) {
            null, '', self::Tenant->value => self::Tenant,
            self::Terminal->value => self::Terminal,
            self::Browser->value => self::Browser,
            self::Report->value => self::Report,
            default => throw new \InvalidArgumentException('The time display is invalid.'),
        };
    }
}
