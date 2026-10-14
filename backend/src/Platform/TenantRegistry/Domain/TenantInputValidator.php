<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

use App\Shared\Time\UtcTime;

final class TenantInputValidator
{
    public function slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
            throw new InvalidTenantInputException('Slug must be a lowercase identifier.');
        }

        return $slug;
    }

    public function legalName(string $legalName): string
    {
        $legalName = trim($legalName);
        if ($legalName === '' || strlen($legalName) > 200 || preg_match('/[\\x00-\\x1F\\x7F]/', $legalName)) {
            throw new InvalidTenantInputException('Legal name is invalid.');
        }

        return $legalName;
    }

    public function hostname(string $hostname, string $platformHost): string
    {
        $hostname = strtolower(trim($hostname));
        if (str_contains($hostname, ':')) {
            $hostname = explode(':', $hostname, 2)[0];
        }
        if ($hostname === '' || $hostname === strtolower($platformHost)) {
            throw new InvalidTenantInputException('Hostname is reserved or empty.');
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidTenantInputException('Tenant host must be a domain name.');
        }
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname)) {
            throw new InvalidTenantInputException('Hostname is invalid.');
        }

        return $hostname;
    }

    public function timezone(string $timezone): string
    {
        try {
            return UtcTime::requireIana($timezone);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidTenantInputException('Timezone must be an IANA name.', 0, $exception);
        }
    }

    public function locale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (!LanguageCatalog::contains($locale)) {
            throw new InvalidTenantInputException('Locale is not a supported language.');
        }

        return $locale;
    }

    public function color(string $color): string
    {
        $color = strtoupper(trim($color));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new InvalidTenantInputException('A color must be a six-digit hex value.');
        }

        return $color;
    }

    public function assetUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (strlen($url) > 500) {
            throw new InvalidTenantInputException('The asset address is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidTenantInputException('The asset address must be an https URL.');
        }

        return $url;
    }

    public function dateFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, PresentationCatalog::dateFormats(), true)) {
            throw new InvalidTenantInputException('Date format is not supported.');
        }

        return $format;
    }

    public function timeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, PresentationCatalog::timeFormats(), true)) {
            throw new InvalidTenantInputException('Time format is not supported.');
        }

        return $format;
    }

    public function weekStartsOn(int $day): int
    {
        if ($day !== 0 && $day !== 1) {
            throw new InvalidTenantInputException('The week starts on Sunday or Monday.');
        }

        return $day;
    }

    public function databaseName(string $databaseName): string
    {
        if (!preg_match('/^tenant_[a-f0-9]{32}$/', $databaseName)) {
            throw new InvalidTenantInputException('Database name is not a platform-generated tenant database.');
        }

        return $databaseName;
    }

    public function databaseUser(string $username): string
    {
        if (!preg_match('/^tu_[a-f0-9]{16}$/', $username)) {
            throw new InvalidTenantInputException('Database user is not a platform-generated tenant user.');
        }

        return $username;
    }

    public function databaseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) || str_contains($host, '..')) {
            throw new InvalidTenantInputException('Database host is invalid.');
        }

        return $host;
    }

    public function actorId(string $actorId): string
    {
        $actorId = trim($actorId);
        if (!preg_match('/^[A-Za-z0-9-]{1,36}$/', $actorId)) {
            throw new InvalidTenantInputException('Actor id is invalid.');
        }

        return $actorId;
    }
}
