<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

/**
 * Shipped UI languages. Adding a language means adding its code here and a matching frontend catalog file.
 * Request handling does not branch on a language code.
 */
final class LanguageCatalog
{
    public const FALLBACK = 'en';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return ['en', 'sv'];
    }

    public static function contains(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }
}
