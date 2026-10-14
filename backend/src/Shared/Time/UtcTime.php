<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * Authoritative instants are UTC. Storage and ISO responses are produced from the instant,
 * so the server's default timezone cannot change them.
 */
final class UtcTime
{
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }

    public static function instant(\DateTimeInterface $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone(self::zone());
    }

    public static function persist(\DateTimeInterface $value): string
    {
        return self::instant($value)->format('Y-m-d H:i:s');
    }

    public static function parseStored(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, self::zone());
        if (!$parsed instanceof \DateTimeImmutable || self::hasErrors()) {
            throw new \InvalidArgumentException('The timestamp is not a UTC instant.');
        }

        return $parsed;
    }

    public static function toIso(\DateTimeInterface $value): string
    {
        return self::instant($value)->format('Y-m-d\TH:i:s\Z');
    }

    public static function present(\DateTimeInterface $value, string $iana): string
    {
        $zone = new \DateTimeZone(self::requireIana($iana));

        return self::instant($value)->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    public static function requireIana(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '' || preg_match('/^[+-]/', $timezone) || preg_match('/^UTC[+-]/i', $timezone)) {
            throw new \InvalidArgumentException('Timezone must be an IANA name.');
        }
        try {
            $name = (new \DateTimeZone($timezone))->getName();
        } catch (\Exception) {
            throw new \InvalidArgumentException('Timezone must be an IANA name.');
        }
        if ($name === 'UTC') {
            return 'UTC';
        }
        if (!str_contains($name, '/') || str_starts_with($name, 'Etc/GMT')) {
            throw new \InvalidArgumentException('Timezone must be an IANA name.');
        }

        return $name;
    }

    public static function durationSeconds(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return self::instant($end)->getTimestamp() - self::instant($start)->getTimestamp();
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
     */
    public static function localDay(string $date, string $iana): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('The local date must be YYYY-MM-DD.');
        }
        $zone = new \DateTimeZone(self::requireIana($iana));
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date.' 00:00:00', $zone);
        if (!$start instanceof \DateTimeImmutable || self::hasErrors()) {
            throw new \InvalidArgumentException('The local date is invalid.');
        }
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start->modify('+1 day')->format('Y-m-d').' 00:00:00', $zone);
        if (!$end instanceof \DateTimeImmutable || self::hasErrors()) {
            throw new \InvalidArgumentException('The local date is invalid.');
        }

        return [
            'start' => self::instant($start),
            'end' => self::instant($end),
        ];
    }

    /**
     * @phpstan-impure
     */
    private static function hasErrors(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }
}
